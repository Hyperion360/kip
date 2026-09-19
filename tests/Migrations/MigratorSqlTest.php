<?php // tests/Migrations/MigratorSqlTest.php
namespace Kip\Tests\Migrations;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorSqlTest extends TestCase
{
    private string $dir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = new Database('sqlite::memory:');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    public function test_sql_migration_runs_up_and_down(): void
    {
        file_put_contents($this->dir . '/001_widgets.sql',
            "-- up\nCREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);\nCREATE INDEX idx_widgets_name ON widgets (name);\n-- down\nDROP TABLE widgets;\n");
        $m = new Migrator($this->db, $this->dir);
        $this->assertSame(['001_widgets'], $m->migrate());
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'widgets'"));
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'idx_widgets_name'")); // multi-statement up
        $this->assertSame(['001_widgets'], $m->rollback());
        $this->assertNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'widgets'"));
    }

    public function test_php_and_sql_migrations_interleave_by_name(): void
    {
        file_put_contents($this->dir . '/002_b.sql', "-- up\nCREATE TABLE b (id INTEGER PRIMARY KEY);\n-- down\nDROP TABLE b;\n");
        file_put_contents($this->dir . '/001_a.php',
            '<?php return new class extends Kip\Migrations\Migration {
                public function up(Kip\Database $db): void { $db->query("CREATE TABLE a (id INTEGER PRIMARY KEY)"); }
                public function down(Kip\Database $db): void { $db->query("DROP TABLE a"); }
            };');
        $this->assertSame(['001_a', '002_b'], (new Migrator($this->db, $this->dir))->migrate());
    }

    public function test_failing_migration_leaves_no_partial_schema(): void
    {
        file_put_contents($this->dir . '/001_bad.sql',
            "-- up\nCREATE TABLE good_half (id INTEGER PRIMARY KEY);\nCREATE BROKEN SYNTAX;\n-- down\nDROP TABLE good_half;\n");
        $m = new Migrator($this->db, $this->dir);
        try {
            $m->migrate();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('001_bad', $e->getMessage());
        }
        $this->assertNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'good_half'")); // transaction rolled back
        $this->assertNull($this->db->one("SELECT name FROM _migrations WHERE name = '001_bad'"));     // not recorded
    }

    public function test_missing_up_marker_is_an_error(): void
    {
        file_put_contents($this->dir . '/001_nomark.sql', "CREATE TABLE x (id INTEGER);\n");
        $this->expectException(\RuntimeException::class);
        (new Migrator($this->db, $this->dir))->migrate();
    }

    public function test_duplicate_name_php_and_sql_is_an_error(): void
    {
        file_put_contents($this->dir . '/001_dup.sql', "-- up\nCREATE TABLE d (id INTEGER);\n-- down\nDROP TABLE d;\n");
        file_put_contents($this->dir . '/001_dup.php',
            '<?php return new class extends Kip\Migrations\Migration {
                public function up(Kip\Database $db): void {}
                public function down(Kip\Database $db): void {}
            };');
        $this->expectException(\RuntimeException::class);
        (new Migrator($this->db, $this->dir))->migrate();
    }

    public function test_sql_migration_without_down_cannot_roll_back(): void
    {
        file_put_contents($this->dir . '/001_updownless.sql', "-- up\nCREATE TABLE u (id INTEGER);\n");
        $m = new Migrator($this->db, $this->dir);
        $m->migrate();
        $this->expectException(\RuntimeException::class);
        $m->rollback();
    }

    public function test_failing_batch_rollback_leaves_batch_fully_applied(): void // whole-batch atomicity
    {
        file_put_contents($this->dir . '/001_ok.sql', "-- up\nCREATE TABLE ok_t (id INTEGER);\n-- down\nDROP TABLE ok_t;\n");
        file_put_contents($this->dir . '/002_bad.sql', "-- up\nCREATE TABLE bad_t (id INTEGER);\n-- down\nDROP BROKEN SYNTAX;\n");
        $m = new Migrator($this->db, $this->dir);
        $m->migrate();
        try {
            $m->rollback(); // newest first: 002_bad fails AFTER 001_ok's down ran
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException) {}
        // The whole batch is still applied and recorded, no half-rolled-back state.
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'ok_t'"));
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'bad_t'"));
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM _migrations')['c']);
    }

    public function test_sql_before_the_up_marker_is_rejected(): void // stray DDL above the marker is an error, not silently skipped
    {
        file_put_contents($this->dir . '/001_stray.sql', "CREATE TABLE stray (id INTEGER);\n-- up\nCREATE TABLE t (id INTEGER);\n-- down\nDROP TABLE t;\n");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only comments may precede');
        (new Migrator($this->db, $this->dir))->migrate();
    }

    public function test_rollback_with_missing_migration_file_throws_and_keeps_ledger(): void
    {
        $file = $this->dir . '/001_gone.sql';
        file_put_contents($file, "-- up\nCREATE TABLE gone (id INTEGER);\n-- down\nDROP TABLE gone;\n");
        $m = new Migrator($this->db, $this->dir);
        $m->migrate();
        unlink($file);
        try {
            $m->rollback();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM _migrations')['c']); // ledger intact
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'gone'"));
    }
}
