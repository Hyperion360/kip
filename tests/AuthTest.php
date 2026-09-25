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
     * The enumeration equalizer, tested by parity rather than by a stopwatch.
     *
     * The dummy verified on the unknown-email path must be exactly what
     * password_hash(PASSWORD_DEFAULT) would produce on THIS runtime. If it is
     * cheaper, unknown emails answer sooner; if dearer, later. Both leak.
     *
     * This is not a fixed number. PASSWORD_DEFAULT's bcrypt cost is 10 up to PHP
     * 8.3 and 12 from 8.4, and Kip supports both. An earlier fix hardcoded cost 12
     * and so reversed the oracle on 8.3. password_needs_rehash() is the precise
     * question to ask: "would PASSWORD_DEFAULT rehash this?" It checks algorithm
     * and options together, and a future default the map lacks fails here.
     *
     * The previous version of this test timed both paths and asserted
     * assertGreaterThan($unknownAt * 0.1, $knownAt). That only failed when the
     * KNOWN path was fast, passed with a 40x margin over a real 53ms-vs-210ms
     * oracle, and flaked under load.
     */
    public function test_selected_dummy_hash_is_what_password_default_produces(): void
    {
        $dummy = (new \ReflectionMethod(\Kip\Auth::class, 'dummyHash'))->invoke(null);

        $this->assertIsString($dummy);
        $this->assertFalse(
            password_needs_rehash($dummy, PASSWORD_DEFAULT),
            'the unknown-email dummy must be a hash PASSWORD_DEFAULT would not rehash; '
            . 'otherwise its verify costs differ from a real account and leak existence. '
            . 'If PHP changed its default, add a dummy for it to Auth::DUMMY_HASHES.'
        );
    }

    /** Every precomputed dummy is a well-formed bcrypt hash, one per cost, covering
     *  the defaults of every supported PHP version (10 on 8.3, 12 on 8.4). */
    public function test_dummy_hashes_cover_each_supported_default_cost(): void
    {
        $hashes = (new \ReflectionClass(\Kip\Auth::class))->getConstant('DUMMY_HASHES');
        $this->assertIsArray($hashes);

        $costs = [];
        foreach ($hashes as $hash) {
            $info = password_get_info($hash);
            $this->assertSame('bcrypt', $info['algoName']);
            $costs[] = $info['options']['cost'];
        }
        $this->assertSame([10, 12], $costs, 'one dummy per supported default cost, ascending');
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
