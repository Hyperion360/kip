<?php // tests/Migrations/MigratorTest.php
namespace Kip\Tests\Migrations;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-mig-' . uniqid();
        mkdir($this->dir);
        $this->db = new Database('sqlite::memory:');
        file_put_contents($this->dir . '/001_create_widgets.php', <<<'PHP'
        <?php
        return new class extends Kip\Migrations\Migration {
            public function up(Kip\Database $db): void { $db->query('CREATE TABLE widgets (id INTEGER PRIMARY KEY)'); }
            public function down(Kip\Database $db): void { $db->query('DROP TABLE widgets'); }
        };
        PHP);
    }

    public function test_runs_pending_migrations_once(): void
    {
        $m = new Migrator($this->db, $this->dir);
        $this->assertSame(['001_create_widgets'], $m->migrate());
        $this->assertSame([], $m->migrate()); // second run: nothing pending
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'widgets'"));
    }

    /**
     * A migration whose up() opens a savepoint and then throws abandons it; the
     * catch's plain rollBack() only unwinds one level, so the depth leaked into
     * whatever runs next. The unwind must return to the entry depth, exactly
     * like App::process() does for controller transactions.
     */
    public function test_a_failed_migration_that_opened_a_savepoint_unwinds_to_entry_depth(): void
    {
        $dir = sys_get_temp_dir() . '/kip-mig-leak-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_boom.php', <<<'PHP'
        <?php
        return new class extends Kip\Migrations\Migration {
            public function up(Kip\Database $db): void { $db->begin(); throw new RuntimeException('boom'); }
            public function down(Kip\Database $db): void {}
        };
        PHP);
        try {
            $db = new Database('sqlite::memory:');
            $m = new Migrator($db, $dir);
            $entryDepth = $db->transactionDepth();
            try {
                $m->migrate();
                $this->fail('expected the migration failure to surface');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('001_boom', $e->getMessage());
                $this->assertStringContainsString('boom', $e->getMessage());
            }
            $this->assertSame($entryDepth, $db->transactionDepth(), 'the savepoint the migration opened and abandoned must be unwound');
            $this->assertNull($db->one("SELECT name FROM _migrations WHERE name = '001_boom'"), 'nothing recorded for the failed migration');
        } finally {
            @unlink($dir . '/001_boom.php');
            @rmdir($dir);
        }
    }

    public function test_rollback_reverses_last_batch_only(): void // review 4A + outside voice #3
    {
        $m = new Migrator($this->db, $this->dir);
        $m->migrate(); // batch 1: widgets
        file_put_contents($this->dir . '/002_create_gadgets.php', <<<'PHP'
        <?php
        return new class extends Kip\Migrations\Migration {
            public function up(Kip\Database $db): void { $db->query('CREATE TABLE gadgets (id INTEGER PRIMARY KEY)'); }
            public function down(Kip\Database $db): void { $db->query('DROP TABLE gadgets'); }
        };
        PHP);
        $m->migrate(); // batch 2: gadgets
        $this->assertSame(['002_create_gadgets'], $m->rollback()); // only batch 2 reversed
        $this->assertNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'gadgets'"));
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'widgets'")); // batch 1 untouched
    }

    /**
     * The rollback path leaks the same way when a down() opens a savepoint and
     * throws: the catch's plain rollBack() unwinds only one level. The unwind
     * must return to the entry depth, and the failed batch stays applied and
     * recorded (no half-rolled-back state).
     */
    public function test_a_failed_down_that_opened_a_savepoint_unwinds_to_entry_depth(): void
    {
        $dir = sys_get_temp_dir() . '/kip-mig-rbleak-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_good_then_boom.php', <<<'PHP'
        <?php
        return new class extends Kip\Migrations\Migration {
            public function up(Kip\Database $db): void { $db->query('CREATE TABLE boom_t (id INTEGER PRIMARY KEY)'); }
            public function down(Kip\Database $db): void { $db->begin(); throw new RuntimeException('boom down'); }
        };
        PHP);
        try {
            $db = new Database('sqlite::memory:');
            $m = new Migrator($db, $dir);
            $m->migrate(); // batch 1 applied and recorded
            $entryDepth = $db->transactionDepth();
            try {
                $m->rollback();
                $this->fail('expected the rollback failure to surface');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('boom down', $e->getMessage());
            }
            $this->assertSame($entryDepth, $db->transactionDepth(), 'the savepoint the down() opened and abandoned must be unwound');
            $this->assertNotNull($db->one("SELECT name FROM _migrations WHERE name = '001_good_then_boom'"), 'the failed batch stays applied and recorded');
        } finally {
            @unlink($dir . '/001_good_then_boom.php');
            @rmdir($dir);
        }
    }

    /**
     * DELIBERATE: no migrations directory means nothing to run, so migrate() is a
     * clean no-op. Throwing here would break an app that simply has no migrations.
     * Pinned so a future change to that is a decision, not an accident.
     */
    public function test_missing_migrations_directory_is_a_no_op(): void
    {
        $dir = sys_get_temp_dir() . '/kip-mig-absent-' . bin2hex(random_bytes(6));
        $migrator = new \Kip\Migrations\Migrator(new \Kip\Database('sqlite::memory:'), $dir);

        $this->assertSame([], $migrator->migrate());
    }

    /**
     * A path that exists but is not a directory cannot be listed, so migrate() must
     * refuse rather than report nothing to do. Unlike the unreadable-directory case
     * below, this reaches the throw for every user, root included.
     */
    public function test_path_that_is_a_file_throws_rather_than_reporting_none(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kip-mig-file-');
        try {
            $migrator = new \Kip\Migrations\Migrator(new \Kip\Database('sqlite::memory:'), $file);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot list migrations in');
            $migrator->migrate();
        } finally {
            unlink($file);
        }
    }

    /**
     * glob() read the directory path itself as a pattern, so a real migrations
     * directory under a path containing [ ] silently listed as empty and its
     * migrations never ran. Hidden files are skipped, as glob('*.sql') skipped them.
     */
    public function test_directory_path_with_glob_metacharacters_still_lists_migrations(): void
    {
        $base = sys_get_temp_dir() . '/kip-mig-br[x]-' . bin2hex(random_bytes(6));
        $dir = $base . '/m';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_meta.sql', "-- up\nCREATE TABLE meta_t (id INTEGER PRIMARY KEY);\n-- down\nDROP TABLE meta_t;\n");
        file_put_contents($dir . '/.hidden.sql', "-- up\nCREATE TABLE hidden_t (id INTEGER);\n-- down\nDROP TABLE hidden_t;\n");
        $db = new \Kip\Database('sqlite::memory:');

        try {
            $this->assertSame(['001_meta'], (new \Kip\Migrations\Migrator($db, $dir))->migrate());
            $this->assertNotNull($db->one("SELECT name FROM sqlite_master WHERE name = 'meta_t'"));
            $this->assertNull($db->one("SELECT name FROM sqlite_master WHERE name = 'hidden_t'"), 'a dotfile is not a migration');
        } finally {
            unlink($dir . '/001_meta.sql');
            unlink($dir . '/.hidden.sql');
            rmdir($dir);
            rmdir($base);
        }
    }

    /** A migrations path that is a dangling symlink is broken config, not "no migrations". */
    public function test_dangling_symlink_as_migrations_directory_throws(): void
    {
        $link = sys_get_temp_dir() . '/kip-mig-link-' . bin2hex(random_bytes(6));
        symlink($link . '-missing-target', $link);
        try {
            $migrator = new \Kip\Migrations\Migrator(new \Kip\Database('sqlite::memory:'), $link);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot list migrations in');
            $migrator->migrate();
        } finally {
            unlink($link);
        }
    }

    /**
     * An entry named like a migration that is not a regular file (a directory, or a
     * symlink whose target is gone) must stop migrate(), not vanish from the batch.
     * glob() used to list these and apply() then failed loudly; skipping them would
     * record a partial batch as complete.
     */
    public function test_migration_named_entry_that_is_not_a_regular_file_throws(): void
    {
        foreach (['directory' => static fn (string $p) => mkdir($p),
                  'broken symlink' => static fn (string $p) => symlink($p . '-missing-target', $p)] as $kind => $make) {
            $dir = sys_get_temp_dir() . '/kip-mig-odd-' . bin2hex(random_bytes(6));
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/001_real.sql', "-- up\nCREATE TABLE real_t (id INTEGER);\n-- down\nDROP TABLE real_t;\n");
            $odd = $dir . '/002_odd.sql';
            $make($odd);
            $error = null;
            try {
                (new \Kip\Migrations\Migrator(new \Kip\Database('sqlite::memory:'), $dir))->migrate();
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            } finally {
                is_link($odd) ? unlink($odd) : @rmdir($odd);
                unlink($dir . '/001_real.sql');
                rmdir($dir);
            }
            $this->assertNotNull($error, "a {$kind} named like a migration must throw");
            $this->assertStringContainsString('002_odd.sql', $error, $kind);
        }
    }

    /**
     * The realistic failure: a migrations directory that exists but cannot be read.
     * The old glob() listing returned [] here, so migrate() reported "nothing to
     * migrate" and exited cleanly against a schema it never touched.
     *
     * Skipped when this user can read a 0000 directory anyway (root, common in
     * containers); the path-is-a-file test above still reaches the throw there.
     */
    public function test_unreadable_migrations_directory_throws_rather_than_reporting_none(): void
    {
        $dir = sys_get_temp_dir() . '/kip-mig-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        chmod($dir, 0000);

        try {
            clearstatcache();
            if (is_readable($dir)) {
                $this->markTestSkipped('this user can read a 0000 directory (root), so the failure cannot be staged');
            }
            $migrator = new \Kip\Migrations\Migrator(new \Kip\Database('sqlite::memory:'), $dir);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot list migrations in');
            $migrator->migrate();
        } finally {
            chmod($dir, 0777);
            rmdir($dir);
        }
    }

    /**
     * Regression guard for the glob() hardening: an empty but READABLE migrations
     * directory must stay a normal no-op: only a genuine read failure is an error,
     * never an empty listing.
     */
    public function test_empty_readable_migrations_directory_is_a_no_op(): void
    {
        $dir = sys_get_temp_dir() . '/kip-mig-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $db = new \Kip\Database('sqlite::memory:');
        $migrator = new \Kip\Migrations\Migrator($db, $dir);

        try {
            // migrate() is one of Migrator's two public methods (the other is
            // rollback()); it calls the private files() that scandir()s the
            // directory and keeps the .php and .sql entries.
            $this->assertSame([], $migrator->migrate(), 'an empty directory applies nothing');
        } finally {
            rmdir($dir);
        }
    }

    /** @return array{0: string, 1: string} [app migrations dir, feature migrations dir] */
    private function twoMigrationDirs(string $appDir, string $featureDir): array
    {
        mkdir($appDir, 0777, true);
        mkdir($featureDir, 0777, true);
        $widget = static fn(string $table): string => <<<PHP
        <?php
        return new class extends Kip\Migrations\Migration {
            public function up(Kip\Database \$db): void { \$db->query('CREATE TABLE {$table} (id INTEGER PRIMARY KEY)'); }
            public function down(Kip\Database \$db): void { \$db->query('DROP TABLE {$table}'); }
        };
        PHP;
        file_put_contents($appDir . '/002_create_gadgets.php', $widget('gadgets'));
        file_put_contents($featureDir . '/001_create_widgets.php', $widget('widgets'));
        file_put_contents($featureDir . '/003_create_sprockets.php', $widget('sprockets'));
        return [$appDir, $featureDir];
    }

    /** The NNN prefixes define one global order across directories, feature folders included (ch. 3). */
    public function test_migrations_across_directories_run_in_one_global_order(): void
    {
        $base = sys_get_temp_dir() . '/kip-mig-two-' . bin2hex(random_bytes(6));
        [$app, $feature] = $this->twoMigrationDirs($base . '/app/migrations', $base . '/Features/Billing/migrations');
        $db = new \Kip\Database('sqlite::memory:');

        try {
            $ran = (new Migrator($db, [$app, $feature]))->migrate();
            $this->assertSame(['001_create_widgets', '002_create_gadgets', '003_create_sprockets'], $ran);
            foreach (['widgets', 'gadgets', 'sprockets'] as $t) {
                $this->assertNotNull($db->one("SELECT name FROM sqlite_master WHERE name = '{$t}'"));
            }
        } finally {
            foreach ([$app . '/002_create_gadgets.php', $feature . '/001_create_widgets.php', $feature . '/003_create_sprockets.php'] as $f) @unlink($f);
            @rmdir($app); @rmdir($feature); @rmdir($base . '/app'); @rmdir($base . '/Features/Billing'); @rmdir($base . '/Features'); @rmdir($base);
        }
    }

    /** One ledger keys on names, not paths, so the same name in two directories is refused (ch. 3). */
    public function test_same_migration_name_in_two_directories_throws(): void
    {
        $base = sys_get_temp_dir() . '/kip-mig-dup-' . bin2hex(random_bytes(6));
        [$app, $feature] = $this->twoMigrationDirs($base . '/app/migrations', $base . '/Features/Billing/migrations');
        copy($feature . '/001_create_widgets.php', $app . '/001_create_widgets.php');

        try {
            $migrator = new Migrator(new \Kip\Database('sqlite::memory:'), [$app, $feature]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Migration name collision: 001_create_widgets');
            $migrator->migrate();
        } finally {
            @unlink($app . '/001_create_widgets.php');
            foreach ([$app . '/002_create_gadgets.php', $feature . '/001_create_widgets.php', $feature . '/003_create_sprockets.php'] as $f) @unlink($f);
            @rmdir($app); @rmdir($feature); @rmdir($base . '/app'); @rmdir($base . '/Features/Billing'); @rmdir($base . '/Features'); @rmdir($base);
        }
    }

    public function test_a_missing_directory_in_the_array_is_skipped(): void
    {
        $base = sys_get_temp_dir() . '/kip-mig-skip-' . bin2hex(random_bytes(6));
        [$app] = $this->twoMigrationDirs($base . '/app/migrations', $base . '/app/Features/Billing/migrations');
        $db = new \Kip\Database('sqlite::memory:');

        try {
            // The Billing folder was deleted; a different feature's absence must not error
            // (delete-a-folder story, ch. 3).
            $this->assertSame(
                ['002_create_gadgets'],
                (new Migrator($db, [$app, $base . '/app/Features/Gone/migrations']))->migrate()
            );
        } finally {
            @unlink($app . '/002_create_gadgets.php');
            @rmdir($app); @rmdir($base . '/app'); @rmdir($base);
        }
    }

    public function test_rollback_finds_files_across_directories(): void
    {
        $base = sys_get_temp_dir() . '/kip-mig-rb-' . bin2hex(random_bytes(6));
        [$app, $feature] = $this->twoMigrationDirs($base . '/app/migrations', $base . '/Features/Billing/migrations');
        $db = new \Kip\Database('sqlite::memory:');
        $m = new Migrator($db, [$app, $feature]);

        try {
            $m->migrate();
            $this->assertSame(
                ['003_create_sprockets', '002_create_gadgets', '001_create_widgets'],
                $m->rollback()
            );
            foreach (['widgets', 'gadgets', 'sprockets'] as $t) {
                $this->assertNull($db->one("SELECT name FROM sqlite_master WHERE name = '{$t}'"));
            }
        } finally {
            foreach ([$app . '/002_create_gadgets.php', $feature . '/001_create_widgets.php', $feature . '/003_create_sprockets.php'] as $f) @unlink($f);
            @rmdir($app); @rmdir($feature); @rmdir($base . '/app'); @rmdir($base . '/Features/Billing'); @rmdir($base . '/Features'); @rmdir($base);
        }
    }

    /** Review P2-3: the rollback not-found message must name every directory, never interpolate the array property. */
    public function test_rollback_not_found_message_names_each_directory(): void
    {
        $base = sys_get_temp_dir() . '/kip-mig-msg-' . bin2hex(random_bytes(6));
        [$app, $feature] = $this->twoMigrationDirs($base . '/app/migrations', $base . '/Features/Billing/migrations');
        $db = new \Kip\Database('sqlite::memory:');
        $m = new Migrator($db, [$app, $feature]);

        try {
            $m->migrate();
            unlink($feature . '/001_create_widgets.php'); // the folder was deleted after the batch ran
            try {
                $m->rollback();
                $this->fail('expected RuntimeException for the vanished migration file');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('001_create_widgets', $e->getMessage());
                $this->assertStringContainsString($app, $e->getMessage());
                $this->assertStringContainsString($feature, $e->getMessage());
            }
        } finally {
            foreach ([$app . '/002_create_gadgets.php', $feature . '/003_create_sprockets.php'] as $f) @unlink($f);
            @rmdir($app); @rmdir($feature); @rmdir($base . '/app'); @rmdir($base . '/Features/Billing'); @rmdir($base . '/Features'); @rmdir($base);
        }
    }
}
