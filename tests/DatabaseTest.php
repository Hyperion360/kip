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
}
