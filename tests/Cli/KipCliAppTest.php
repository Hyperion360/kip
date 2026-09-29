<?php // tests/Cli/KipCliAppTest.php
namespace Kip\Tests\Cli;

use Kip\Tests\S3StubServer;
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
    use S3StubServer;

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
        $this->assertStringContainsString('008_create_jobs', $out);
        $tables = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['users', 'login_attempts', 'password_resets', 'jobs', '_migrations'] as $t) {
            $this->assertContains($t, $tables);
        }
        $this->assertSame(8, (int) $this->pdo()->query('SELECT COUNT(*) FROM _migrations')->fetchColumn());
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

    /**
     * Task 5b (review P1-1): the apps map App\ to app/src/, so App\Features\ classes
     * must autoload from app/Features/, the same longest-prefix line the apps'
     * composer.json carries. The harness's hand-rolled autoloader is first-match-wins,
     * so this pins that its map got the longer prefix too. Layered App\ classes keep
     * resolving from app/src/.
     */
    public function test_feature_controllers_autoload_from_the_app_features_root(): void
    {
        mkdir($this->cliApp . '/app/Features/Billing', 0777, true);
        file_put_contents($this->cliApp . '/app/Features/Billing/BillingController.php',
            '<?php namespace App\Features\Billing; final class BillingController { const OK = "feature-loaded"; }');
        $classes = var_export(['App\\Features\\Billing\\BillingController', 'App\\Controllers\\HomeController'], true);
        $script = sprintf(
            'require %s; $ok = 0; foreach (%s as $c) { if (!class_exists($c)) { echo "missing: $c\n"; $ok = 1; } } exit($ok);',
            var_export($this->cliApp . '/vendor/autoload.php', true),
            $classes
        );
        // The harness ships no HomeController file; place one so the shorter App\ prefix is exercised too.
        file_put_contents($this->cliApp . '/app/src/Controllers/HomeController.php',
            '<?php namespace App\Controllers; final class HomeController {}');
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
    }

    public function test_rollback_reverses_the_batch(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['rollback']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Rolled back: 008_create_jobs, 007_index_login_attempts_by_time, 006_add_login_attempts_kind, 005_add_password_resets_token_index, 004_create_password_resets, 003_add_users_is_admin, 002_create_login_attempts, 001_create_users', $out);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM _migrations')->fetchColumn());
    }

    /** Feature folders (ch. 3): a migration inside app/Features/<Name>/migrations applies and rolls back via the CLI. */
    public function test_migrate_applies_and_rolls_back_a_feature_folder_migration(): void
    {
        mkdir($this->cliApp . '/app/Features/Billing/migrations', 0777, true);
        file_put_contents($this->cliApp . '/app/Features/Billing/migrations/009_billing.php', <<<'PHP'
        <?php
        return new class extends Kip\Migrations\Migration {
            public function up(Kip\Database $db): void { $db->query('CREATE TABLE billing_invoices (id INTEGER PRIMARY KEY)'); }
            public function down(Kip\Database $db): void { $db->query('DROP TABLE billing_invoices'); }
        };
        PHP);

        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('008_create_jobs, 009_billing', $out); // one global order: the feature file runs after the app's own migrations
        $this->assertNotFalse($this->pdo()->query("SELECT name FROM sqlite_master WHERE name = 'billing_invoices'")->fetchColumn());

        [$out, $code] = $this->cli(['rollback']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Rolled back: 009_billing', $out); // newest first
        $this->assertFalse((bool) $this->pdo()->query("SELECT name FROM sqlite_master WHERE name = 'billing_invoices'")->fetchColumn());
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

    /** No KIP_BACKUP_S3_* vars set (an empty string counts as unset): local-only, the path above unchanged. */
    public function test_backup_without_s3_vars_stays_local_only(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['backup'], null, ['KIP_BACKUP_S3_ENDPOINT' => '']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Backup written:', $out);
        $this->assertNotEmpty(glob($this->cliApp . '/app/backups/kip-backup-*.zip'));
    }

    /** All five vars set: the archive lands on the (stub) bucket in the same run. */
    public function test_backup_with_all_s3_vars_set_uploads_the_archive(): void
    {
        $this->cli(['migrate']);
        $config = $this->startS3Stub(8098);
        try {
            [$out, $code] = $this->cli(['backup'], null, [
                'KIP_BACKUP_S3_ENDPOINT' => $config['endpoint'],
                'KIP_BACKUP_S3_REGION' => 'us-east-1',
                'KIP_BACKUP_S3_BUCKET' => $config['bucket'],
                'KIP_BACKUP_S3_KEY' => $config['key'],
                'KIP_BACKUP_S3_SECRET' => $config['secret'],
            ]);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('Backup written:', $out);
            $this->assertNotEmpty(glob($config['store'] . '/kip-backup-*.zip'), 'the archive must reach the bucket');
        } finally {
            $this->stopS3Stub();
        }
    }

    /**
     * Partial configuration is a cron typo and must fail loudly under the
     * shared contract (kip: <message>, exit 1) BEFORE any backup is written:
     * a half-configured job never silently falls back to local-only.
     */
    public function test_backup_with_one_s3_var_missing_fails_and_writes_nothing(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['backup'], null, [
            'KIP_BACKUP_S3_ENDPOINT' => 'http://127.0.0.1:8098',
            'KIP_BACKUP_S3_REGION' => 'us-east-1',
            'KIP_BACKUP_S3_BUCKET' => 'stub-bucket',
            'KIP_BACKUP_S3_KEY' => 'stub-key',
            // KIP_BACKUP_S3_SECRET missing
        ]);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringContainsString('KIP_BACKUP_S3_SECRET', $out);
        $this->assertStringNotContainsString('Backup written', $out);
        $this->assertSame([], glob($this->cliApp . '/app/backups/kip-backup-*.zip') ?: [], 'no archive may exist after the refusal');
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

    public function test_a_framework_exception_becomes_a_cli_failure_not_a_trace(): void
    {
        // The harness copies config.php as source, so the DSN to break is the
        // expression itself, not a resolved path. /no-such-dir cannot exist
        // beside the filesystem root, so the PDO open throws instead of
        // creating a database.
        $configPath = $this->cliApp . '/config.php';
        $config = (string) file_get_contents($configPath);
        $broken = str_replace("'sqlite:' . __DIR__ . '/app/data.sqlite'", "'sqlite:/no-such-dir/data.sqlite'", $config);
        $this->assertNotSame($config, $broken, 'skeleton/config.php changed how the db DSN is spelled; update the injection');
        file_put_contents($configPath, $broken);
        [$out, $code] = $this->cli(['migrate']); // harness records combined stdout+stderr
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
    }

    public function test_a_throwing_config_php_follows_the_failure_contract_too(): void
    {
        // config.php is loaded inside the try, so a throw during its require
        // reaches the catch arm like any arm-level error: kip: prefix, exit 1,
        // no trace. Same contract as the DSN test above, earlier in the boot.
        file_put_contents($this->cliApp . '/config.php', "<?php throw new RuntimeException('config boom');\n");
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
    }

    public function test_failure_output_lands_on_stderr_not_stdout(): void
    {
        // Guide ch. 8 owns the contract: failures print "kip: <error>" on STDERR,
        // so deploy tooling can read stdout for success output. The harness merges
        // the two streams and cannot tell a regression to stdout (echo) from the
        // contract; this run separates them.
        $configPath = $this->cliApp . '/config.php';
        $config = (string) file_get_contents($configPath);
        $broken = str_replace("'sqlite:' . __DIR__ . '/app/data.sqlite'", "'sqlite:/no-such-dir/data.sqlite'", $config);
        $this->assertNotSame($config, $broken, 'skeleton/config.php changed how the db DSN is spelled; update the injection');
        file_put_contents($configPath, $broken);
        $errFile = (string) tempnam(sys_get_temp_dir(), 'kip-cli-err-');
        $outFile = (string) tempnam(sys_get_temp_dir(), 'kip-cli-out-');
        try {
            $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ' . escapeshellarg(PHP_BINARY) . ' ./bin/kip migrate'
                . ' > ' . escapeshellarg($outFile) . ' 2> ' . escapeshellarg($errFile);
            exec($cmd, $lines, $code);
            $this->assertSame(1, $code);
            $this->assertStringStartsWith('kip: ', (string) file_get_contents($errFile));
            $this->assertSame('', (string) file_get_contents($outFile), 'the failure line must not leak into stdout');
        } finally {
            @unlink($errFile);
            @unlink($outFile);
        }
    }
    public function test_a_feature_migrations_entry_that_is_a_file_fails_loudly(): void
    {
        mkdir($this->cliApp . '/app/Features/Junk', 0777, true);
        file_put_contents($this->cliApp . '/app/Features/Junk/migrations', 'not a directory');
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('kip: ', $out);
        $this->assertStringNotContainsString('Nothing to migrate', $out); // not a silent skip
    }

    public function test_a_features_dir_that_is_a_regular_file_fails_with_the_reason_and_no_raw_warning(): void
    {
        // The closure's OWN listing branch (Migrator's equivalent is covered
        // elsewhere): Features exists but is a file, so scandir itself fails.
        // The idiom is @scandir + error_get_last, so the exception carries
        // the OS reason and no raw PHP warning leaks into the output.
        file_put_contents($this->cliApp . '/app/Features', 'not a directory');
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Cannot list', $out);
        $this->assertStringContainsString('scandir', $out); // the OS reason is present (cross-OS-stable prefix of the strerror)
        $this->assertStringContainsString('Refusing to migrate', $out);
        $this->assertStringNotContainsString('Warning', $out);
    }

    public function test_logs_command_ignores_a_broken_features_layout(): void
    {
        mkdir($this->cliApp . '/app/Features/Junk', 0777, true);
        file_put_contents($this->cliApp . '/app/Features/Junk/migrations', 'not a directory');
        [$out, $code] = $this->cli(['logs']);
        $this->assertSame(0, $code, $out); // discovery is lazy: non-migration arms never list Features
    }

    public function test_db_without_arguments_lists_tables_ledger_included(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['db']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('users', $out);
        $this->assertStringContainsString('_migrations', $out); // the operator view keeps the ledger visible
    }

    public function test_db_runs_a_read_only_select_with_a_header_row(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['db', 'SELECT name FROM _migrations ORDER BY name LIMIT 1']);
        $this->assertSame(0, $code, $out);
        // The harness joins exec() lines with \n, so the block's own final
        // newline is trimmed: header line first, then the value line.
        $this->assertStringContainsString("name\n001_create_users", $out);
    }

    public function test_db_runs_a_pragma(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['db', 'PRAGMA table_info(users)']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('email', $out);
        $this->assertStringContainsString('password_hash', $out);
    }

    public function test_db_refuses_statements_other_than_select_and_pragma(): void
    {
        $this->cli(['migrate']);
        foreach (
            [
                'CREATE' => 'CREATE TABLE x (i INTEGER)',
                'ATTACH' => "ATTACH DATABASE '/tmp/kip-evil.sqlite' AS e",
                'DELETE' => 'DELETE FROM users',
            ] as $label => $sql
        ) {
            [$out, $code] = $this->cli(['db', $sql]);
            $this->assertSame(1, $code, "{$label}: {$out}");
            $this->assertStringContainsString('SELECT', $out, $label);
            $this->assertStringContainsString('PRAGMA', $out, $label);
        }
    }

    public function test_db_write_pragma_passes_the_gate_and_hits_the_read_only_handle(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['db', 'PRAGMA user_version=5']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringContainsString('readonly', $out); // the database, not the parser, refuses
        $version = $this->pdo()->query('PRAGMA user_version')->fetchColumn();
        $this->assertSame(0, (int) $version); // and nothing changed on disk
    }

    public function test_db_query_error_follows_the_failure_contract(): void
    {
        $this->cli(['migrate']);
        [$out, $code] = $this->cli(['db', 'SELECT * FROM nosuch']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
    }

    public function test_db_failure_output_lands_on_stderr_not_stdout(): void
    {
        $this->cli(['migrate']);
        $errFile = (string) tempnam(sys_get_temp_dir(), 'kip-db-err-');
        $outFile = (string) tempnam(sys_get_temp_dir(), 'kip-db-out-');
        try {
            $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ' . escapeshellarg(PHP_BINARY)
                . ' ./bin/kip db "CREATE TABLE x(i)" > ' . escapeshellarg($outFile) . ' 2> ' . escapeshellarg($errFile);
            exec($cmd, $lines, $code);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('SELECT', (string) file_get_contents($errFile));
            $this->assertSame('', (string) file_get_contents($outFile));
        } finally {
            @unlink($errFile);
            @unlink($outFile);
        }
    }

    public function test_db_on_a_missing_database_exits_one_and_creates_nothing(): void
    {
        [$out, $code] = $this->cli(['db', 'SELECT 1']); // no migrate: app/data.sqlite does not exist
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertFileDoesNotExist($this->cliApp . '/app/data.sqlite');
    }

}
