<?php // src/Jobs.php

declare(strict_types=1);
namespace Kip;

/**
 * Durable background jobs, table-backed. `enqueue()` writes one row into the
 * app's `jobs` table through the caller's own Kip\Database: inside a caller's
 * transaction the row lives and dies with it (a rolled-back request rolls its
 * jobs back too), outside one it commits immediately. A worker (`bin/kip
 * queue:work`) claims the oldest pending row, executes it, and records the
 * outcome in the row. `App::defer()` remains the in-request tool; jobs are
 * the durable one.
 *
 * The job contract, duck-typed and closure-free so a row is plain data:
 * a job is any class with a public non-static `handle(array $payload): void`
 * method and a constructor the runner can call with no arguments. The runner
 * builds a fresh instance per run and passes the JSON-decoded payload array.
 * The shape is checked with reflection at enqueue() time (so a typo fails in
 * the request, not on a worker) and again at run() time (a class can change
 * between the two); a drifted row fails visibly, it is never silently skipped.
 */
final class Jobs
{
    /** Lost claim races before the worker gives up loudly: far past any sane two-worker contention. */
    private const CLAIM_ATTEMPTS = 25;

    public function __construct(private Database $db) {}

    /**
     * Queue a job. The INSERT joins the caller's transaction when one is open
     * (same connection only) and autocommits otherwise; workers see the row
     * only once the OUTER commit lands. Payload is stored as JSON, so it must
     * survive json_encode(); treat it as plaintext database content, never put
     * a bearer secret (a raw reset token, say) in it.
     *
     * @param array<string, mixed> $payload
     * @return int the new job row id
     */
    public function enqueue(string $jobClass, array $payload): int
    {
        self::assertJobShape($jobClass);
        $this->db->query(
            'INSERT INTO jobs (job_class, payload, status, created_at) VALUES (?, ?, ?, ?)',
            [$jobClass, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'pending', date('c')]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Claim the oldest pending row and flip it to running, atomically: the
     * UPDATE rechecks status='pending', so when two workers race, the loser's
     * UPDATE matches nothing, it re-reads, and the same row is never handed
     * out twice. Returns the claimed row (id, job_class, payload) or null
     * when nothing is pending.
     *
     * Throws when a framework transaction is open on this connection: a claim
     * inside one would revert to pending on its rollback and re-run a job
     * whose side effects already happened. The worker owns an autocommit
     * connection; jobs that need transactions open and close their own.
     *
     * @return array<string, mixed>|null
     */
    public function claim(): ?array
    {
        if ($this->db->transactionDepth() > 0) {
            throw new \RuntimeException(sprintf(
                'Jobs::claim() needs an autocommit connection, a transaction is open (depth %d). '
                . 'Run workers outside Database::begin()/commit(); a claim inside a transaction '
                . 'reverts to pending on its rollback and runs twice.',
                $this->db->transactionDepth()
            ));
        }
        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $candidate = $this->db->one('SELECT id, job_class, payload FROM jobs WHERE status = ? ORDER BY id LIMIT 1', ['pending']);
            if ($candidate === null) {
                return null;
            }
            $claimed = $this->db->query(
                'UPDATE jobs SET status = ?, ran_at = ? WHERE id = ? AND status = ?',
                ['running', date('c'), $candidate['id'], 'pending']
            )->rowCount();
            if ($claimed === 1) {
                return $candidate;
            }
            usleep(5000); // lost the race: brief pause, then re-read the queue
        }
        throw new \RuntimeException(sprintf(
            'Jobs::claim() lost %d claim races in a row; another worker keeps winning every candidate. '
            . 'Retry this worker shortly or run fewer workers against one SQLite file.',
            self::CLAIM_ATTEMPTS
        ));
    }

    /**
     * Claim and execute the oldest pending job. Returns the finished row
     * (status done or failed, with error set on failure), or null when the
     * queue was empty. A job's own failure (it threw, its class drifted, its
     * payload is corrupt, it leaked a transaction) is recorded in the row and
     * never throws. Queue-storage trouble (a refused claim, an acknowledgment
     * UPDATE that fails, a row that vanished mid-run) DOES throw: the
     * outcome is uncertain and must surface, never be recorded as failed
     * work the job already did.
     *
     * @return array<string, mixed>|null
     */
    public function runNext(): ?array
    {
        $job = $this->claim();
        if ($job === null) {
            return null;
        }
        $id = (int) $job['id'];
        $entryDepth = $this->db->transactionDepth(); // 0: claim() enforced autocommit
        $failure = null;
        try {
            $jobClass = (string) $job['job_class'];
            self::assertJobShape($jobClass); // drift since enqueue: class deleted, shape changed
            $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException('payload is not a JSON object (array), it decoded to ' . get_debug_type($payload));
            }
            $instance = new $jobClass();
            if ($instance->handle($payload) instanceof \Generator) {
                // Calling a generator returns before its body runs; recording
                // that as done would acknowledge work that never executed.
                throw new \RuntimeException('handle() returned a Generator; a job method must not yield');
            }
        } catch (\Throwable $e) {
            $failure = $e::class . ': ' . $e->getMessage();
        }
        // A handler that opened a transaction and abandoned it (return or
        // throw) must not leak it into the acknowledgment or the next claim;
        // unwind first, exactly like App::process() and runDeferred() do.
        if ($this->db->transactionDepth() > $entryDepth) {
            $this->db->rollBackToDepth($entryDepth);
            if ($failure === null) {
                // Its side effects on THIS database are gone with the unwind;
                // done would claim work that no longer exists.
                $failure = 'Kip\Jobs contract: handle() left a database transaction open; '
                    . 'its uncommitted work was rolled back and the job was not recorded as done';
            }
        }
        if ($failure !== null) {
            $this->markFailed($id, $failure);
        } else {
            $this->markDone($id);
        }
        return $this->row($id);
    }

    /** Record success. The payload is logically removed: a done row keeps what ran and when, not what it carried. */
    public function markDone(int $id): void
    {
        $stmt = $this->db->query('UPDATE jobs SET status = ?, payload = NULL, error = NULL WHERE id = ?', ['done', $id]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("job row {$id} vanished before its done update could record it");
        }
    }

    /**
     * Record failure with the error kept in the row. Failed is terminal in
     * this version: no automatic retry, the row is the report (a later
     * version may add retry with backoff).
     */
    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->db->query('UPDATE jobs SET status = ?, error = ? WHERE id = ?', ['failed', $error, $id]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("job row {$id} vanished before its failure could be recorded");
        }
    }

    /**
     * Reflection-only contract check, shared by enqueue() (fail the request
     * on a typo) and runNext() (fail the row on drift). Never instantiates:
     * a constructor may have side effects, and enqueue() runs in requests.
     */
    private static function assertJobShape(string $jobClass): void
    {
        $contract = "a job is an autoloadable class with a public handle(array \$payload): void method "
            . 'and a constructor callable with no arguments';
        if (!class_exists($jobClass)) {
            throw new \InvalidArgumentException("job class {$jobClass} does not exist; {$contract}");
        }
        $ref = new \ReflectionClass($jobClass);
        if (!$ref->isInstantiable()) {
            throw new \InvalidArgumentException("job class {$jobClass} is not instantiable (abstract, an interface, or an enum); {$contract}");
        }
        $ctor = $ref->getConstructor();
        if ($ctor !== null && (!$ctor->isPublic() || $ctor->getNumberOfRequiredParameters() > 0)) {
            throw new \InvalidArgumentException("job class {$jobClass} needs a public constructor with no required arguments, the runner builds each job with new {$jobClass}()");
        }
        if (!$ref->hasMethod('handle')) {
            throw new \InvalidArgumentException("job class {$jobClass} has no handle() method; {$contract}");
        }
        $handle = $ref->getMethod('handle');
        if (!$handle->isPublic()) {
            throw new \InvalidArgumentException("job class {$jobClass}::handle() must be public; {$contract}");
        }
        if ($handle->isStatic()) {
            throw new \InvalidArgumentException("job class {$jobClass}::handle() must be an instance method, not static; the runner builds a fresh instance per run");
        }
        if ($handle->getNumberOfParameters() !== 1 || $handle->getNumberOfRequiredParameters() !== 1) {
            throw new \InvalidArgumentException("job class {$jobClass}::handle() must take exactly the payload parameter (one required array argument); {$contract}");
        }
        $return = $handle->getReturnType();
        if ($return instanceof \ReflectionNamedType
            && in_array($return->getName(), ['Generator', 'Traversable', 'Iterator', 'iterable'], true)) {
            throw new \InvalidArgumentException("job class {$jobClass}::handle() declares a generator/iterator return type; calling it would return before its body ran");
        }
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->db->one('SELECT id, job_class, payload, status, error, created_at, ran_at FROM jobs WHERE id = ?', [$id])
            ?? throw new \RuntimeException("job row {$id} vanished before its outcome could be read back");
    }
}
