<?php // src/Database.php
namespace Kip;

final class Database
{
    private \PDO $pdo;

    /** @var null|callable(string):void */
    private $onQuery = null;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) {
            $this->pdo->exec('PRAGMA journal_mode = WAL');      // D3: concurrent readers alongside a writer
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
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

    public function begin(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollBack(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }

    /** Multi-statement execution (SQL-file migrations). Tapped like query() so cache tagging stays honest. */
    public function exec(string $sql): void
    {
        if ($this->onQuery !== null) { ($this->onQuery)($sql); }
        $this->pdo->exec($sql);
    }
}
