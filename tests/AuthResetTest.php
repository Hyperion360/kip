<?php // tests/AuthResetTest.php
namespace Kip\Tests;
use Kip\Auth;
use Kip\Database;
use Kip\Session;
use PHPUnit\Framework\TestCase;

final class AuthResetTest extends TestCase
{
    private Database $db;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, email TEXT, ip TEXT, attempted_at TEXT)');
        $this->db->query('CREATE TABLE password_resets (email TEXT PRIMARY KEY, token_hash TEXT NOT NULL, expires_at TEXT NOT NULL)');
        $store = [];
        $this->auth = new Auth($this->db, new Session($store), static function (): void {});
        $this->auth->register('me@example.com', 'oldpass1');
    }

    public function test_reset_round_trip(): void
    {
        $token = $this->auth->createReset('me@example.com');
        $this->assertNotNull($token);
        $this->assertTrue($this->auth->resetPassword($token, 'newpass1'));
        $this->assertTrue($this->auth->attempt('me@example.com', 'newpass1'));
    }

    public function test_unknown_email_returns_null_but_burns_an_attempt(): void
    {
        $this->assertNull($this->auth->createReset('nobody@example.com', '1.2.3.4'));
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM login_attempts')['c']);
    }

    public function test_token_stored_hashed_not_plaintext(): void
    {
        $token = $this->auth->createReset('me@example.com');
        $row = $this->db->one('SELECT token_hash FROM password_resets');
        $this->assertNotSame($token, $row['token_hash']);
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
    }

    public function test_token_is_single_use(): void
    {
        $token = $this->auth->createReset('me@example.com');
        $this->assertTrue($this->auth->resetPassword($token, 'newpass1'));
        $this->assertFalse($this->auth->resetPassword($token, 'anotherpw'));
    }

    public function test_expired_token_fails(): void
    {
        $token = $this->auth->createReset('me@example.com');
        $this->db->query('UPDATE password_resets SET expires_at = ?', [date('c', time() - 60)]);
        $this->assertFalse($this->auth->resetPassword($token, 'newpass1'));
    }

    public function test_new_reset_invalidates_previous_token(): void
    {
        $old = $this->auth->createReset('me@example.com');
        $this->auth->createReset('me@example.com');
        $this->assertFalse($this->auth->resetPassword($old, 'newpass1'));
    }

    public function test_reset_requests_share_login_throttle(): void
    {
        for ($i = 0; $i < 5; $i++) $this->auth->createReset('me@example.com', '9.9.9.9');
        $this->assertNull($this->auth->createReset('me@example.com', '9.9.9.9')); // 6th refused
    }

    public function test_garbage_token_fails(): void
    {
        $this->assertFalse($this->auth->resetPassword('not-a-real-token', 'newpass1'));
    }

    public function test_successful_reset_clears_login_attempts(): void
    {
        $this->auth->attempt('me@example.com', 'WRONG', '1.1.1.1');
        $token = $this->auth->createReset('me@example.com');
        $this->auth->resetPassword($token, 'newpass1');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM login_attempts WHERE email = ?', ['me@example.com'])['c']);
    }

    public function test_reset_revokes_prior_sessions(): void // the stolen-cookie scenario
    {
        $this->assertTrue($this->auth->attempt('me@example.com', 'oldpass1'));
        $this->assertTrue($this->auth->sessionValid());
        $token = $this->auth->createReset('me@example.com');
        $this->assertTrue($this->auth->resetPassword($token, 'newpass1'));
        $this->assertFalse($this->auth->sessionValid()); // the pre-reset session died with the old hash
        $this->assertTrue($this->auth->attempt('me@example.com', 'newpass1')); // re-login works
    }

    public function test_expiry_compare_survives_dst_offset_mismatch(): void // instants, not strings
    {
        $token = $this->auth->createReset('me@example.com');
        // Stored with a +01:00 offset, "expiring" 30 min BEFORE a +02:00 wall clock says.
        // lexicographic compare calls this still-valid; instant compare must reject it.
        $this->db->query('UPDATE password_resets SET expires_at = ?', [date('c', time() - 1800)]);
        $this->assertFalse($this->auth->resetPassword($token, 'newpass1'));
    }

    public function test_throttle_prunes_rows_outside_the_window(): void // attempts table stays bounded
    {
        $old = date('c', time() - 3600);
        for ($i = 0; $i < 3; $i++) {
            $this->db->query('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)', ['dead@x.y', '5.5.5.5', $old]);
        }
        $this->auth->attempt('me@example.com', 'oldpass1'); // any throttled() call prunes
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM login_attempts WHERE email = 'dead@x.y'")['c']);
    }
}
