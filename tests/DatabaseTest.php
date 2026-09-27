<?php // tests/DatabaseTest.php
namespace Kip\Tests;
use Kip\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function test_query_binds_parameters(): void // threat model: SQLi
    {
        $this->db->query('INSERT INTO t (name) VALUES (?)', ["Robert'); DROP TABLE t;--"]);
        $rows = $this->db->all('SELECT * FROM t');
        $this->assertCount(1, $rows);
        $this->assertSame("Robert'); DROP TABLE t;--", $rows[0]['name']);
    }

    public function test_one_returns_single_row_or_null(): void
    {
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->assertSame('a', $this->db->one('SELECT * FROM t WHERE id = ?', [1])['name']);
        $this->assertNull($this->db->one('SELECT * FROM t WHERE id = ?', [999]));
    }

    public function test_last_insert_id(): void
    {
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->assertSame('1', $this->db->lastInsertId());
    }

    public function test_on_query_tap_reports_sql(): void // v0.2 T3
    {
        $seen = [];
        $this->db->onQuery(function (string $sql) use (&$seen): void { $seen[] = $sql; });
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->db->all('SELECT * FROM t');
        $this->assertCount(2, $seen);
        $this->assertStringStartsWith('INSERT', $seen[0]);
    }

    public function test_separate_credentials_are_accepted(): void // mysql-style DSNs cannot embed user/pass
    {
        // The sqlite driver ignores username/password, so this proves the
        // constructor plumbs them to PDO without needing a mysql server.
        $db = new Database('sqlite::memory:', 'someuser', 'somepass');
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $db->query('INSERT INTO t (id) VALUES (1)');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM t')['c']);
    }

    /**
     * lastInsertId() declares string. PDO::lastInsertId() is string|false, so
     * the declared type must be enforced rather than assumed.
     */
    public function test_last_insert_id_is_always_a_string(): void
    {
        $db = new \Kip\Database('sqlite::memory:');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $db->query('INSERT INTO t (v) VALUES (?)', ['x']);

        $id = $db->lastInsertId();
        $this->assertIsString($id);
        $this->assertSame('1', $id);
    }

    public function test_nested_begin_opens_a_savepoint_instead_of_throwing(): void
    {
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['outer']);
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['inner']);
        $this->db->commit(); // releases the savepoint
        $this->db->commit(); // commits the outer transaction
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM t')['c']);
    }

    public function test_inner_rollback_undoes_only_the_inner_work(): void
    {
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['outer']);
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['inner']);
        $this->db->rollBack(); // back to the savepoint, outer work intact
        $this->db->commit();
        $this->assertSame([['name' => 'outer']], $this->db->all('SELECT name FROM t'));
    }

    public function test_savepoint_statements_do_not_fire_the_on_query_tap(): void
    {
        $seen = [];
        $this->db->onQuery(function (string $sql) use (&$seen): void { $seen[] = $sql; });
        $this->db->begin();
        $this->db->begin();
        $this->db->commit();
        $this->db->commit();
        $this->db->onQuery(static fn () => null);
        $this->assertSame([], $seen, 'SAVEPOINT plumbing is not table traffic');
    }
}
