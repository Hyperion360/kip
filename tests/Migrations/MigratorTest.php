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
     * Regression guard for the glob() hardening: an empty but READABLE migrations
     * directory must stay a normal no-op, not a thrown error.
     *
     * The failure case (glob() returning false) is deliberately not tested here.
     * Verified: glob() on a 0000 directory returns [] on macOS, not false, so the
     * error branch is not reachable through permissions. PHPStan is the check for
     * that branch, exactly as in Tasks 3 and 4.
     */
    /**
     * The enumeration-failure branch, exercised directly.
     *
     * glob() returns false when its pattern exceeds the platform length limit
     * (1024 characters here), which is a reachable way to hit the throw. An
     * earlier version of this plan concluded the branch was unreachable because
     * a 0000-permission directory makes glob() return [] rather than false. That
     * was true of that probe, not of glob(): it was the wrong trigger to try.
     */
    public function test_unlistable_migrations_directory_throws_rather_than_reporting_none(): void
    {
        $db = new \Kip\Database('sqlite::memory:');
        $migrator = new \Kip\Migrations\Migrator($db, '/' . str_repeat('b/', 2000));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot list migrations in');
        $migrator->migrate();
    }

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
