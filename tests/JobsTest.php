<?php // tests/JobsTest.php
namespace Kip\Tests;

use Kip\Database;
use Kip\Jobs;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * The durable-jobs battery (src/Jobs.php). The schema comes from the REAL
 * skeleton migration, the way LoginAttemptsPlanTest loads Auth's tables, so
 * the tests can never drift from what apps actually get from `kip migrate`.
 */
final class JobsTest extends TestCase
{
    private Database $db;
    private string $fileDb = '';

    protected function setUp(): void
    {
        $this->db = $this->freshDb();
    }

    protected function tearDown(): void
    {
        foreach ([$this->fileDb, $this->fileDb . '-wal', $this->fileDb . '-shm'] as $f) {
            if ($f !== '' && is_file($f)) @unlink($f);
        }
    }

    private function freshDb(): Database
    {
        $db = new Database($this->dsn());
        (new Migrator($db, dirname(__DIR__) . '/skeleton/app/migrations'))->migrate();
        return $db;
    }

    /** A second connection to the same file, for cross-worker tests. */
    private function secondDb(): Database
    {
        return new Database($this->dsn());
    }

    private function dsn(): string
    {
        if ($this->fileDb === '') {
            $this->fileDb = sys_get_temp_dir() . '/kip-jobs-' . bin2hex(random_bytes(6)) . '.sqlite';
            if (is_file($this->fileDb)) unlink($this->fileDb);
        }
        return 'sqlite:' . $this->fileDb;
    }

    /** @param array<string, mixed> $over */
    private function insertJob(string $class, string $payload, string $status): int
    {
        $this->db->query(
            'INSERT INTO jobs (job_class, payload, status, created_at) VALUES (?, ?, ?, ?)',
            [$class, $payload, $status, date('c')]
        );
        return (int) $this->db->lastInsertId();
    }

    // ------------------------------------------------------------ enqueue

    public function test_enqueue_outside_a_transaction_persists_a_pending_job(): void
    {
        $payload = ['to' => 'someone@example.com', 'nested' => ['keep' => ['deep' => true]], 'unicode' => 'héllo wörld'];
        $id = (new Jobs($this->db))->enqueue(RecordingJob::class, $payload);
        $this->assertGreaterThan(0, $id);
        $row = $this->db->one('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertSame(RecordingJob::class, $row['job_class']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['error']);
        $this->assertNull($row['ran_at']);
        $this->assertSame($payload, json_decode((string) $row['payload'], true)); // round trip, unicode intact
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $row['created_at']));
    }

    public function test_enqueue_inside_a_transaction_rolls_back_with_it(): void
    {
        $jobs = new Jobs($this->db);
        $this->db->begin();
        $jobs->enqueue(RecordingJob::class, ['x' => 1]);
        $this->db->rollBack();
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM jobs')['c']);
    }

    public function test_enqueue_inside_a_transaction_persists_on_commit(): void
    {
        $jobs = new Jobs($this->db);
        $this->db->begin();
        $id = $jobs->enqueue(RecordingJob::class, ['x' => 1]);
        $this->db->commit();
        $this->assertSame('pending', $this->db->one('SELECT status FROM jobs WHERE id = ?', [$id])['status']);
    }

    public function test_enqueue_inside_a_rolled_back_savepoint_rolls_back_with_it(): void
    {
        $jobs = new Jobs($this->db);
        $this->db->begin();
        $jobs->enqueue(RecordingJob::class, ['outer' => 1]);
        $this->db->begin(); // savepoint
        $jobs->enqueue(RecordingJob::class, ['inner' => 1]);
        $this->db->rollBack(); // undoes only the savepoint's work
        $this->db->commit();
        $rows = $this->db->all('SELECT payload FROM jobs');
        $this->assertCount(1, $rows);
        $this->assertSame(['outer' => 1], json_decode((string) $rows[0]['payload'], true));
    }

    public function test_inner_commit_then_outer_rollback_discards_the_job(): void
    {
        $jobs = new Jobs($this->db);
        $this->db->begin();
        $this->db->begin(); // savepoint
        $jobs->enqueue(RecordingJob::class, ['x' => 1]);
        $this->db->commit(); // releases the savepoint, nothing is durable yet
        $this->db->rollBack(); // outer rollback takes the released work with it
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM jobs')['c']);
    }

    public function test_a_worker_connection_cannot_see_the_job_before_the_outer_commit(): void
    {
        $jobs = new Jobs($this->db);
        $worker = new Jobs($this->secondDb());
        $this->db->begin();
        $jobs->enqueue(RecordingJob::class, ['x' => 1]);
        $this->assertNull($worker->claim()); // uncommitted work is invisible
        $this->db->commit();
        $claimed = $worker->claim();
        $this->assertNotNull($claimed);
        $this->assertSame(RecordingJob::class, $claimed['job_class']);
    }

    // --------------------------------------------- enqueue contract checks

    public function test_enqueue_rejects_a_class_that_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        (new Jobs($this->db))->enqueue('Kip\\Tests\\NoSuchJobClassAnywhere', []);
    }

    public function test_enqueue_rejects_a_class_without_handle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle');
        (new Jobs($this->db))->enqueue(JobWithNoHandle::class, []);
    }

    public function test_enqueue_rejects_a_private_handle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('public');
        (new Jobs($this->db))->enqueue(JobWithPrivateHandle::class, []);
    }

    public function test_enqueue_rejects_a_static_handle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('static');
        (new Jobs($this->db))->enqueue(JobWithStaticHandle::class, []);
    }

    public function test_enqueue_rejects_a_constructor_with_required_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('constructor');
        (new Jobs($this->db))->enqueue(JobWithNeededCtorArg::class, []);
    }

    public function test_enqueue_rejects_a_private_constructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('constructor');
        (new Jobs($this->db))->enqueue(JobWithPrivateCtor::class, []);
    }

    public function test_enqueue_rejects_an_abstract_job_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('instantiable');
        (new Jobs($this->db))->enqueue(AbstractJob::class, []);
    }

    public function test_enqueue_rejects_a_handle_without_the_payload_parameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload');
        (new Jobs($this->db))->enqueue(JobWithZeroParamHandle::class, []);
    }

    public function test_enqueue_rejects_a_handle_with_two_required_parameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload');
        (new Jobs($this->db))->enqueue(JobWithTwoParamHandle::class, []);
    }

    public function test_enqueue_rejects_a_declared_generator_return_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generator');
        (new Jobs($this->db))->enqueue(GeneratorReturnTypeJob::class, []);
    }

    public function test_enqueue_rejects_a_payload_json_cannot_encode(): void
    {
        $this->expectException(\JsonException::class);
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['nan' => NAN]);
    }

    // ------------------------------------------------------------- claim

    public function test_claim_takes_the_oldest_pending_row_and_flips_it_to_running(): void
    {
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['first' => 1]);
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['second' => 2]);
        $claimed = (new Jobs($this->db))->claim();
        $this->assertNotNull($claimed);
        $this->assertSame(['first' => 1], json_decode((string) $claimed['payload'], true));
        $row = $this->db->one('SELECT status, ran_at FROM jobs WHERE id = ?', [$claimed['id']]);
        $this->assertSame('running', $row['status']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $row['ran_at']));
    }

    public function test_claim_returns_null_when_nothing_is_pending(): void
    {
        $this->assertNull((new Jobs($this->db))->claim());
    }

    public function test_claim_skips_running_failed_and_done_rows(): void
    {
        // Older terminal rows must never block or satisfy the claim.
        $this->insertJob(RecordingJob::class, '{"done":true}', 'done');
        $this->insertJob(RecordingJob::class, '{"failed":true}', 'failed');
        $this->insertJob(RecordingJob::class, '{"running":true}', 'running'); // a crashed worker's row
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['pending' => true]);
        $claimed = (new Jobs($this->db))->claim();
        $this->assertNotNull($claimed);
        $this->assertSame(['pending' => true], json_decode((string) $claimed['payload'], true));
    }

    public function test_two_connections_never_claim_the_same_row(): void
    {
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['only' => 1]);
        $a = new Jobs($this->db);
        $b = new Jobs($this->secondDb());
        $first = $a->claim();
        $second = $b->claim();
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM jobs WHERE status = 'running'")['c']);
    }

    /**
     * Deterministic lost race (codex fold F6): the first worker's claim UPDATE
     * is tapped through onQuery BEFORE it executes, and a second connection
     * claims the same candidate inside the tap. The tapped UPDATE must then
     * match zero rows, re-read, and return null: never the same row twice.
     */
    public function test_a_worker_that_loses_the_claim_race_returns_null(): void
    {
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['only' => 1]);
        $winner = new Jobs($this->secondDb());
        $raced = false;
        $this->db->onQuery(function (string $sql) use ($winner, &$raced): void {
            if ($raced || !str_starts_with($sql, 'UPDATE jobs SET status')) return;
            $raced = true;
            $this->assertNotNull($winner->claim()); // the other worker commits first
        });
        $loser = new Jobs($this->db);
        $this->assertNull($loser->claim());
        $this->assertTrue($raced);
    }

    public function test_claim_refuses_to_run_inside_a_transaction(): void
    {
        $this->db->begin();
        try {
            (new Jobs($this->db))->claim();
            $this->fail('expected the ambient-transaction refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autocommit', $e->getMessage());
        } finally {
            $this->db->rollBack();
        }
    }

    // ----------------------------------------------------------- runNext

    public function test_runNext_executes_the_job_marks_it_done_and_clears_its_payload(): void
    {
        $payload = ['to' => 'someone@example.com', 'lines' => ['a', 'b'], 'n' => 3];
        (new Jobs($this->db))->enqueue(RecordingJob::class, $payload);
        $row = (new Jobs($this->db))->runNext();
        $this->assertSame('done', $row['status']);
        $this->assertNull($row['error']);
        $this->assertSame($payload, RecordingJob::$received); // exact round trip through JSON
        $this->assertNull($row['payload']); // done rows keep no payload (logical removal)
        $this->assertSame(RecordingJob::class, $row['job_class']);
    }

    public function test_runNext_on_an_empty_queue_returns_null(): void
    {
        $this->assertNull((new Jobs($this->db))->runNext());
    }

    public function test_a_throwing_job_is_marked_failed_with_the_error_kept_in_the_row(): void
    {
        (new Jobs($this->db))->enqueue(BoomJob::class, ['why' => 'test']);
        $row = (new Jobs($this->db))->runNext();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('RuntimeException', (string) $row['error']);
        $this->assertStringContainsString('boom on purpose', (string) $row['error']);
        $this->assertSame(['why' => 'test'], json_decode((string) $row['payload'], true)); // diagnosis kept
        $this->assertNull((new Jobs($this->db))->runNext()); // failed is terminal, never retried
    }

    public function test_a_failed_job_does_not_stop_the_next_one(): void
    {
        $jobs = new Jobs($this->db);
        $jobs->enqueue(BoomJob::class, []);
        $jobs->enqueue(RecordingJob::class, ['still' => 'runs']);
        $this->assertSame('failed', $jobs->runNext()['status']);
        $this->assertSame('done', $jobs->runNext()['status']);
    }

    public function test_a_job_class_deleted_after_enqueue_fails_visibly_at_run_time(): void
    {
        $this->insertJob('Kip\\Tests\\VanishedJobClass', '{}', 'pending');
        $row = (new Jobs($this->db))->runNext();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('VanishedJobClass', (string) $row['error']);
        $this->assertStringContainsString('does not exist', (string) $row['error']);
    }

    public function test_a_corrupt_payload_fails_the_row_not_the_worker(): void
    {
        $this->insertJob(RecordingJob::class, '{"broken', 'pending');
        $row = (new Jobs($this->db))->runNext();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('JsonException', (string) $row['error']);
    }

    public function test_a_scalar_json_payload_fails_the_row(): void
    {
        $this->insertJob(RecordingJob::class, '"just a string"', 'pending');
        $row = (new Jobs($this->db))->runNext();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('not a JSON object', (string) $row['error']);
    }

    public function test_an_undeclared_generator_handle_is_a_contract_failure(): void
    {
        // No declared return type, so enqueue's reflection check passes; the
        // called handle() returns a Generator before its body runs, and the
        // runner must refuse to record that as success.
        (new Jobs($this->db))->enqueue(UndeclaredGeneratorJob::class, []);
        $row = (new Jobs($this->db))->runNext();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('Generator', (string) $row['error']);
        $this->assertFalse(UndeclaredGeneratorJob::$bodyRan);
    }

    public function test_a_successful_job_that_leaks_a_transaction_is_unwound_and_failed(): void
    {
        $jobs = new Jobs($this->db);
        $jobs->enqueue(RecordingJob::class, ['a' => 1]);
        $jobs->runNext(); // consume, so the leak job is the only pending one
        LeakyJob::$db = $this->db;
        $jobs->enqueue(LeakyJob::class, ['b' => 2]);
        $row = $jobs->runNext();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('transaction', (string) $row['error']);
        $this->assertSame(0, $this->db->transactionDepth()); // never leaked past the run
        $row = $this->db->one('SELECT status FROM jobs WHERE job_class = ?', [LeakyJob::class]);
        $this->assertSame('failed', $row['status']); // the leak was rolled back to its entry depth
    }

    public function test_a_throwing_job_that_leaks_a_transaction_is_unwound_and_keeps_its_error(): void
    {
        LeakyJob::$db = $this->db;
        LeakyJob::$throw = true;
        try {
            $jobs = new Jobs($this->db);
            $jobs->enqueue(LeakyJob::class, []);
            $row = $jobs->runNext();
            $this->assertSame('failed', $row['status']);
            $this->assertStringContainsString('leak then throw', (string) $row['error']);
            $this->assertSame(0, $this->db->transactionDepth());
        } finally {
            LeakyJob::$throw = false;
        }
    }

    public function test_a_failed_acknowledgment_propagates_and_leaves_the_row_running(): void
    {
        // The handler runs fine; the mark-done UPDATE aborts (a trigger stands
        // in for disk full / lock trouble). The outcome is uncertain, so the
        // row must stay running and the storage error must surface (codex F3).
        $this->db->exec("CREATE TRIGGER ack_boom BEFORE UPDATE ON jobs WHEN NEW.status = 'done' BEGIN SELECT RAISE(ABORT, 'ack failed'); END");
        (new Jobs($this->db))->enqueue(RecordingJob::class, ['sent' => true]);
        try {
            (new Jobs($this->db))->runNext();
            $this->fail('expected the acknowledgment failure to propagate');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('ack failed', $e->getMessage());
        }
        $this->assertSame(['sent' => true], RecordingJob::$received); // the side effect happened
        $row = $this->db->one('SELECT status FROM jobs LIMIT 1');
        $this->assertSame('running', $row['status']); // never mislabeled done or failed
    }

    public function test_marking_a_row_that_vanished_throws(): void
    {
        $jobs = new Jobs($this->db);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vanished');
        $jobs->markDone(9999);
    }
}

// --------------------------------------------------------- fixture jobs
// The contract is duck-typed: a job is any class with a public handle(array
// $payload): void and a constructor callable with no arguments. These
// fixtures cover the shapes enqueue() must accept and reject, and the ones
// runNext() must survive at run time.

final class RecordingJob
{
    /** @var array<string, mixed>|null */
    public static ?array $received = null;

    public function handle(array $payload): void
    {
        self::$received = $payload;
    }
}

final class BoomJob
{
    public function handle(array $payload): void
    {
        throw new \RuntimeException('boom on purpose');
    }
}

final class LeakyJob
{
    public static ?Database $db = null;
    public static bool $throw = false;

    public function handle(array $payload): void
    {
        self::$db?->begin(); // leaked on purpose
        if (self::$throw) throw new \RuntimeException('leak then throw');
    }
}

final class GeneratorReturnTypeJob
{
    public function handle(array $payload): \Generator
    {
        yield 1;
    }
}

final class UndeclaredGeneratorJob
{
    public static bool $bodyRan = false;

    public function handle(array $payload)
    {
        self::$bodyRan = true; // must never execute: calling a generator returns before the body runs
        yield 1;
    }
}

final class JobWithNoHandle
{
    public function run(array $payload): void {}
}

final class JobWithPrivateHandle
{
    private function handle(array $payload): void {}
}

final class JobWithStaticHandle
{
    public static function handle(array $payload): void {}
}

final class JobWithNeededCtorArg
{
    public function __construct(private int $needed) {}
    public function handle(array $payload): void {}
}

final class JobWithPrivateCtor
{
    private function __construct() {}
    public function handle(array $payload): void {}
}

abstract class AbstractJob
{
    public function handle(array $payload): void {}
}

final class JobWithZeroParamHandle
{
    public function handle(): void {}
}

final class JobWithTwoParamHandle
{
    public function handle(array $payload, int $extra): void {}
}
