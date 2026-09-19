<?php // tests/AuthResetSplitTest.php
namespace Kip\Tests;

use Kip\Auth;
use Kip\Database;
use Kip\Session;
use PHPUnit\Framework\TestCase;

/**
 * The split throttle buckets: when login_attempts carries the kind column
 * (skeleton migration 006), reset spam locks only resets, and a login-failure
 * flood never blocks the victim's way back in via "forgot password".
 */
final class AuthResetSplitTest extends TestCase
{
    private Database $db;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, ip TEXT, attempted_at TEXT, kind TEXT NOT NULL DEFAULT \'login\')');
        $this->db->query('CREATE TABLE password_resets (email TEXT PRIMARY KEY, token_hash TEXT NOT NULL, expires_at TEXT NOT NULL)');
        $store = [];
        $this->auth = new Auth($this->db, new Session($store), static function (): void {});
        $this->auth->register('me@example.com', 'oldpass1');
    }

    public function test_reset_spam_locks_resets_but_not_login(): void
    {
        for ($i = 0; $i < 3; $i++) $this->assertNotNull($this->auth->createReset('me@example.com', '9.9.9.9'));
        $this->assertNull($this->auth->createReset('me@example.com', '9.9.9.9'));            // 4th per-account reset refused
        $this->assertTrue($this->auth->attempt('me@example.com', 'oldpass1', '9.9.9.9'));    // login unaffected
    }

    public function test_login_flood_does_not_block_reset(): void
    {
        for ($i = 0; $i < 5; $i++) $this->auth->attempt('me@example.com', 'WRONG', '8.8.8.8');
        $this->assertFalse($this->auth->attempt('me@example.com', 'oldpass1', '8.8.8.8'));   // login throttled
        $this->assertNotNull($this->auth->createReset('me@example.com', '8.8.8.8'));         // recovery path open
    }

    public function test_reset_flood_per_ip_is_looser_than_per_account(): void
    {
        for ($u = 0; $u < 4; $u++) {
            $this->auth->register("victim{$u}@example.com", 'password1');
        }
        for ($u = 0; $u < 4; $u++) {                                                          // 4 accounts × 1 reset each
            $this->assertNotNull($this->auth->createReset("victim{$u}@example.com", '7.7.7.7'));
        }
        $this->assertNotNull($this->auth->createReset('me@example.com', '7.7.7.7'));          // per-IP (4 < 10) still open
    }

    public function test_reset_path_also_prunes_stale_rows(): void // the split table stays bounded without a login
    {
        $old = date('c', time() - 3600);
        for ($i = 0; $i < 3; $i++) {
            $this->db->query("INSERT INTO login_attempts (email, ip, attempted_at, kind) VALUES ('dead@x.y', '6.6.6.6', ?, 'reset')", [$old]);
        }
        $this->auth->createReset('me@example.com', '9.9.9.9');                                // any resetThrottled() call prunes
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE email = 'dead@x.y'")['c']);
    }
}
