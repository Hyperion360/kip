<?php // tests/AuthOAuthTest.php
namespace Kip\Tests;

use Kip\{Auth, Database, Session};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OAuth identity linking and the login-or-register policy (guide ch. 6).
 * The policy in one line: the oauth_identities row is the ONLY key an
 * OAuth login turns; a provider email never resolves to an existing local
 * account, it may only seed a brand-new one, and only when verified.
 */
final class AuthOAuthTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL)');
        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, ip TEXT, attempted_at TEXT, kind TEXT)');
        $this->db->query('CREATE TABLE password_resets (email TEXT PRIMARY KEY, token_hash TEXT, expires_at TEXT)');
    }

    /** Runs the real bundled migration, so the tests pin its actual schema. */
    private function migrateIdentities(): void
    {
        $migration = require dirname(__DIR__) . '/skeleton/app/migrations/009_create_oauth_identities.php';
        $migration->up($this->db);
    }

    /** @param array<string, mixed> $store */
    private function auth(array &$store, ?callable $regenerator = null): Auth
    {
        return new Auth($this->db, new Session($store), $regenerator);
    }

    public function test_migration_declares_the_identity_table_with_cascade(): void
    {
        $this->migrateIdentities();
        $sql = (string) $this->db->one(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'oauth_identities'"
        )['sql'];
        $this->assertStringContainsString('PRIMARY KEY (provider, provider_uid)', $sql);
        $this->assertStringContainsString('REFERENCES users(id) ON DELETE CASCADE', $sql);
    }

    public function test_deleting_a_user_cascades_the_identity_away(): void
    {
        $this->migrateIdentities();
        $store = [];
        $this->auth($store)->register('u@x.y', 'pass-pass-pass');
        $this->auth($store)->linkOAuthIdentity(1, 'google', 'g-1');
        $this->db->query('DELETE FROM users WHERE id = 1');
        $this->assertNull($this->db->one("SELECT * FROM oauth_identities"));
    }

    public function test_link_is_idempotent_and_never_transfers_ownership(): void
    {
        $this->migrateIdentities();
        $store = [];
        $auth = $this->auth($store);
        $auth->register('one@x.y', 'pass-pass-pass');
        $auth->register('two@x.y', 'pass-pass-pass');

        $this->assertTrue($auth->linkOAuthIdentity(1, 'google', 'g-1'));
        $this->assertTrue($auth->linkOAuthIdentity(1, 'google', 'g-1')); // same user: no-op true
        $this->assertFalse($auth->linkOAuthIdentity(2, 'google', 'g-1')); // another user: refused

        $row = $this->db->one("SELECT user_id FROM oauth_identities WHERE provider = 'google' AND provider_uid = 'g-1'");
        $this->assertSame(1, (int) $row['user_id'], 'ownership must not move');
    }

    public function test_identity_match_logs_in_without_asking_the_email(): void
    {
        $this->migrateIdentities();
        $regens = 0;
        $store = [];
        $this->auth($store, function () use (&$regens): void { $regens++; })->register('u@x.y', 'pass-pass-pass');
        $auth = $this->auth($store, function () use (&$regens): void { $regens++; });
        $auth->linkOAuthIdentity(1, 'github', 'gh-9');

        // verified=false on purpose: a linked identity never re-checks email
        $result = $auth->loginOrRegisterOAuth('github', 'gh-9', 'changed@elsewhere.zz', false);

        $this->assertSame(['user' => 1, 'created' => false], $result);
        $this->assertSame(1, $store['user_id']);
        $this->assertTrue($auth->sessionValid());
        $this->assertSame(1, $regens, 'login must regenerate the session id once');
    }

    public function test_stale_identity_of_a_deleted_user_is_cleaned(): void
    {
        // FKs off for this one (a plain table): the lazy cleanup path
        $this->db->query('CREATE TABLE oauth_identities (provider TEXT NOT NULL, provider_uid TEXT NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (provider, provider_uid))');
        $store = [];
        $this->auth($store)->register('u@x.y', 'pass-pass-pass');
        $this->auth($store)->linkOAuthIdentity(1, 'google', 'g-1');
        $this->db->query('DELETE FROM users WHERE id = 1');

        // verified email, now free again: a fresh account is created
        $result = $this->auth($store)->loginOrRegisterOAuth('google', 'g-1', 'u@x.y', true);
        $this->assertSame(['user' => 2, 'created' => true], $result);
        $row = $this->db->one("SELECT user_id FROM oauth_identities WHERE provider = 'google' AND provider_uid = 'g-1'");
        $this->assertSame(2, (int) $row['user_id'], 'the stale row must be gone and re-linked to the new user');
    }

    public function test_verified_free_email_creates_an_account_with_an_unusable_password(): void
    {
        $this->migrateIdentities();
        $store = [];
        $result = $this->auth($store)->loginOrRegisterOAuth('google', 'g-2', '  New@Example.COM ', true);

        $this->assertSame(['user' => 1, 'created' => true], $result);
        $user = $this->db->one('SELECT * FROM users WHERE id = 1');
        $this->assertSame('new@example.com', $user['email'], 'provider emails are trimmed and lowercased');
        foreach (['', 'password', 'new@example.com', 'null'] as $guess) {
            $this->assertFalse(password_verify($guess, (string) $user['password_hash']));
        }
        $linked = $this->db->one("SELECT user_id FROM oauth_identities WHERE provider = 'google' AND provider_uid = 'g-2'");
        $this->assertSame(1, (int) $linked['user_id']);
        $this->assertSame(1, $store['user_id'], 'creation logs the session in');
    }

    /** @return list<array{string, ?string, bool}> */
    public static function provider_email_never_attaches_to_an_existing_account(): array
    {
        return [
            'verified, exact email' => ['taken@x.y', 'taken@x.y', true],
            'verified, case differs' => ['taken@x.y', 'TAKEN@X.Y', true],
            'unverified, free email' => ['taken@x.y', 'other@x.y', false],
            'no email at all' => ['taken@x.y', null, false],
        ];
    }

    #[DataProvider('provider_email_never_attaches_to_an_existing_account')]
    public function test_email_paths_refuse_instead_of_linking(string $registered, ?string $providerEmail, bool $verified): void
    {
        $this->migrateIdentities();
        $store = [];
        $this->auth($store)->register($registered, 'pass-pass-pass');

        $result = $this->auth($store)->loginOrRegisterOAuth('google', 'g-3', $providerEmail, $verified);

        $this->assertNull($result);
        $this->assertArrayNotHasKey('user_id', $store, 'a refusal must leave the session a guest');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'no second account');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM oauth_identities')['c'], 'no identity linked');
    }

    public function test_unverified_free_email_is_refused_too(): void
    {
        $this->migrateIdentities();
        $store = [];
        $this->assertNull($this->auth($store)->loginOrRegisterOAuth('microsoft', 'ms-1', 'fresh@x.y', false));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM oauth_identities')['c']);
    }

    public function test_refusals_leave_no_open_transaction(): void
    {
        $this->migrateIdentities();
        $store = [];
        $this->auth($store)->register('taken@x.y', 'pass-pass-pass');
        $auth = $this->auth($store);
        $before = $this->db->transactionDepth();

        $auth->loginOrRegisterOAuth('google', 'g-4', 'taken@x.y', true);
        $auth->loginOrRegisterOAuth('google', 'g-4', null, false);

        $this->assertSame($before, $this->db->transactionDepth());
    }

    public function test_reset_password_severs_linked_identities(): void
    {
        $this->migrateIdentities();
        $store = [];
        $auth = $this->auth($store);
        $auth->register('u@x.y', 'pass-pass-pass');
        $auth->linkOAuthIdentity(1, 'google', 'g-5');
        $auth->linkOAuthIdentity(1, 'github', 'gh-5');
        $token = (string) $auth->createReset('u@x.y');

        $this->assertTrue($auth->resetPassword($token, 'new-pass-pass'));

        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM oauth_identities')['c'],
            'a reset signals compromise: linked providers must not survive it');
    }

    public function test_reset_password_works_without_the_identity_table(): void
    {
        // Apps that never adopt OAuth reset exactly as before.
        $store = [];
        $auth = $this->auth($store);
        $auth->register('u@x.y', 'pass-pass-pass');
        $token = (string) $auth->createReset('u@x.y');
        $this->assertTrue($auth->resetPassword($token, 'new-pass-pass'));
    }

    public function test_missing_table_fails_loud_naming_the_migration(): void
    {
        $store = [];
        $auth = $this->auth($store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('oauth_identities');
        $auth->linkOAuthIdentity(1, 'google', 'g-6');
    }

    public function test_unique_email_race_returns_null_instead_of_throwing(): void
    {
        $db = new Database('sqlite::memory:');
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL)');
        $db->query('CREATE TABLE oauth_identities (provider TEXT NOT NULL, provider_uid TEXT NOT NULL, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY (provider, provider_uid))');
        $store = [];
        $auth = new Auth($db, new Session($store), static function (): void {});
        // onQuery fires just before execute: the racer's UNIQUE(email) win is
        // simulated by the listener raising the constraint the loser would see.
        $db->onQuery(function (string $sql): void {
            if (str_starts_with($sql, 'INSERT INTO users')) {
                throw new \PDOException('UNIQUE constraint failed: users.email', 19);
            }
        });
        $this->assertNull($auth->loginOrRegisterOAuth('google', 'race-1', 'race@x.com', true));
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], 'the rolled-back half left no user row');
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM oauth_identities')['c'], 'and no identity row');
    }

    public function test_identity_cascade_delete_seeks_the_user_index(): void
    {
        $this->migrateIdentities();
        $plan = implode("\n", array_column(
            $this->db->all("EXPLAIN QUERY PLAN DELETE FROM oauth_identities WHERE user_id IN (SELECT id FROM users WHERE email = ?)", ['u@x.y']),
            'detail'
        ));
        $this->assertStringContainsString('idx_oauth_identities_user', $plan);
        $this->assertDoesNotMatchRegularExpression('/^SCAN oauth_identities$/m', $plan);
    }
}
