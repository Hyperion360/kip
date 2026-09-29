<?php // tests/Cron/SchedulerTest.php
namespace Kip\Tests\Cron;

use Kip\Cron\Scheduler;
use PHPUnit\Framework\TestCase;

/**
 * The schedule runner: loading app/schedule.php, due selection, per-entry
 * failure isolation, the flock overlap lock, and command-job spawning.
 * These run in-process against a temp app dir and assert exit codes and
 * side effects; output-shape assertions live in the subprocess CLI tests
 * (fwrite to stderr bypasses output buffering, so echo is the only
 * capturable channel here).
 */
final class SchedulerTest extends TestCase
{
    private string $dir;
    private string $marker;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-sched-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/app', 0777, true);
        $this->marker = $this->dir . '/marker';
    }

    protected function tearDown(): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            $rm($this->dir);
        } finally { restore_error_handler(); }
    }

    /**
     * A stub kip script with arms in the same 4-space shape the real
     * bin/kip uses, so the command validation reads a real command list.
     */
    private function writeKipStub(): string
    {
        $marker = var_export($this->marker, true);
        $script = <<<PHP
        #!/usr/bin/env php
        <?php // stub kip script: 'ok' touches the marker, 'fail' exits 3
        match (\$argv[1] ?? '') {
            'ok' => (function () { touch({$marker}); exit(0); })(),
            'fail' => exit(3),
            default => exit(0),
        };

        PHP;
        $path = $this->dir . '/kip';
        file_put_contents($path, $script);
        return $path;
    }

    /**
     * @param list<string> $entryPhp each a full `'expr' => job` line with
     *   paths already baked; writeSchedule adds the separating commas
     */
    private function writeSchedule(array $entryPhp): void
    {
        file_put_contents($this->dir . '/app/schedule.php', "<?php return [\n" . implode(",\n", $entryPhp) . "\n];\n");
    }

    private function touchEntry(string $markerSuffix = ''): string
    {
        return 'function () { touch(' . var_export($this->marker . $markerSuffix, true) . '); }';
    }

    private function ran(string $suffix = ''): bool
    {
        return is_file($this->marker . $suffix);
    }

    public function test_listing_without_a_schedule_file_is_a_clean_zero(): void
    {
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(0, $scheduler->listing(new \DateTimeImmutable('2026-09-29 14:05:00')));
    }

    public function test_run_due_without_a_schedule_file_is_a_silent_zero(): void
    {
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(0, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
    }

    public function test_empty_schedule_is_a_zero_in_both_modes(): void
    {
        $this->writeSchedule([]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $now = new \DateTimeImmutable('2026-09-29 14:05:00');
        $this->assertSame(0, $scheduler->listing($now));
        $this->assertSame(0, $scheduler->runDue($now));
    }

    public function test_run_due_runs_matching_entries_and_skips_the_others(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => " . $this->touchEntry('-due'),
            "'" . '0 0 30 2 *' . "' => " . $this->touchEntry('-never'), // Feb 30 can never match
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(0, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:37')));
        $this->assertTrue($this->ran('-due'));
        $this->assertFalse($this->ran('-never'));
    }

    public function test_a_throwing_job_does_not_stop_the_others_and_fails_the_run(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => [" . $this->touchEntry('-a'),
            "  function () { throw new RuntimeException('job boom'); }",
            "  " . $this->touchEntry('-b') . "]",
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(1, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
        $this->assertTrue($this->ran('-a'));
        $this->assertTrue($this->ran('-b'), 'the sibling after the throwing job still ran');
    }

    public function test_a_failing_command_job_fails_the_run_without_stopping_siblings(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => [" . $this->touchEntry('-sibling') . ", 'fail']",
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(1, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
        $this->assertTrue($this->ran('-sibling'));
    }

    public function test_a_successful_command_job_runs_the_kip_script_with_its_arguments(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => 'ok --with-args'",
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(0, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
        $this->assertTrue($this->ran());
    }

    public function test_a_launch_failure_fails_the_entry_without_stopping_siblings(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => [" . $this->touchEntry('-sibling') . ", 'ok']",
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub(), '/no/such/php-binary');
        $this->assertSame(1, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
        $this->assertTrue($this->ran('-sibling'));
    }

    public function test_unknown_command_tokens_are_rejected_without_stopping_siblings(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => ['backpu', " . $this->touchEntry('-sibling') . "]",
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $now = new \DateTimeImmutable('2026-09-29 14:05:00');
        $this->assertSame(1, $scheduler->runDue($now), 'the typo fails the run');
        $this->assertTrue($this->ran('-sibling'), 'the sibling still ran');
        $this->assertFalse($this->ran(), 'the typoed command never spawned');
    }

    public function test_invalid_job_values_are_rejected_per_entry(): void
    {
        $this->writeSchedule([
            "'" . '* * * * *' . "' => 42",
            "'" . '5 5 * * *' . "' => ['cmd' => 'backup']", // assoc array: not a list, not a callable
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $now = new \DateTimeImmutable('2026-09-29 14:05:00');
        $this->assertSame(1, $scheduler->runDue($now));
        $this->assertSame(1, $scheduler->listing($now));
    }

    public function test_invalid_expressions_fail_the_entry_not_the_run(): void
    {
        $this->writeSchedule([
            "'" . '60 * * * *' . "' => 'ok'", // minute out of range
            "'" . '* * * * *' . "' => " . $this->touchEntry('-sibling'),
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $now = new \DateTimeImmutable('2026-09-29 14:05:00');
        $this->assertSame(1, $scheduler->runDue($now));
        $this->assertTrue($this->ran('-sibling'), 'the valid entry still ran');
        $this->assertSame(1, $scheduler->listing($now));
    }

    public function test_array_callables_and_invokable_objects_run_as_single_jobs(): void
    {
        $class = var_export(SchedulerStaticJob::class, true);
        $invokableMarker = var_export($this->marker . '-invokable', true);
        $this->writeSchedule([
            // Two distinct keys: PHP collapses duplicate array keys silently,
            // the trap the list form exists to avoid.
            "'" . '* * * * *' . "' => [{$class}, 'run']",
            "'" . '5 14 * * *' . "' => ['ok', new class({$invokableMarker}) { public function __construct(private string \$m) {} public function __invoke(): void { touch(\$this->m); } }]",
        ]);
        SchedulerStaticJob::$marker = $this->marker . '-static';
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(0, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
        $this->assertTrue($this->ran('-static'), 'the [class, method] array ran as a callable, not as two command jobs');
        $this->assertTrue($this->ran('-invokable'), 'the invokable object ran');
    }

    public function test_broken_schedule_files_fail_loudly(): void
    {
        $kip = $this->writeKipStub();
        $now = new \DateTimeImmutable('2026-09-29 14:05:00');
        foreach (
            [
                ['php' => '<?php return 42;', 'message' => 'must return an array'],
                ['php' => "<?php throw new RuntimeException('schedule boom');", 'message' => 'failed to load'],
            ] as $case
        ) {
            file_put_contents($this->dir . '/app/schedule.php', $case['php']);
            $scheduler = new Scheduler($this->dir . '/app', $kip);
            try {
                $scheduler->runDue($now);
                self::fail('a broken schedule file must not pass silently');
            } catch (\RuntimeException $e) {
                // the bin/kip failure contract turns this into kip: + exit 1
                self::assertStringContainsString($case['message'], $e->getMessage());
            }
        }
    }

    public function test_an_unreadable_kip_script_fails_loudly(): void
    {
        $this->writeSchedule(["'" . '* * * * *' . "' => 'ok'"]);
        $scheduler = new Scheduler($this->dir . '/app', $this->dir . '/no-such-script');
        $this->expectException(\RuntimeException::class);
        $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00'));
    }

    public function test_a_kip_script_without_parseable_arms_fails_loudly(): void
    {
        file_put_contents($this->dir . '/kip', "<?php echo 'no match arms here';\n");
        $this->writeSchedule(["'" . '* * * * *' . "' => 'ok'"]);
        $scheduler = new Scheduler($this->dir . '/app', $this->dir . '/kip');
        $this->expectException(\RuntimeException::class);
        $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00'));
    }

    public function test_a_held_lock_makes_the_run_a_no_op(): void
    {
        $this->writeSchedule(["'" . '* * * * *' . "' => " . $this->touchEntry() . ","]);
        $lock = fopen($this->dir . '/app/schedule.lock', 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
            $this->assertSame(0, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')), 'an overlap is not an error');
            $this->assertFalse($this->ran(), 'no job ran while the previous run held the lock');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_a_stale_lock_from_a_crashed_run_is_recovered(): void
    {
        // The post-crash state: the lock file is still there with the dead
        // PID, but nobody holds it (the kernel released the flock at death).
        file_put_contents($this->dir . '/app/schedule.lock', "999999\n");
        $this->writeSchedule(["'" . '* * * * *' . "' => " . $this->touchEntry() . ","]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->assertSame(0, $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00')));
        $this->assertTrue($this->ran(), 'a leftover lock file must not block the next run');
        self::assertNotSame(
            "999999\n",
            (string) file_get_contents($this->dir . '/app/schedule.lock'),
            'the recovered run overwrote the stale PID with its own'
        );
    }

    public function test_an_unopenable_lock_file_fails_loudly(): void
    {
        $this->writeSchedule(["'" . '* * * * *' . "' => " . $this->touchEntry() . ","]);
        mkdir($this->dir . '/app/schedule.lock'); // a directory where the lock file should be
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        $this->expectException(\RuntimeException::class);
        $scheduler->runDue(new \DateTimeImmutable('2026-09-29 14:05:00'));
    }

    public function test_listing_shows_next_due_times_and_job_labels(): void
    {
        $closureMarker = var_export($this->marker, true);
        $this->writeSchedule([
            "'" . '0 3 * * *' . "' => 'ok'",
            "'" . '0 4 * * *' . "' => function () { touch({$closureMarker}); }",
        ]);
        $scheduler = new Scheduler($this->dir . '/app', $this->writeKipStub());
        ob_start();
        $code = $scheduler->listing(new \DateTimeImmutable('2026-09-29 14:05:37'));
        $out = (string) ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('next: 2026-09-30 03:00', $out);
        $this->assertStringContainsString('kip ok', $out);
        $this->assertStringContainsString('closure (', $out);
        $this->assertStringContainsString('app/schedule.php:', $out);
    }
}

/** Shared with the schedule fixture: a [class, method] callable job. */
final class SchedulerStaticJob
{
    public static string $marker = '';

    public static function run(): void
    {
        touch(self::$marker);
    }
}
