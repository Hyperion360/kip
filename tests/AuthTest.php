<?php // tests/AuthTest.php
namespace Kip\Tests;
use Kip\{Auth, Database, Session};
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private Database $db;
    private Session $session;
    private Auth $auth;
    private array $store = [];
    private int $regenerated = 0;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, ip TEXT, attempted_at TEXT)');
        $this->session = new Session($this->store);
        $this->auth = new Auth($this->db, $this->session, regenerator: function () { $this->regenerated++; });
    }

    public function test_password_stored_hashed(): void // threat model
    {
        $this->auth->register('a@b.c', 'secret123');
        $row = $this->db->one('SELECT * FROM users WHERE email = ?', ['a@b.c']);
        $this->assertNotSame('secret123', $row['password_hash']);
        $this->assertTrue(password_verify('secret123', $row['password_hash']));
    }

    public function test_attempt_success_sets_user_and_regenerates_session_id(): void // fixation
    {
        $this->auth->register('a@b.c', 'secret123');
        $this->assertTrue($this->auth->attempt('a@b.c', 'secret123'));
        $this->assertSame(1, $this->regenerated);
        $this->assertNotNull($this->auth->user());
    }

    public function test_attempt_failure(): void
    {
        $this->auth->register('a@b.c', 'secret123');
        $this->assertFalse($this->auth->attempt('a@b.c', 'wrong'));
        $this->assertNull($this->auth->user());
    }

    public function test_attempt_unknown_email_fails_records_attempt_and_stays_logged_out(): void
    {
        $this->assertFalse($this->auth->attempt('ghost@b.c', 'whatever'));
        $this->assertNull($this->auth->user());
        // the throttle row must exist. Unknown emails count toward lockout like wrong passwords
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) AS c FROM login_attempts WHERE email = 'ghost@b.c'")['c']);
    }

    /**
     * A lower bound, not a ratio: the unknown-email path must actually perform a
     * bcrypt verify rather than returning early. A stall can only make this longer,
     * so unlike the ratio it replaced, load cannot produce a false failure. bcrypt
     * at the default cost is ~200ms here, so a 10ms floor has a wide margin and
     * still catches the verify being removed altogether.
     */
    public function test_unknown_email_path_performs_real_bcrypt_work(): void
    {
        $start = microtime(true);
        $this->assertFalse($this->auth->attempt('ghost@b.c', 'whatever'));
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThan(
            10.0,
            $elapsedMs,
            'the unknown-email path returned too fast to have run a bcrypt verify, '
            . 'so response time now reveals that the account does not exist'
        );
    }

    /**
     * The same floor for an account whose stored hash is not a hash PHP recognizes
     * (empty, truncated, hand-edited). password_verify() rejects those in
     * microseconds, which would mark the account as existing.
     */
    public function test_unverifiable_stored_hash_still_performs_real_bcrypt_work(): void
    {
        $cases = [
            'empty' => '',
            'garbage' => 'not-a-hash',
            'truncated' => '$2y$12$abc',
            'bad salt alphabet' => '$2y$12$' . str_repeat('!', 53),
            'out-of-range cost' => '$2y$99$' . str_repeat('a', 53),
        ];
        foreach ($cases as $label => $stored) {
            $label = str_replace(' ', '-', $label);
            $this->db->query('INSERT INTO users (email, password_hash) VALUES (?, ?)', ["{$label}@b.c", $stored]);

            $start = microtime(true);
            $this->assertFalse($this->auth->attempt("{$label}@b.c", 'whatever'), $label);
            $elapsedMs = (microtime(true) - $start) * 1000;

            $this->assertGreaterThan(10.0, $elapsedMs, "a {$label} stored hash returned too fast, revealing the account");
        }
    }

    /**
     * Bcrypt hashes from other systems use the $2a$/$2b$/$2x$ prefixes.
     * password_verify() accepts them though password_get_info() does not name them,
     * so the equalizer must not mistake them for corrupt hashes and lock users out.
     */
    public function test_other_bcrypt_prefixes_still_log_in(): void
    {
        $y = password_hash('right-pass', PASSWORD_BCRYPT, ['cost' => 4]);
        foreach (['2a', '2b', '2x'] as $variant) {
            $email = "{$variant}@b.c";
            $this->db->query('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, '$' . $variant . substr($y, 3)]);
            $this->assertTrue($this->auth->attempt($email, 'right-pass'), "a \${$variant}\$ bcrypt hash must still verify");
        }
    }

    /**
     * password_hash() throws on a NUL byte where password_verify() just returns false.
     * If only the unknown-email path threw, the response (500 vs a normal failure) and
     * the missing throttle row would reveal which emails exist.
     */
    public function test_nul_byte_password_fails_the_same_way_for_known_and_unknown_emails(): void
    {
        $this->auth->register('real@b.c', 'right-pass');
        foreach (['real@b.c', 'ghost@b.c'] as $email) {
            $start = microtime(true);
            $this->assertFalse($this->auth->attempt($email, "right\0pass"), $email);
            $this->assertGreaterThan(10.0, (microtime(true) - $start) * 1000, "{$email} returned too fast");
            $row = $this->db->one('SELECT COUNT(*) c FROM login_attempts WHERE email = ?', [$email]);
            $this->assertSame(1, (int) $row['c'], "{$email}: the failure must count toward the throttle");
        }
    }

    public function test_logout_clears_user(): void
    {
        $this->auth->register('a@b.c', 'secret123');
        $this->auth->attempt('a@b.c', 'secret123');
        $this->auth->logout();
        $this->assertNull($this->auth->user());
    }

    public function test_logout_regenerates_session_and_rotates_csrf(): void // v0.1.1 T4: parity with login
    {
        $this->auth->register('a@b.c', 'secret123');
        $this->auth->attempt('a@b.c', 'secret123');
        $oldToken = $this->session->csrfToken();
        $countAfterLogin = $this->regenerated;
        $this->auth->logout();
        $this->assertSame($countAfterLogin + 1, $this->regenerated); // id regenerated on logout too
        $this->assertNotSame($oldToken, $this->session->csrfToken());
    }

    public function test_five_failures_throttle_login(): void
    {
        $this->auth->register('a@b.c', 'secret123');
        for ($i = 0; $i < 5; $i++) $this->auth->attempt('a@b.c', 'wrong', '1.2.3.4');
        $this->assertTrue($this->auth->throttled('a@b.c', '1.2.3.4'));
        $this->assertFalse($this->auth->attempt('a@b.c', 'secret123', '1.2.3.4')); // even correct password refused
    }

    public function test_success_clears_failures(): void
    {
        $this->auth->register('a@b.c', 'secret123');
        for ($i = 0; $i < 4; $i++) $this->auth->attempt('a@b.c', 'wrong', '1.2.3.4');
        $this->assertTrue($this->auth->attempt('a@b.c', 'secret123', '1.2.3.4'));
        $this->assertFalse($this->auth->throttled('a@b.c', '1.2.3.4'));
    }

    public function test_old_failures_expire(): void
    {
        $old = date('c', time() - 16 * 60); // outside the 15-minute window
        for ($i = 0; $i < 5; $i++) {
            $this->db->query('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)', ['a@b.c', '1.2.3.4', $old]);
        }
        $this->assertFalse($this->auth->throttled('a@b.c', '1.2.3.4'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_default_regenerator_rotates_the_active_session(): void // the un-injected path: session_regenerate_id(true)
    {
        session_start();
        $db = new Database('sqlite::memory:');
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, ip TEXT, attempted_at TEXT)');
        $store = [];
        $auth = new Auth($db, new Session($store)); // no regenerator closure, the default path
        $auth->register('regen@x.y', 'password1');
        $before = session_id();
        $this->assertTrue($auth->attempt('regen@x.y', 'password1'));
        $this->assertNotSame($before, session_id());
        $this->assertSame(1, $store['user_id']);
    }

    public function test_session_epoch_tracks_the_password(): void // reset/admin-edit revokes sessions
    {
        $store = [];
        $auth = new Auth($this->db, new Session($store), static function (): void {});
        $auth->register('epoch@x.y', 'password1');
        $this->assertTrue($auth->attempt('epoch@x.y', 'password1', '1.1.1.1'));
        $this->assertTrue($auth->sessionValid());
        $this->db->query("UPDATE users SET password_hash = 'completely-new-hash-value'");
        $this->assertFalse($auth->sessionValid()); // the password changed under the session
    }

    public function test_session_without_epoch_is_invalid(): void // legacy/forged sessions fail closed
    {
        $this->auth->register('legacy@x.y', 'password1');
        $store = ['user_id' => 1];
        $this->assertFalse((new Auth($this->db, new Session($store), static function (): void {}))->sessionValid());
    }
}
