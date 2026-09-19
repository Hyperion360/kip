<?php // tests/Cache/PageCacheTest.php
namespace Kip\Tests\Cache;
use Kip\Cache\PageCache;
use Kip\Database;
use Kip\Http\Response;
use PHPUnit\Framework\TestCase;

final class PageCacheTest extends TestCase
{
    private PageCache $cache;

    protected function setUp(): void
    {
        $this->cache = new PageCache(new Database('sqlite::memory:'), ttlSeconds: 3600);
    }

    public function test_miss_then_hit_roundtrip(): void
    {
        $this->assertNull($this->cache->get('/posts', ''));
        $this->cache->put('/posts', '', new Response('<html>x</html>'), ['posts']);
        $hit = $this->cache->get('/posts', '');
        $this->assertNotNull($hit);
        $this->assertSame('<html>x</html>', $hit->body);
        $this->assertSame('HIT', $hit->headers['X-Kip-Cache']);
    }

    public function test_query_string_is_part_of_the_key(): void
    {
        $this->cache->put('/posts', 'page=1', new Response('p1'), ['posts']);
        $this->assertNull($this->cache->get('/posts', 'page=2'));
    }

    public function test_write_purges_tagged_pages(): void
    {
        $this->cache->put('/posts', '', new Response('list'), ['posts']);
        $this->cache->put('/posts/show/1', '', new Response('show'), ['posts', 'comments']);
        $this->cache->put('/', '', new Response('home'), []);
        $this->cache->purgeByTables(['comments']);
        $this->assertNotNull($this->cache->get('/posts', ''));      // untagged by comments, survives
        $this->assertNull($this->cache->get('/posts/show/1', ''));  // tagged, purged
        $this->assertNotNull($this->cache->get('/', ''));           // tagless page survives all purges
    }

    public function test_expired_entries_are_not_served_and_are_pruned(): void
    {
        $short = new PageCache(new Database('sqlite::memory:'), ttlSeconds: 0);
        $short->put('/x', '', new Response('old'), []);
        $this->assertNull($short->get('/x', '')); // TTL 0 → immediately stale
    }

    public function test_only_status_200_is_stored(): void
    {
        $this->cache->put('/gone', '', new Response('nope', 404), []);
        $this->assertNull($this->cache->get('/gone', ''));
    }

    public function test_opportunistic_prune_removes_tag_rows_too(): void // T4 fix-round
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db, ttlSeconds: 3600);
        $cache->put('/old', '', new Response('old'), ['posts']);
        $db->query('UPDATE pages SET created_at = ?', [time() - 7200]); // force-expire
        $cache->put('/new', '', new Response('new'), []);               // triggers the prune
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM page_tags')['c']);
    }
}
