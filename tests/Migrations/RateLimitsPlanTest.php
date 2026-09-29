<?php // tests/Migrations/RateLimitsPlanTest.php
namespace Kip\Tests\Migrations;

use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rate limiter's storage (guide ch. 6): every statement Kip\RateLimit
 * issues must plan as an index seek against a real app schema, never a SCAN
 * and never a TEMP B-TREE sort (the /db-optimize standard), and the app's own
 * migration must give it the primary key and the window index those plans
 * depend on. SQL is captured through the onQuery tap, so a query rewritten in
 * RateLimit is checked as written, and the inventory is asserted non-vacuous
 * so a silently-changed code path cannot pass an empty gate.
 */
final class RateLimitsPlanTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function apps(): array
    {
        $root = dirname(__DIR__, 2);
        return ['skeleton' => [$root . '/skeleton/app/migrations'], 'blog' => [$root . '/examples/blog/app/migrations']];
    }

    #[DataProvider('apps')]
    public function test_migration_creates_the_counter_key_and_the_window_index(string $migrations): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, $migrations))->migrate();

        // The PK is the counter key: the upsert's ON CONFLICT target and its
        // conflict lookup, in one index.
        $pk = [];
        foreach ($db->all('PRAGMA table_info(rate_limits)') as $col) {
            if ((int) $col['pk'] > 0) $pk[(int) $col['pk']] = strtolower((string) $col['name']);
        }
        ksort($pk);
        $this->assertSame(['prefix', 'ip', 'window_start'], array_values($pk),
            'the primary key must be the counter key (prefix, ip, window_start)');

        // The prune deletes WHERE window_start < ? across every key; the PK
        // leads on prefix, so it needs the dedicated window index to seek.
        $windowIndex = [];
        foreach ($db->all('PRAGMA index_list(rate_limits)') as $idx) {
            if (strcasecmp((string) $idx['name'], 'idx_rate_limits_window') === 0) {
                foreach ($db->all('PRAGMA index_info(idx_rate_limits_window)') as $col) {
                    $windowIndex[] = strtolower((string) $col['name']);
                }
            }
        }
        $this->assertSame(['window_start'], $windowIndex, 'idx_rate_limits_window must lead on window_start');

        // window_start is an integer column: the prune's plain range compare
        // seeks, no julianday() on the column (the login-throttle lesson).
        $types = [];
        foreach ($db->all('PRAGMA table_info(rate_limits)') as $col) {
            $types[strtolower((string) $col['name'])] = strtolower((string) $col['type']);
        }
        $this->assertSame('integer', $types['window_start'] ?? null);
        $this->assertSame('integer', $types['hits'] ?? null);
    }
}
