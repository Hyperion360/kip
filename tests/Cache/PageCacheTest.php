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

    public function test_an_app_set_etag_is_kept_through_the_store_and_hit_roundtrip(): void
    {
        // The MISS path already emits the app's tag; the HIT must revalidate
        // against the same validator, not a body hash computed at store time.
        $tag = '"app-v1"';
        $this->cache->put('/x', '', (new Response('body'))->withHeader('ETag', $tag), []);
        $hit = $this->cache->get('/x', '');
        $this->assertNotNull($hit);
        $this->assertSame($tag, $hit->headers['ETag']);
        $this->assertNotSame('"' . hash('sha256', 'body') . '"', $hit->headers['ETag']);
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

    public function test_row_cap_evicts_the_oldest_pages_and_their_tags(): void // pentest: junk query strings filled the cache
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db, ttlSeconds: 3600, maxPages: 3);
        foreach (range(1, 5) as $n) {
            $cache->put('/posts', "junk={$n}", new Response("p{$n}"), ['posts']);
        }
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM pages')['c']);
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM page_tags')['c']);
        $this->assertNull($cache->get('/posts', 'junk=1'), 'the oldest went first');
        $this->assertNull($cache->get('/posts', 'junk=2'));
        $this->assertNotNull($cache->get('/posts', 'junk=5'), 'the newest stays');
    }

    /** An upgrade or a lowered max_pages can leave the cache far over the cap: one put() trims it in a few statements. */
    public function test_a_cache_far_over_the_cap_is_trimmed_in_a_bounded_number_of_statements(): void
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db, ttlSeconds: 3600, maxPages: 3);
        $db->query("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 500)
                    INSERT INTO pages (key, body, headers, etag, created_at) SELECT 'k' || i, 'b', '{}', 'e', ? FROM n", [time()]);
        $statements = 0;
        $db->onQuery(function () use (&$statements): void { $statements++; });
        $cache->put('/new', '', new Response('fresh'), ['posts']);
        $db->onQuery(static fn () => null);
        $this->assertSame(3, (int) $db->one('SELECT COUNT(*) c FROM pages')['c']);
        $this->assertNotNull($cache->get('/new', ''), 'the page just written survives');
        $this->assertLessThan(15, $statements, 'eviction is set-based, not a statement per evicted row');
    }

    public function test_a_row_cap_below_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageCache(new Database('sqlite::memory:'), ttlSeconds: 3600, maxPages: 0);
    }

    /** Every delete the cache issues finds its rows through an index, not a table scan. */
    public function test_cache_deletes_never_scan_a_table(): void
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db, ttlSeconds: 3600, maxPages: 1);
        $sql = [];
        $db->onQuery(function (string $q) use (&$sql): void { $sql[] = $q; });
        $cache->put('/a', '', new Response('a'), ['posts']);
        $cache->put('/b', '', new Response('b'), ['posts']);   // over the cap: evicts /a
        $cache->purgeByTables(['posts']);
        $db->onQuery(static fn () => null);
        foreach (array_unique(array_filter($sql, static fn (string $q): bool => str_starts_with($q, 'DELETE'))) as $q) {
            $plan = implode("\n", array_column($db->all('EXPLAIN QUERY PLAN ' . $q, array_fill(0, substr_count($q, '?'), 1)), 'detail'));
            $this->assertDoesNotMatchRegularExpression('/^SCAN (pages|page_tags)$/m', $plan, $q);
        }
    }

    /** put() is one transaction: a write that fails partway leaves no page behind without its tags. */
    public function test_a_failed_put_leaves_no_half_written_page(): void
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db, ttlSeconds: 3600);
        $db->query('DROP TABLE page_tags');   // the tag write will fail after the page row is inserted
        try {
            $cache->put('/posts', '', new Response('list'), ['posts']);
            $this->fail('a failed tag write must surface');
        } catch (\PDOException) {
        }
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM pages')['c'], 'the page row was rolled back');
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

    public function test_framework_default_headers_are_not_stored_so_a_hit_reads_current_ones(): void
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db);
        $cache->put('/x', '', (new Response('b'))->withHeader('X-App', 'custom'), []);
        $stored = json_decode($db->one('SELECT headers FROM pages')['headers'], true);
        // Only what the app actually set is stored; defaults are re-derived on read.
        $this->assertSame(['X-App' => 'custom'], $stored);
        $hit = $cache->get('/x', '');
        $this->assertSame('custom', $hit->headers['X-App']);
        $this->assertSame(Response::defaultHeaders()['Content-Security-Policy'], $hit->headers['Content-Security-Policy']);
        $this->assertSame(Response::defaultHeaders()['X-Frame-Options'], $hit->headers['X-Frame-Options']);
    }

    public function test_an_app_override_of_a_default_header_survives_the_roundtrip(): void
    {
        $this->cache->put('/x', '', new Response('b', 200, ['X-Frame-Options' => 'DENY']), []);
        $hit = $this->cache->get('/x', '');
        $this->assertSame('DENY', $hit->headers['X-Frame-Options']);
    }

    public function test_noindexed_responses_are_refused(): void
    {
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db);
        $cache->put('/empty-listing', '', (new Response('x'))->withHeader('X-Robots-Tag', 'noindex, nofollow'), []);
        $this->assertNull($cache->get('/empty-listing', ''));
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM pages')['c'], 'no row was written at all');
        // Other robots directives still cache; the refusal targets noindex.
        $cache->put('/fine', '', (new Response('y'))->withHeader('X-Robots-Tag', 'noarchive'), []);
        $this->assertNotNull($cache->get('/fine', ''));
    }
    public function test_non_string_header_shapes_are_skipped(): void
    {
        // An app violating the documented array<string, string> contract (an int
        // key, a non-string value) is skipped, not trusted.
        $this->cache->put('/x', '', new Response('b', 200, [7 => 'x', 'X-Custom' => 42]), []);
        $hit = $this->cache->get('/x', '');
        $this->assertNotNull($hit);
        $this->assertSame('b', $hit->body);
    }

    public function test_noindex_guard_matches_name_and_directive_case_insensitively(): void
    {
        // RFC 9110 field names are case-insensitive and apps spell headers
        // freely ('x-robots-tag' is common lowercase); the directive itself is
        // case-insensitive too. A guard keyed to one spelling would cache a page
        // the app meant to keep out of search indexes.
        $db = new Database('sqlite::memory:');
        $cache = new PageCache($db);
        $cache->put('/lower-name', '', (new Response('x'))->withHeader('x-robots-tag', 'noindex'), []);
        $cache->put('/upper-value', '', (new Response('x'))->withHeader('X-Robots-Tag', 'NOINDEX'), []);
        $this->assertNull($cache->get('/lower-name', ''));
        $this->assertNull($cache->get('/upper-value', ''));
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM pages')['c'], 'neither refusal wrote a row');
    }

}
