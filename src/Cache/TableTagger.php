<?php
namespace Kip\Cache;

use Kip\Migrations\Migrator;

/** Extracts table names from the framework's own simple SQL. Table-granular by design (v0.2). */
final class TableTagger
{
    private const IGNORED = ['sqlite_master', Migrator::LEDGER_TABLE];

    public static function isWrite(string $sql): bool
    {
        return (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql);
    }

    /** @return string[] lowercase table names, ordered as encountered, DDL/internal excluded */
    public static function tables(string $sql): array
    {
        if (preg_match('/^\s*(CREATE|DROP|ALTER|PRAGMA)\b/i', $sql)) {
            return [];
        }
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+([a-z_][a-z0-9_]*)/i', $sql, $m);
        $tables = [];
        foreach ($m[1] as $t) {
            $t = strtolower($t);
            if (!in_array($t, self::IGNORED, true) && !in_array($t, $tables, true)) {
                $tables[] = $t;
            }
        }
        return $tables;
    }
}
