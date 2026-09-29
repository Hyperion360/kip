<?php // src/Cron/Scheduler.php

declare(strict_types=1);
namespace Kip\Cron;

/**
 * Runs an app's scheduled jobs from app/schedule.php: a PHP file returning a
 * map of cron expression => job, where a job is a kip command string, a
 * callable, or a list of those (PHP array keys collide silently, so several
 * jobs on one expression share a list value).
 *
 * listing() prints one line per job with its next due time. runDue() runs
 * only the entries whose expression matches the given minute, under a flock
 * on app/schedule.lock so an overlapping invocation is a no-op; the kernel
 * releases the lock when the holder dies, so a crashed run leaves an inert
 * file the next run simply reacquires. One failing job reports to STDERR
 * and never stops its siblings; the exit code is non-zero when any due
 * entry failed. Callables run in-process and must not exit() or die():
 * throw instead, exceptions are the isolated failure channel.
 */
final class Scheduler
{
    /** @var resource|null */
    private $stderr;

    /** @var list<string>|null the kip script's command list, read once */
    private $commands = null;

    /**
     * @param string $appDir the configured app_dir; schedule.php and
     *   schedule.lock live directly inside it
     * @param string $kipScript the bin/kip script that owns the command
     *   list; scheduled command strings are validated against its match
     *   arms, and command jobs run as `php <script> <command>` subprocesses
     * @param ?string $phpBinary the PHP binary for those subprocesses, null
     *   meaning PHP_BINARY (tests pass a dud to exercise launch failure)
     */
    public function __construct(
        private readonly string $appDir,
        private readonly string $kipScript,
        private readonly ?string $phpBinary = null,
    ) {
    }

    /**
     * List every entry with its next due time (local wall time). The return
     * value is the process exit code: 1 when any entry is invalid, so a
     * deploy pipeline can run this as a schedule sanity check.
     */
    public function listing(\DateTimeImmutable $now): int
    {
        $file = $this->scheduleFile();
        if (!is_file($file)) {
            echo "No schedule ({$file} not present).\n";
            return 0;
        }
        $rows = $this->entries();
        if ($rows === []) {
            echo "No scheduled jobs.\n";
            return 0;
        }
        $failed = false;
        foreach ($rows as $row) {
            // An expression error breaks the whole entry; a command error
            // breaks that job. Either way the listing says so and fails.
            $error = $row['error'] ?? $this->firstCommandError($row['jobs']);
            if ($error !== null) {
                printf("%-14s invalid: %s\n", $row['expression'], $error);
                $failed = true;
                continue;
            }
            $next = $row['due'] !== null ? $row['due']->nextDue($now) : null;
            printf(
                "%-14s next: %-29s %s\n",
                $row['expression'],
                $next?->format('Y-m-d H:i') ?? '(no run in the next four years)',
                implode('; ', array_map($this->label(...), $row['jobs']))
            );
        }
        return $failed ? 1 : 0;
    }

    /**
     * Run the entries due in $now's minute. Silent and 0 when nothing is
     * due or no schedule exists (a cron line installed before the app
     * defines a schedule must not mail every minute). 1 when any due entry
     * failed. An overlapping run prints one line to STDERR and returns 0:
     * cron mails on any output, which is how an overrunning job surfaces.
     */
    public function runDue(\DateTimeImmutable $now): int
    {
        if (!is_file($this->scheduleFile())) return 0;
        $rows = $this->entries();

        // Overlap safety: an exclusive non-blocking flock. The PID written
        // into the file is for humans (cat the file, ps -p); the flock is
        // the mutex, and the kernel releases it when the holder dies, so a
        // crashed run leaves a file nobody holds and the next run proceeds.
        // A job subprocess inherits this handle: if the scheduler dies while
        // a command job runs, the job keeps the lock until it exits, the
        // safe direction, because work is still running.
        $lockPath = $this->lockFile();
        error_clear_last();
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            $why = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("cannot open the schedule lock file {$lockPath}: {$why}");
        }
        $wouldBlock = false;
        if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);
            if (!$wouldBlock) {
                // A locking error that is not contention: refuse to run
                // unlocked rather than skip silently.
                throw new \RuntimeException("cannot acquire the schedule lock {$lockPath}: the lock call failed for a reason other than an active run");
            }
            $this->err("schedule: previous run still active, skipping this run.\n");
            return 0;
        }
        try {
            ftruncate($lock, 0);
            rewind($lock);
            $pid = getmypid();
            fwrite($lock, ($pid === false ? 'unknown' : (string) $pid) . "\n");
            fflush($lock);
            $failed = false;
            foreach ($rows as $row) {
                if ($row['error'] !== null) {
                    $this->err("schedule: {$row['expression']}: {$row['error']}\n");
                    $failed = true;
                    continue;
                }
                if ($row['due'] === null || !$row['due']->isDue($now)) continue;
                foreach ($row['jobs'] as $job) {
                    $reason = $this->runJob($job);
                    if ($reason !== null) {
                        $this->err("schedule: {$row['expression']} {$this->label($job)}: {$reason}\n");
                        $failed = true;
                    }
                }
            }
            return $failed ? 1 : 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Load app/schedule.php. Load errors (the file throws, or returns a
     * non-array) fail the whole command; a bad expression or a bad job
     * value lands in the row's error slot instead, so one broken entry
     * never hides the rest.
     *
     * @return list<array{expression: string, due: ?Expression, jobs: array<int, string|callable>, error: ?string}>
     */
    private function entries(): array
    {
        $file = $this->scheduleFile();
        try {
            $loaded = require $file;
        } catch (\Throwable $e) {
            throw new \RuntimeException("schedule file {$file} failed to load: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($loaded)) {
            throw new \RuntimeException("{$file} must return an array of cron expression => job entries, got " . get_debug_type($loaded));
        }
        $rows = [];
        foreach ($loaded as $key => $value) {
            // A numeric key (PHP collapses '5' to int 5) stringifies here and
            // fails the five-field check with a message naming it.
            $row = ['expression' => is_string($key) ? $key : (string) $key, 'due' => null, 'jobs' => [], 'error' => null];
            try {
                $row['due'] = Expression::parse($row['expression']);
            } catch (\InvalidArgumentException $e) {
                $row['error'] = $e->getMessage();
            }
            [$jobs, $error] = $this->jobsFor($value);
            if ($error !== null && $row['error'] === null) {
                $row['error'] = $error;
            }
            $row['jobs'] = $jobs;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The shape of one job value: a command string, a callable (checked
     * BEFORE the list, since ['Class', 'method'] is itself an array), or a
     * list of those. Anything else is a per-entry error.
     *
     * @param mixed $value
     * @return array{0: array<int, string|callable>, 1: ?string}
     */
    private function jobsFor(mixed $value): array
    {
        if (is_string($value)) return [[$value], null];
        if (is_callable($value)) return [[$value], null];
        if (is_array($value) && array_is_list($value)) {
            $jobs = [];
            foreach ($value as $item) {
                if (is_string($item) || is_callable($item)) {
                    $jobs[] = $item;
                    continue;
                }
                return [[], 'a job list item must be a command string or a callable, got ' . get_debug_type($item)];
            }
            return [$jobs, null];
        }
        return [[], 'a job must be a kip command string, a callable, or a list of those, got ' . get_debug_type($value)];
    }

    /**
     * Every command job's first token must be a real command: bin/kip's
     * default arm prints usage and exits 0 for an unknown command, so a
     * typo would otherwise count as success forever. Checked per job, at
     * listing time and again at run time, so one bad job never stops its
     * siblings in the same list.
     *
     * @param array<int, string|callable> $jobs
     */
    private function firstCommandError(array $jobs): ?string
    {
        foreach ($jobs as $job) {
            if (!is_string($job)) continue;
            $error = $this->commandErrorOf($job);
            if ($error !== null) return $error;
        }
        return null;
    }

    private function commandErrorOf(string $job): ?string
    {
        if (trim($job) === '') return 'a command job must not be empty';
        $first = preg_split('/\s+/', trim($job), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
        return in_array($first, $this->commands(), true)
            ? null
            : "unknown kip command '{$first}' (check the spelling against bin/kip)";
    }

    /** Run one job; null on success, a one-line failure reason otherwise. */
    private function runJob(string|callable $job): ?string
    {
        if (is_string($job)) {
            return $this->commandErrorOf($job) ?? $this->spawn($job);
        }
        try {
            $job();
            return null;
        } catch (\Throwable $e) {
            // Where it failed, not the full trace: trace arguments would copy
            // job data into the cron mail.
            return $e::class . ': ' . $e->getMessage();
        }
    }

    /**
     * Run a command job as a kip subprocess. Output passes straight through
     * to stdout and stderr (cron mails whatever the job prints), the exit
     * code becomes the job's success or failure, and there is no timeout: a
     * hung job holds the lock until it exits, which is the overlap contract.
     */
    private function spawn(string $command): ?string
    {
        if (!function_exists('proc_open')) {
            return 'proc_open is unavailable on this host, command jobs cannot run; use a callable job for this entry';
        }
        $tokens = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pipes = [];
        error_clear_last();
        $process = @proc_open(
            [$this->phpBinary ?? \PHP_BINARY, $this->kipScript, ...$tokens],
            [0 => ['pipe', 'r'], 1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes,
            dirname($this->kipScript, 2) // the app root, where the documented crontab line cds to
        );
        if ($process === false) {
            // On some platforms the exec failure surfaces here with a warning
            // (spawn refused); on others the child exits 127. Either way the
            // job failed and its siblings continue.
            $why = error_get_last()['message'] ?? 'unknown error';
            return "failed to launch the kip subprocess: {$why}";
        }
        fclose($pipes[0]);
        $code = proc_close($process);
        return $code === 0 ? null : "exited with code {$code}";
    }

    /** One line of STDERR: the failure channel cron reads. */
    private function err(string $line): void
    {
        $h = $this->stderr ??= @fopen('php://stderr', 'wb');
        if (is_resource($h)) fwrite($h, $line);
    }

    /**
     * The command list, read from the kip script's own match arms (the same
     * anchored shape scripts/check-docs.php reads, and that gate keeps the
     * formatting honest), so validation cannot drift from what actually
     * runs. Read lazily and once: a callable-only schedule never needs it.
     *
     * @return list<string>
     */
    private function commands(): array
    {
        if ($this->commands !== null) return $this->commands;
        $source = @file_get_contents($this->kipScript);
        if ($source === false) {
            throw new \RuntimeException("cannot read the kip script {$this->kipScript} to validate scheduled commands");
        }
        $count = preg_match_all("/^    '([A-Za-z0-9:_-]+)'\s*=>/m", $source, $matches);
        if ($count === false || $count === 0) {
            throw new \RuntimeException("cannot read the command list from {$this->kipScript}: no match arms in the expected shape");
        }
        return $this->commands = array_values(array_unique($matches[1]));
    }

    /** The listing label for a job: the invocation, or where the callable is defined. */
    private function label(string|callable $job): string
    {
        if (is_string($job)) return "kip {$job}";
        if (is_array($job)) {
            $target = $job[0];
            return (is_object($target) ? $target::class : (string) $target) . '::' . $job[1];
        }
        if ($job instanceof \Closure) {
            try {
                $ref = new \ReflectionFunction($job);
                return 'closure (' . $ref->getFileName() . ':' . $ref->getStartLine() . ')';
            } catch (\ReflectionException) {
                return 'closure';
            }
        }
        // A callable that is neither string, array, nor closure: an invokable
        // object. The is_object guard is for the type checker only; every
        // value jobsFor() lets through is an object here.
        return is_object($job) ? $job::class : 'callable';
    }

    private function scheduleFile(): string
    {
        return $this->appDir . '/schedule.php';
    }

    private function lockFile(): string
    {
        return $this->appDir . '/schedule.lock';
    }
}
