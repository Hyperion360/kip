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
}
