<?php // tests/Cache/TableTaggerTest.php
namespace Kip\Tests\Cache;
use Kip\Cache\TableTagger;
use PHPUnit\Framework\TestCase;

final class TableTaggerTest extends TestCase
{
    public function test_extracts_read_tables(): void
    {
        $this->assertSame(['posts'], TableTagger::tables('SELECT * FROM posts ORDER BY created_at'));
        $this->assertSame(['comments'], TableTagger::tables('SELECT * FROM comments WHERE post_id = ?'));
        $this->assertSame(['a', 'b'], TableTagger::tables('SELECT * FROM a JOIN b ON a.id = b.a_id'));
    }

    public function test_extracts_write_tables_and_kind(): void
    {
        $this->assertTrue(TableTagger::isWrite('INSERT INTO comments (a) VALUES (?)'));
        $this->assertSame(['comments'], TableTagger::tables('INSERT INTO comments (a) VALUES (?)'));
        $this->assertSame(['posts'], TableTagger::tables('UPDATE posts SET x = ?'));
        $this->assertSame(['posts'], TableTagger::tables('DELETE FROM posts WHERE id = ?'));
        $this->assertFalse(TableTagger::isWrite('SELECT * FROM posts'));
    }

    public function test_ignores_ddl_and_internal_tables(): void
    {
        $this->assertSame([], TableTagger::tables('CREATE TABLE x (id INTEGER)'));
        $this->assertSame([], TableTagger::tables('SELECT * FROM sqlite_master'));
        $this->assertSame([], TableTagger::tables('SELECT * FROM _migrations'));
    }
}
