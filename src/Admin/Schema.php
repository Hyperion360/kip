<?php // src/Admin/Schema.php
namespace Kip\Admin;
use Kip\Database;
use Kip\Migrations\Migrator;

/**
 * SQLite schema introspection for the admin panel. This class is the ONLY
 * gate through which a URL-supplied table name may reach SQL: has() checks
 * membership in sqlite_master, and every other method calls has() first.
 * Column names come exclusively from PRAGMA table_info, never from input.
 */
final class Schema
{
    private ?array $tables = null;        // D6: one catalog query per request, not per call
    /** @var array<string, array> per-table PRAGMA results (same memoization intent) */
    private array $columnsCache = [];

    public function __construct(private Database $db) {}

    /** @return string[] user tables, framework ledger and sqlite internals excluded */
    public function tables(): array
    {
        return $this->tables ??= array_column($this->db->all(
            "SELECT name FROM sqlite_master WHERE type = 'table'
             AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' AND name NOT IN ('" . Migrator::LEDGER_TABLE . "') ORDER BY name"
        ), 'name');
    }

    public function has(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    // Eng-review D6: tables() and columns() are memoized per instance (Schema lives
    // one request; staleness is bounded to that request, so no invalidation is needed).

    /** @return array<int,array{cid:int|string,name:string,type:string,notnull:int|string,dflt_value:?string,pk:int|string}> */
    public function columns(string $table): array
    {
        $this->assertKnown($table);
        return $this->columnsCache[$table] ??= $this->db->all("PRAGMA table_info(\"{$table}\")");
    }

    public function count(string $table): int
    {
        $this->assertKnown($table);
        return (int) $this->db->one("SELECT COUNT(*) c FROM \"{$table}\"")['c'];
    }

    private function assertKnown(string $table): void
    {
        if (!$this->has($table)) {
            throw new \InvalidArgumentException("Unknown table: {$table}");
        }
    }
}
