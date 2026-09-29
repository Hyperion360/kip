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

    public function test_an_upsert_keeps_its_real_table_and_drops_the_set_keyword(): void
    {
        // 'DO UPDATE SET' reads as "UPDATE <table named set>" to a word-boundary
        // extractor; SET is a keyword, not a table. A quoted table really named
        // "set" never matched the bare-identifier regex, so dropping it breaks
        // nothing. The performance contract's upsert idiom rides on this.
        $this->assertSame(['posts'], TableTagger::tables(
            'INSERT INTO posts (id, n) VALUES (?, 1) ON CONFLICT (id) DO UPDATE SET n = n + 1'
        ));
        $this->assertTrue(TableTagger::isWrite(
            'INSERT INTO posts (id, n) VALUES (?, 1) ON CONFLICT (id) DO UPDATE SET n = n + 1'
        ));
    }

    public function test_the_rate_limiter_tables_are_bookkeeping_never_content(): void
    {
        // Every counted request writes rate_limits; those writes must never
        // purge cached pages (no page shows the counter, and the quota churns
        // on every POST), the same exemption the audit ledger already has.
        $upsert = 'INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?, ?, ?, 1)'
            . ' ON CONFLICT (prefix, ip, window_start) DO UPDATE SET hits = hits + 1 RETURNING hits';
        $this->assertSame([], TableTagger::tables($upsert));
        $this->assertTrue(TableTagger::isWrite($upsert));
        $this->assertSame([], TableTagger::tables('DELETE FROM rate_limits WHERE window_start < ?'));
    }
}
