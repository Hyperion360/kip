<?php // tests/RequestLogTest.php
namespace Kip\Tests;
use Kip\{Database, RequestLog};
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestLogTest extends TestCase
{
    public function test_logs_request_with_user_and_duration(): void
    {
        $log = new RequestLog(new Database('sqlite::memory:'));
        $log->log(new Request('GET', '/posts', [], [], [], '9.8.7.6'), 200, 42, 12.5);
        $row = $log->recent(1)[0];
        $this->assertSame('GET', $row['method']);
        $this->assertSame('/posts', $row['path']);
        $this->assertSame(200, (int) $row['status']);
        $this->assertSame('9.8.7.6', $row['ip']);
        $this->assertSame(42, (int) $row['user_id']);
        $this->assertGreaterThan(0, (float) $row['duration_ms']);
    }

    public function test_guest_user_id_is_null_and_prune_removes_old_rows(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $log->log(new Request('GET', '/', [], [], []), 200, null, 1.0);
        $this->assertNull($log->recent(1)[0]['user_id']);
        $db->query('UPDATE requests SET created_at = ?', [date('c', time() - 40 * 86400)]);
        $this->assertSame(1, $log->prune(30));
        $this->assertSame([], $log->recent(10));
    }

    public function test_path_is_sanitized_of_control_chars(): void // v0.1.1 T7: terminal-escape injection
    {
        $log = new RequestLog(new Database('sqlite::memory:'));
        $log->log(new Request('GET', "/a\x1b]0;pwned\x07b\x00c", [], [], [], '1.1.1.1'), 200, null, 1.0);
        $this->assertSame('/abc', $log->recent(1)[0]['path']);
    }

    public function test_reset_token_paths_are_redacted_at_write_time(): void
    {
        $log = new RequestLog(new Database('sqlite::memory:'));
        $log->log(new Request('GET', '/auth/reset/' . str_repeat('a', 64), [], [], [], '1.1.1.1'), 200, null, 1.0);
        $this->assertSame('/auth/reset/<redacted>', $log->recent(1)[0]['path']);
        // A near-miss shape is not a reset link and keeps its path.
        $log->log(new Request('GET', '/auth/reset/tooshort', [], [], [], '1.1.1.1'), 200, null, 1.0);
        $this->assertSame('/auth/reset/tooshort', $log->recent(1)[0]['path']);
    }

    public function test_retention_days_drives_prune(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db, retentionDays: 7);
        $log->log(new Request('GET', '/x', [], [], []), 200, null, 1.0);
        $db->query('UPDATE requests SET created_at = ?', [date('c', time() - 8 * 86400)]);
        $this->assertSame(1, $log->pruneToRetention());
        $this->assertSame([], $log->recent(10));
    }

    public function test_write_failure_is_swallowed_best_effort(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $db->exec('DROP TABLE requests'); // the insert now throws inside log()
        $log->log(new Request('GET', '/x', [], [], []), 200, null, 1.0);
        $this->addToAssertionCount(1); // reaching here IS the contract: logging never breaks the response
    }

    public function testPruneUsesTheRetentionIndexAndKeepsFreshRows(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $log->prune(30); // no-op on empty, proves prepare
        $plan = $db->all('EXPLAIN QUERY PLAN DELETE FROM requests WHERE julianday(created_at) < julianday(?)', [date('c')]);
        $detail = strtolower(implode(' ', array_merge(...array_map('array_values', $plan))));
        $this->assertStringContainsString('using index idx_requests_retention', $detail);
        $this->assertStringNotContainsString('scan requests', $detail);
        // Selectivity guard (the data-loss regression codex flagged): a fresh row survives.
        $db->query('INSERT INTO requests (created_at, method, path, status, duration_ms, ip) VALUES (?, ?, ?, ?, ?, ?)',
            [date('c'), 'GET', '/x', 200, 1.0, '127.0.0.1']);
        $this->assertSame(0, $log->prune(30));
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM requests')['c']);
        $this->assertSame(1, $log->prune(-1)); // negative window: everything is older, all rows go
    }

    /** G4 logs viewer: the keyset window returns newest-first rows on both cursor directions. */
    public function test_page_returns_newest_first_within_window(): void
    {
        $log = new RequestLog(new Database('sqlite::memory:'));
        foreach (['/a', '/b', '/c', '/d', '/e'] as $p) {
            $log->log(new Request('GET', $p, [], [], []), 200, null, 1.0);
        }
        $ids = static fn(array $rows): array => array_map('intval', array_column($rows, 'id'));
        $this->assertSame([5, 4, 3, 2, 1], $ids($log->page([], 10)));
        $this->assertSame([3, 2, 1], $ids($log->page(['before' => 4], 10)));
        $this->assertSame([5, 4], $ids($log->page(['after' => 3], 10)));
    }

    /** The absent-cursor bound is inclusive: even a max-id row shows on the first page. */
    public function test_page_first_page_includes_a_max_id_row(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $db->query('INSERT INTO requests (id, created_at, method, path, status, duration_ms, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [PHP_INT_MAX, date('c'), 'GET', '/top', 200, 1.0, '127.0.0.1']);
        $page = $log->page([], 10);
        $this->assertCount(1, $page);
        $this->assertSame(PHP_INT_MAX, (int) $page[0]['id']);
    }

    /** Every filter is a bound parameter with literal prefix semantics; user_id 0 is a real id, not a guest sentinel. */
    public function test_page_filters_method_status_path_user_guest(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $seed = [ // [method, path, status, user_id]
            ['GET', '/api/users', 200, 7],
            ['POST', '/api/users', 422, null],
            ['GET', '/posts', 200, null],
            ['GET', '/a%b', 500, 7],
            ['GET', '/a_b', 301, 0],
        ];
        foreach ($seed as $i => [$m, $p, $s, $u]) {
            $db->query('INSERT INTO requests (created_at, method, path, status, duration_ms, ip, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [date('c', time() - count($seed) + $i), $m, $p, $s, 1.0, '127.0.0.1', $u]);
        }
        $paths = static fn(array $rows): array => array_column($rows, 'path');
        $this->assertSame(['/api/users'], $paths($log->page(['method' => 'POST'], 10)));      // exact verb
        $this->assertSame(['/api/users'], $paths($log->page(['status' => 4], 10)));           // 4xx class
        $this->assertSame(['/a%b'], $paths($log->page(['status' => 5], 10)));
        $this->assertSame(['/api/users', '/api/users'], $paths($log->page(['path' => '/api'], 10))); // prefix
        $this->assertSame(['/a%b'], $paths($log->page(['path' => '/a%'], 10)));  // % matches literally
        $this->assertSame(['/a_b'], $paths($log->page(['path' => '/a_'], 10)));  // _ matches literally
        $this->assertSame(['/api/users', '/api/users'], $paths($log->page(['path' => '/AP'], 10))); // LIKE is ASCII case-insensitive, documented
        $this->assertSame(['/api/users', '/api/users'], $paths($log->page(['path' => "/ap\x00i"], 10))); // NUL stripped, not prefix-truncating
        $this->assertSame(['/a%b', '/api/users'], $paths($log->page(['user_id' => 7], 10)));
        $this->assertSame(['/a_b'], $paths($log->page(['user_id' => 0], 10)));   // id 0 is a real user id
        $this->assertSame(['/posts', '/api/users'], $paths($log->page(['guests' => true], 10))); // guests: IS NULL only, newest first
        $this->assertSame(['/api/users'], $paths($log->page(['method' => 'POST', 'status' => 4, 'path' => '/api', 'guests' => true], 10)));
    }

    /** The window query is a seek, not a scan, and never sorts: every filter shape, both directions. */
    public function test_page_plan_is_a_seek_not_a_scan_or_sort(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $log->log(new Request('GET', '/x', [], [], []), 200, null, 1.0);
        $cases = [
            [],
            ['method' => 'GET'],
            ['status' => 4],
            ['path' => '/api'],
            ['user_id' => 7],
            ['guests' => true],
            ['method' => 'GET', 'status' => 2, 'path' => '/api', 'user_id' => 7],
            ['after' => 1],
            ['before' => 1],
        ];
        foreach ($cases as $f) {
            $seen = [];
            $db->onQuery(static function (string $sql) use (&$seen): void { $seen[] = $sql; });
            $log->page($f, 50);
            $db->onQuery(static fn () => null);
            $this->assertCount(1, $seen, 'one statement per page, filters: ' . json_encode($f));
            $plan = $db->all('EXPLAIN QUERY PLAN ' . $seen[0], array_fill(0, substr_count($seen[0], '?'), 1));
            $detail = strtolower(implode(' ', array_merge(...array_map('array_values', $plan))));
            $this->assertStringContainsString('search requests using', $detail, $seen[0]);
            $this->assertStringNotContainsString('scan requests', $detail, $seen[0]);
            $this->assertStringNotContainsString('temp b-tree', $detail, $seen[0]);
        }
    }

    /** Read-only pin: page() issues exactly one SELECT per call and changes nothing. */
    public function test_page_is_one_query_and_never_mutates(): void
    {
        $db = new Database('sqlite::memory:');
        $log = new RequestLog($db);
        $log->log(new Request('GET', '/x', [], [], []), 200, 5, 1.0);
        $before = $db->all('SELECT * FROM requests');
        $sqls = [];
        $db->onQuery(static function (string $sql) use (&$sqls): void { $sqls[] = $sql; });
        $log->page([], 50);
        $log->page(['method' => 'GET', 'before' => 9], 50);
        $log->page(['after' => 0, 'guests' => true], 50);
        $db->onQuery(static fn () => null);
        $this->assertCount(3, $sqls);
        foreach ($sqls as $sql) {
            $this->assertStringStartsWith('SELECT', $sql);
        }
        $this->assertSame($before, $db->all('SELECT * FROM requests'));
    }
}
