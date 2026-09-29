<?php // tests/Migrations/RateLimitsPlanTest.php
namespace Kip\Tests\Migrations;

use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\RateLimit;
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

    #[DataProvider('apps')]
    public function test_every_limiter_statement_plans_as_an_index_seek(string $migrations): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, $migrations))->migrate();

        $sql = [];
        $db->onQuery(function (string $q) use (&$sql): void { $sql[] = $q; });
        $rl = new RateLimit($db, ['auth' => ['max' => 1, 'window' => 60], 'api' => ['max' => 1, 'window' => 30]]);
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1000));              // opens the 960 window
        $this->assertNotNull($rl->check('/auth/login', '1.2.3.4', 1001));           // over limit, upsert only
        $this->assertNull($rl->check('/api/x', '9.9.9.9', 1000));                   // opens the 990 window
        $db->onQuery(static fn () => null);

        // Non-vacuous: every statement shape the limiter owns ran.
        $all = implode("\n", $sql);
        $this->assertStringContainsString('INSERT INTO rate_limits', $all);
        $this->assertStringContainsString('ON CONFLICT (prefix, ip, window_start)', $all);
        $this->assertStringContainsString('DELETE FROM rate_limits WHERE window_start < ?', $all);

        // EXPLAIN QUERY PLAN of an INSERT is empty by design, so the upsert's
        // plan cannot be asserted directly: its conflict lookup is gated by
        // the equivalent PK-equality SELECT (SEARCH with the key, verified
        // SCAN without it), and the write itself by the presence pin above
        // plus the counting behavior tests in RateLimitTest.
        $checked = array_values(array_unique(array_filter($sql, static fn (string $q): bool =>
            (str_starts_with(ltrim($q), 'SELECT') || str_starts_with(ltrim($q), 'DELETE')) && str_contains($q, 'rate_limits'))));
        $this->assertNotEmpty($checked, 'the captured inventory must contain the prune');
        foreach ($checked as $q) {
            $plan = implode("\n", array_column(
                $db->all('EXPLAIN QUERY PLAN ' . $q, array_fill(0, substr_count($q, '?'), 1000)),
                'detail'
            ));
            $this->assertStringNotContainsString('SCAN', "{$q}\n{$plan}");
            $this->assertStringNotContainsString('TEMP B-TREE', "{$q}\n{$plan}");
            $this->assertStringContainsString('SEARCH rate_limits USING INDEX idx_rate_limits_window', $plan,
                'the prune must seek the window index, never read the table');
        }

        // The upsert's conflict probe: equality on the counter key seeks the
        // primary key index (drop the PK and this SCANs, so the gate bites).
        $plan = implode("\n", array_column($db->all(
            'EXPLAIN QUERY PLAN SELECT hits FROM rate_limits WHERE prefix = ? AND ip = ? AND window_start = ?',
            ['Auth', '1.2.3.4', 960]
        ), 'detail'));
        $this->assertStringContainsString('SEARCH rate_limits USING INDEX', $plan);
        $this->assertStringNotContainsString('SCAN', $plan);
    }

    public function test_a_configured_request_pays_exactly_one_upsert_after_the_window_opens(): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, dirname(__DIR__, 2) . '/skeleton/app/migrations'))->migrate();
        $rl = new RateLimit($db, ['auth' => ['max' => 10, 'window' => 60]]);

        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1000));
        $this->assertSame(2, $queries, 'the request that opens a window pays the upsert plus one prune');
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1001));
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1002));
        $this->assertSame(4, $queries, 'every later request in the window pays exactly one upsert');
        $db->onQuery(static fn () => null);
    }
}
