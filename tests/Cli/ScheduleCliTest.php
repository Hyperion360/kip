<?php // tests/Cli/ScheduleCliTest.php
namespace Kip\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * `bin/kip schedule` end to end against the throwaway app: listing, --due
 * execution, the overlap lock, and the failure contract. These run the real
 * skeleton bin/kip as a subprocess, so output shape and exit codes are the
 * observable contract, exactly what a crontab entry sees.
 */
final class ScheduleCliTest extends TestCase
{
    use CliAppHarness;

    private string $marker;

    protected function setUp(): void
    {
        $this->buildCliApp(withMigrations: false);
        $this->marker = $this->cliApp . '/schedule-marker';
    }

    protected function tearDown(): void
    {
        $this->tearDownCliApp();
    }

    /** Write app/schedule.php with baked marker paths for closure jobs. */
    private function writeSchedule(string $entriesPhp): void
    {
        file_put_contents($this->cliApp . '/app/schedule.php', "<?php return [\n{$entriesPhp}\n];\n");
    }

    private function touchEntry(string $suffix = ''): string
    {
        return 'function () { touch(' . var_export($this->marker . $suffix, true) . '); }';
    }

    public function test_listing_without_a_schedule_file_exits_zero(): void
    {
        [$out, $code] = $this->cli(['schedule']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('No schedule', $out);
        $this->assertStringContainsString('schedule.php not present', $out);
    }

    public function test_listing_shows_entries_with_their_next_due_times(): void
    {
        $this->writeSchedule(
            "'*/5 * * * *' => 'logs:prune --days=30',\n'0 4 * * *' => " . $this->touchEntry()
        );
        [$out, $code] = $this->cli(['schedule']);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('#\*/5 \* \* \* \* +next: \d{4}-\d{2}-\d{2} \d{2}:\d{2}#', $out);
        $this->assertStringContainsString('kip logs:prune --days=30', $out);
        $this->assertStringContainsString('closure (', $out);
        $this->assertStringContainsString('app/schedule.php:', $out);
    }

    public function test_due_runs_matching_entries_and_skips_the_others(): void
    {
        $this->writeSchedule(
            "'* * * * *' => 'logs:prune --days=1',\n'0 0 30 2 *' => 'backup'"
        );
        [$out, $code] = $this->cli(['schedule', '--due']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Pruned 0 rows older than 1 days.', $out); // the command job ran, output passed through
        $this->assertStringNotContainsString('Backup written', $out); // the never-due entry was skipped
        $this->assertSame([], glob($this->cliApp . '/app/backups/kip-backup-*.zip') ?: []);
    }

    public function test_due_without_a_schedule_file_is_silent_and_zero(): void
    {
        [$out, $code] = $this->cli(['schedule', '--due']);
        $this->assertSame(0, $code, $out);
        $this->assertSame('', $out, 'a cron line installed before any schedule exists must not mail');
    }

    public function test_a_clean_due_run_prints_nothing_the_scheduler_generated(): void
    {
        // Only the scheduler's own chatter is at stake: a silent closure job
        // plus stream separation proves nothing lands on stdout on success.
        $this->writeSchedule("'* * * * *' => " . $this->touchEntry());
        $outFile = (string) tempnam(sys_get_temp_dir(), 'kip-sched-out-');
        $errFile = (string) tempnam(sys_get_temp_dir(), 'kip-sched-err-');
        try {
            $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ' . escapeshellarg(PHP_BINARY)
                . ' ./bin/kip schedule --due > ' . escapeshellarg($outFile) . ' 2> ' . escapeshellarg($errFile);
            exec($cmd, $lines, $code);
            $this->assertSame(0, $code, (string) file_get_contents($errFile));
            $this->assertSame('', (string) file_get_contents($outFile), 'the scheduler itself prints nothing on success');
            $this->assertSame('', (string) file_get_contents($errFile));
            $this->assertFileExists($this->marker);
        } finally {
            @unlink($outFile);
            @unlink($errFile);
        }
    }

    public function test_the_lock_file_holds_the_running_pid(): void
    {
        $this->writeSchedule("'* * * * *' => " . $this->touchEntry());
        [, $code] = $this->cli(['schedule', '--due']);
        $this->assertSame(0, $code);
        $pid = (string) file_get_contents($this->cliApp . '/app/schedule.lock');
        $this->assertMatchesRegularExpression('/^\d+$/', trim($pid), 'the lock file carries the PID for humans');
    }

    public function test_a_held_lock_makes_the_overlapping_run_a_no_op(): void
    {
        $this->writeSchedule("'* * * * *' => " . $this->touchEntry());
        $lock = fopen($this->cliApp . '/app/schedule.lock', 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            [$out, $code] = $this->cli(['schedule', '--due']);
            $this->assertSame(0, $code, $out, 'an overlap is normal operation, not an error');
            $this->assertStringContainsString('previous run still active', $out);
            $this->assertFileDoesNotExist($this->marker, 'no job ran while the previous run held the lock');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_a_stale_lock_from_a_crashed_run_is_recovered(): void
    {
        // The post-crash state: the lock file survived with the dead PID, but
        // the kernel released the flock when the holder died, so the next
        // run proceeds and stamps its own PID.
        file_put_contents($this->cliApp . '/app/schedule.lock', "999999\n");
        $this->writeSchedule("'* * * * *' => " . $this->touchEntry());
        [, $code] = $this->cli(['schedule', '--due']);
        $this->assertSame(0, $code);
        $this->assertFileExists($this->marker, 'a leftover lock file must not block the next run');
        $this->assertNotSame("999999\n", (string) file_get_contents($this->cliApp . '/app/schedule.lock'));
    }

    public function test_a_failing_job_does_not_stop_its_siblings_and_fails_the_run(): void
    {
        $this->writeSchedule(
            "'* * * * *' => [function () { throw new RuntimeException('job boom'); }, " . $this->touchEntry('-sibling') . ']'
        );
        [$out, $code] = $this->cli(['schedule', '--due']);
        $this->assertSame(1, $code, $out, 'a failed due entry must fail the cron run');
        $this->assertStringContainsString('job boom', $out);
        $this->assertStringContainsString('schedule:', $out);
        $this->assertFileExists($this->marker . '-sibling');
    }

    public function test_a_typoed_command_reports_and_fails_without_stopping_siblings(): void
    {
        $this->writeSchedule(
            "'* * * * *' => ['backpu', " . $this->touchEntry('-sibling') . ']'
        );
        [$out, $code] = $this->cli(['schedule', '--due']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString("unknown kip command 'backpu'", $out);
        $this->assertFileExists($this->marker . '-sibling');
    }

    public function test_proc_open_disabled_fails_the_command_job_but_not_its_siblings(): void
    {
        $this->writeSchedule(
            "'* * * * *' => ['logs:prune --days=1', " . $this->touchEntry('-sibling') . ']'
        );
        $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ' . escapeshellarg(PHP_BINARY)
            . ' -d disable_functions=proc_open ./bin/kip schedule --due 2>&1';
        exec($cmd, $lines, $code);
        $out = implode("\n", $lines);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('proc_open is unavailable', $out);
        $this->assertFileExists($this->marker . '-sibling', 'the callable sibling still ran');
    }

    public function test_an_unknown_flag_is_a_usage_error(): void
    {
        [$out, $code] = $this->cli(['schedule', '--duez']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Usage: kip schedule', $out);
    }

    public function test_listing_an_invalid_entry_exits_nonzero(): void
    {
        $this->writeSchedule("'60 * * * *' => 'backup'");
        [$out, $code] = $this->cli(['schedule']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('invalid', $out);
        $this->assertStringContainsString('minute must be 0-59', $out);
    }
}
