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

    /**
     * The realistic failure: a migrations directory that exists but cannot be read.
     * Without GLOB_ERR, glob() returns [] here, so migrate() would report "nothing
     * to migrate" and exit cleanly against a schema it never touched.
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
            // rollback()); it calls the private files() that array_merge's the
            // two glob() results.
            $this->assertSame([], $migrator->migrate(), 'an empty directory applies nothing');
        } finally {
            rmdir($dir);
        }
    }
}
