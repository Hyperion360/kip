<?php // tests/Cli/KipCliAppTest.php
namespace Kip\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * bin/kip's DB-touching arms (migrate/rollback/user:create/backup/logs),
 * exercised end to end against a throwaway app: the skeleton's real bin/kip
 * + config.php + migrations, with a hand-rolled PSR-4 autoloader pointing
 * Kip\ at the framework checkout (no composer, no network).
 */
final class KipCliAppTest extends TestCase
{
    use CliAppHarness;

    protected function setUp(): void
    {
        $this->buildCliApp(withMigrations: true);
    }

    protected function tearDown(): void
    {
        $this->tearDownCliApp();
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->cliApp . '/app/data.sqlite');
    }

    public function test_migrate_runs_all_migrations_and_creates_schema(): void
    {
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Ran: 001_create_users', $out);
        $this->assertStringContainsString('007_index_login_attempts_by_time', $out);
        $tables = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['users', 'login_attempts', 'password_resets', '_migrations'] as $t) {
            $this->assertContains($t, $tables);
        }
        $this->assertSame(7, (int) $this->pdo()->query('SELECT COUNT(*) FROM _migrations')->fetchColumn());
        $idx = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_password_resets_token'")->fetchColumn();
        $this->assertNotFalse($idx); // token lookups are indexed
        $kind = $this->pdo()->query("SELECT kind FROM login_attempts LIMIT 1");
        $this->assertNotFalse($kind); // split throttle buckets column exists
    }

    public function test_second_migrate_is_a_noop(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to migrate.', $out);
    }

    public function test_rollback_reverses_the_batch(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['rollback']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Rolled back: 007_index_login_attempts_by_time, 006_add_login_attempts_kind, 005_add_password_resets_token_index, 004_create_password_resets, 003_add_users_is_admin, 002_create_login_attempts, 001_create_users', $out);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM _migrations')->fetchColumn());
    }

    public function test_user_create_prompts_and_hashes_password(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['user:create', 'cli@example.com'], "secretpw1\n");
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('User cli@example.com created.', $out);
        $row = $this->pdo()->query("SELECT password_hash, is_admin FROM users WHERE email = 'cli@example.com'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify('secretpw1', $row['password_hash']));
        $this->assertSame(0, (int) $row['is_admin']); // default-deny: no admin without the flag
    }

    public function test_user_create_refuses_a_nul_byte_password(): void
    {
        $this->cli(['migrate']);
        // A shell argument cannot carry a NUL, so printf writes it from an octal escape.
        exec('cd ' . escapeshellarg($this->cliApp) . " && printf 'secret\\000pw1\\n' | " . PHP_BINARY
            . ' ./bin/kip user:create nul@example.com 2>&1', $lines, $code);
        $out = implode("\n", $lines);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Password cannot contain a NUL byte.', $out);
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'nul@example.com'")->fetchColumn());
    }

    public function test_user_create_admin_flag_sets_is_admin(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['user:create', 'root@example.com', '--admin'], "secretpw1\n");
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('User root@example.com created (admin).', $out);
        $this->assertSame(1, (int) $this->pdo()->query("SELECT is_admin FROM users WHERE email = 'root@example.com'")->fetchColumn());
    }

    public function test_user_create_rejects_short_passwords(): void // same floor as the reset flow
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['user:create', 'short@example.com'], "short\n");
        $this->assertSame(1, $code);
        $this->assertStringContainsString('at least 8 characters', $out);
    }

    public function test_backup_writes_restorable_zip(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['backup']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Backup written:', $out);
        $zips = glob($this->cliApp . '/app/backups/kip-backup-*.zip');
        $this->assertNotEmpty($zips);
        $zip = new \ZipArchive();
        $zip->open($zips[0]);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $zip->close();
        $this->assertContains('data.sqlite', $names); // the migrated app database
    }

    public function test_logs_and_prune_on_fresh_app(): void
    {
        $this->cli(['migrate']);
        [, $code] = $this->cli(['logs']);
        $this->assertSame(0, $code);
        [$out, $code] = $this->cli(['logs:prune', '--days=1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Pruned 0 rows older than 1 days.', $out);
    }
}
