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
}
