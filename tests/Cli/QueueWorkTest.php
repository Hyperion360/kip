<?php // tests/Cli/QueueWorkTest.php
namespace Kip\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * `bin/kip queue:work` end to end against the shared throwaway-app harness:
 * the skeleton's real bin/kip + config.php + migrations, job classes written
 * under app/src/Jobs/ so the harness autoloader resolves App\ exactly like a
 * layered app would. Enqueue goes through Kip\Jobs in a php -r child, the
 * same path a controller's enqueue takes.
 */
final class QueueWorkTest extends TestCase
{
    use CliAppHarness;

    protected function setUp(): void
    {
        $this->buildCliApp(withMigrations: true);
    }

    protected function tearDown(): void
    {
        $this->tearDownCliApp();
    }

    private function migrate(): void
    {
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
    }

    private function writeJobClasses(): void
    {
        mkdir($this->cliApp . '/app/src/Jobs', 0777, true);
        // Records the exact payload it received, so the test asserts the
        // JSON round trip through the queue, not just a side effect.
        file_put_contents($this->cliApp . '/app/src/Jobs/RecordJob.php', <<<'PHP'
        <?php
        namespace App\Jobs;
        final class RecordJob
        {
            public function handle(array $payload): void
            {
                file_put_contents(dirname(__DIR__, 3) . '/job-record.json', json_encode($payload, JSON_THROW_ON_ERROR));
            }
        }
        PHP);
        file_put_contents($this->cliApp . '/app/src/Jobs/BoomJob.php', <<<'PHP'
        <?php
        namespace App\Jobs;
        final class BoomJob
        {
            public function handle(array $payload): void
            {
                throw new \RuntimeException('boom on purpose');
            }
        }
        PHP);
    }

    /**
     * Enqueue through the framework, in a child PHP process using the
     * harness autoloader, the way a controller would at runtime.
     *
     * @param array<string, mixed> $payload
     */
    private function enqueue(string $jobClass, array $payload): int
    {
        $script = sprintf(
            'require %s; $db = new Kip\Database(%s); echo (new Kip\Jobs($db))->enqueue(%s, %s);',
            var_export($this->cliApp . '/vendor/autoload.php', true),
            var_export('sqlite:' . $this->cliApp . '/app/data.sqlite', true),
            var_export($jobClass, true),
            var_export($payload, true)
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        return (int) implode('', $lines);
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->cliApp . '/app/data.sqlite');
    }

    public function test_once_on_an_empty_queue_exits_zero(): void
    {
        $this->migrate();
        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('No pending jobs.', $out);
    }

    public function test_an_unknown_flag_is_a_usage_error_never_an_infinite_worker(): void
    {
        $this->migrate();
        [$out, $code] = $this->cli(['queue:work', '--onces']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Usage: kip queue:work [--once]', $out);
    }

    public function test_once_executes_one_job_end_to_end_and_marks_it_done(): void
    {
        $this->migrate();
        $this->writeJobClasses();
        $payload = ['to' => 'someone@example.com', 'lines' => ['first', 'second'], 'unicode' => 'grüße'];
        $id = $this->enqueue('App\\Jobs\\RecordJob', $payload);
        $this->assertGreaterThan(0, $id);

        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString(sprintf('Job #%d App\Jobs\RecordJob: done', $id), $out);

        $row = $this->pdo()->query('SELECT status, payload, error FROM jobs WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('done', $row['status']);
        $this->assertNull($row['payload']); // done rows keep no payload
        $this->assertNull($row['error']);
        // The exact payload reached the job, unicode intact.
        $this->assertSame($payload, json_decode((string) file_get_contents($this->cliApp . '/job-record.json'), true));
    }

    public function test_once_processes_exactly_one_job_and_respects_fifo(): void
    {
        $this->migrate();
        $this->writeJobClasses();
        $first = $this->enqueue('App\\Jobs\\RecordJob', ['batch' => 'first']);
        $second = $this->enqueue('App\\Jobs\\RecordJob', ['batch' => 'second']);

        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Job #{$first} App\Jobs\RecordJob: done", $out);
        $this->assertSame(['batch' => 'first'], json_decode((string) file_get_contents($this->cliApp . '/job-record.json'), true));

        $statuses = $this->pdo()->query('SELECT id, status FROM jobs')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('done', $statuses[$first]);
        $this->assertSame('pending', $statuses[$second]); // exactly one, oldest first

        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Job #{$second} App\Jobs\RecordJob: done", $out);
    }

    public function test_a_throwing_job_exits_non_zero_and_keeps_the_error_in_the_row(): void
    {
        $this->migrate();
        $this->writeJobClasses();
        $id = $this->enqueue('App\\Jobs\\BoomJob', ['why' => 'cli test']);

        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('kip: ', $out);
        $this->assertStringContainsString("job #{$id}", $out);
        $this->assertStringContainsString('boom on purpose', $out);

        $row = $this->pdo()->query("SELECT status, error, payload FROM jobs WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('RuntimeException', $row['error']);
        $this->assertStringContainsString('boom on purpose', $row['error']);
        $this->assertSame(['why' => 'cli test'], json_decode((string) $row['payload'], true));
    }

    public function test_a_failed_job_does_not_poison_the_queue(): void
    {
        $this->migrate();
        $this->writeJobClasses();
        $this->enqueue('App\\Jobs\\BoomJob', []);
        $this->enqueue('App\\Jobs\\RecordJob', ['after' => 'failure']);

        [, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(1, $code); // the failure is loud for cron

        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(0, $code, $out); // the next job still runs
        $this->assertStringContainsString('done', $out);
    }

    /** The failed-job line belongs on STDERR, so deploy tooling can trust stdout (guide ch. 8 contract). */
    public function test_failure_output_lands_on_stderr_not_stdout(): void
    {
        $this->migrate();
        $this->writeJobClasses();
        $this->enqueue('App\\Jobs\\BoomJob', []);
        $errFile = (string) tempnam(sys_get_temp_dir(), 'kip-q-err-');
        $outFile = (string) tempnam(sys_get_temp_dir(), 'kip-q-out-');
        try {
            $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ' . escapeshellarg(PHP_BINARY) . ' ./bin/kip queue:work --once'
                . ' > ' . escapeshellarg($outFile) . ' 2> ' . escapeshellarg($errFile);
            exec($cmd, $lines, $code);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('kip: job #', (string) file_get_contents($errFile));
            $this->assertSame('', (string) file_get_contents($outFile), 'the failure line must not leak into stdout');
        } finally {
            @unlink($errFile);
            @unlink($outFile);
        }
    }

    public function test_an_unmigrated_app_fails_under_the_shared_contract(): void
    {
        // No migrate run: the jobs table does not exist, so the worker must
        // hit the kip: / exit 1 failure contract, not a trace.
        [$out, $code] = $this->cli(['queue:work', '--once']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringContainsString('jobs', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
    }
}
