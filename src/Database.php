<?php // src/Database.php

declare(strict_types=1);
namespace Kip;

final class Database
{
    private \PDO $pdo;

    /** The DSN exactly as constructed: read-only surfaces (the admin SQL browser, kip db) derive their file path from it. */
    private readonly string $dsn;

    /** @var null|callable(string):void */
    private $onQuery = null;

    /** Transaction nesting depth: 0 = no framework transaction, N = N-1 open SAVEPOINTs. */
    private int $txDepth = 0;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->dsn = $dsn;
        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) {
            $this->pdo->exec('PRAGMA journal_mode = WAL');      // D3: concurrent readers alongside a writer
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** The configured DSN, for introspection that must not re-run the constructor's pragmas. */
    public function dsn(): string { return $this->dsn; }

    /**
     * The server/library version PDO cached at connect (ATTR_SERVER_VERSION):
     * sqlite reports the SQLite library, pgsql the server. No query is sent,
     * so boot-time capability checks cost nothing per request.
     */
    public function serverVersion(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    public function onQuery(callable $listener): void { $this->onQuery = $listener; }

    /** @param array<array-key, mixed> $params positional or named PDO bindings */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        if ($this->onQuery !== null) { ($this->onQuery)($sql); }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param  array<array-key, mixed>     $params
     * @return list<array<array-key, mixed>>  every matching row, FETCH_ASSOC
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param  array<array-key, mixed>    $params
     * @return array<array-key, mixed>|null  the first row, or null when none matched
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function lastInsertId(): string
    {
        // PDO::lastInsertId() is string|false: a driver that cannot report an
        // id returns false. Coerce so the declared return type is the truth.
        return (string) $this->pdo->lastInsertId();
    }

    /**
     * Begin a transaction, or nest inside the one already open: a nested begin
     * opens a SAVEPOINT, its commit releases it, its rollBack undoes only its
     * own work. Framework code that must be atomic (Auth::createReset(),
     * Auth::resetPassword(), PageCache::put(), the Migrator) is therefore safe
     * inside a caller's transaction instead of failing with "There is already
     * an active transaction". Savepoints run on the raw PDO handle so they
     * never fire the onQuery tap: they are not table reads or writes.
     */
    public function begin(): void
    {
        // A driver-side implicit commit (the MySQL DDL class) already ended the
        // transaction; forget the depth so this begin() starts a real one
        // instead of a bare SAVEPOINT. Unreachable on SQLite, defensive.
        if ($this->txDepth > 0 && !$this->pdo->inTransaction()) {
            $this->txDepth = 0;
        }
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT kip_sp' . $this->txDepth);
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->commit(); // unmatched: PDO's own exception, as before
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT kip_sp' . $this->txDepth);
        }
    }

    /**
     * After ROLLBACK TO SAVEPOINT the savepoint stays defined, so a later
     * begin() at the same depth legally redefines it; depth counts nesting
     * levels, not live savepoint names.
     */
    public function rollBack(): void
    {
        if (!$this->pdo->inTransaction()) {
            // A driver-side auto-rollback (the MySQL deadlock class) already ended
            // the transaction; forget the depth so the next begin() starts a real
            // one instead of a bare SAVEPOINT. Unreachable on SQLite, defensive.
            $this->txDepth = 0;
            return; // unmatched rollback stays a no-op
        }
        if ($this->txDepth === 0) {
            // Only reachable when a commit() already decremented and then failed;
            // the transaction is still open, so unwind it completely.
            $this->pdo->rollBack();
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT kip_sp' . $this->txDepth);
        }
    }

    /** Current nesting depth: 0 = no framework transaction open. */
    public function transactionDepth(): int { return $this->txDepth; }

    /** Unwind nested transactions (savepoint per level) until depth == $depth. */
    public function rollBackToDepth(int $depth): void
    {
        while ($this->txDepth > $depth) $this->rollBack();
    }

    /** Multi-statement execution (SQL-file migrations). Tapped like query() so cache tagging stays honest. */
    public function exec(string $sql): void
    {
        if ($this->onQuery !== null) { ($this->onQuery)($sql); }
        $this->pdo->exec($sql);
    }
}
