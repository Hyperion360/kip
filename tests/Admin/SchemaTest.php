<?php // tests/Admin/SchemaTest.php
namespace Kip\Tests\Admin;
use Kip\Admin\Schema;
use Kip\Database;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private Schema $schema;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE _migrations (name TEXT PRIMARY KEY, batch INTEGER, run_at TEXT)');
        $this->db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT)');
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, is_admin INTEGER DEFAULT 0)');
        $this->schema = new Schema($this->db);
    }

    public function test_tables_lists_user_tables_only(): void
    {
        $this->assertSame(['posts', 'users'], $this->schema->tables()); // _migrations and sqlite_* excluded
    }

    public function test_has_rejects_unknown_and_metachar_names(): void
    {
        $this->assertTrue($this->schema->has('posts'));
        $this->assertFalse($this->schema->has('nope'));
        $this->assertFalse($this->schema->has('posts"; DROP TABLE users;--'));
        $this->assertFalse($this->schema->has('_migrations'));
    }

    public function test_columns_returns_pragma_metadata(): void
    {
        $cols = $this->schema->columns('posts');
        $this->assertSame(['id', 'title', 'body'], array_column($cols, 'name'));
        $this->assertSame(1, (int) $cols[0]['pk']);
        $this->assertSame(1, (int) $cols[1]['notnull']);
    }

    public function test_columns_on_unknown_table_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->schema->columns('nope');
    }

    public function test_count(): void
    {
        $this->db->query("INSERT INTO posts (title) VALUES ('a'), ('b')");
        $this->assertSame(2, $this->schema->count('posts'));
    }
}
