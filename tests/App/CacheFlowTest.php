<?php // tests/App/CacheFlowTest.php
namespace Kip\Tests\App;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

// Fixture controller for the Set-Cookie cache-guard pin, resolved via a second App
// instance's controller_namespace, same pattern as AppTest's fixture controllers.
class CookiePageController
{
    public function index(): \Kip\Http\Response
    {
        return (new \Kip\Http\Response('c'))->withHeader('Set-Cookie', 'pref=dark');
    }
}

// HTTP field names are case-insensitive, so the cacheability guard scans every
// key rather than enumerating spellings.
class CookieCasePageController
{
    public function index(): \Kip\Http\Response
    {
        return (new \Kip\Http\Response('c'))->withHeader('SET-COOKIE', 'pref=dark');
    }
}

// Fixture carrying its own cache directives, for the 304 header-copy pin.
class CacheHeaderPageController
{
    public function index(): \Kip\Http\Response
    {
        return (new \Kip\Http\Response('ch'))
            ->withHeader('Cache-Control', 'max-age=60')
            ->withHeader('Vary', 'Accept-Encoding')
            ->withHeader('Content-Location', '/canonical');
    }
}

// The strongest form of the duplicate-validator bug: both spellings on one
// response. The framework must collapse them into one canonical field.
class DualEtagPageController
{
    public function index(): \Kip\Http\Response
    {
        return new \Kip\Http\Response('de', 200, ['etag' => '"one"', 'ETag' => '"two"']);
    }
}

final class CacheFlowTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        $this->db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT, created_at TEXT)');
        $this->db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, author TEXT, body TEXT, created_at TEXT)');
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('T', 'B', '2026-01-01')");
    }

    private function get(string $path, array $headers = []): \Kip\Http\Response
    {
        return $this->app->handle(new Request('GET', $path, [], [], [], '', $headers));
    }

    public function test_guest_get_is_cached_second_hit_serves_from_cache(): void
    {
        $this->assertSame('MISS', $this->get('/posts')->headers['X-Kip-Cache']);
        $this->assertSame('HIT', $this->get('/posts')->headers['X-Kip-Cache']);
    }

    public function test_error_response_carries_no_validators(): void // review D5c: no ETags on errors
    {
        $res = $this->get('/nope/nope');
        $this->assertSame(404, $res->status);
        $this->assertArrayNotHasKey('ETag', $res->headers);
    }

    public function test_write_invalidates_only_tagged_pages(): void
    {
        $this->get('/posts');            // caches list (tags: posts)
        $this->get('/posts/show/1');     // caches show (tags: posts, comments)
        $this->app->handle(new Request('POST', '/comments/store/1', [],
            ['author' => 'A', 'body' => 'B'], [], '', ['sec-fetch-site' => 'same-origin']));
        $this->assertSame('HIT', $this->get('/posts')->headers['X-Kip-Cache']);          // comments write doesn't touch list
        $this->assertSame('MISS', $this->get('/posts/show/1')->headers['X-Kip-Cache']);  // show page purged
    }

    public function test_request_with_session_cookie_bypasses_cache(): void
    {
        $this->get('/posts'); // warm the cache
        $res = $this->app->handle(new Request('GET', '/posts', [], [], ['PHPSESSID' => 'abc']));
        $this->assertSame('BYPASS', $res->headers['X-Kip-Cache']);
    }

    public function test_request_with_authorization_header_bypasses_cache(): void // RFC 9111 shared-cache rule
    {
        $res = $this->get('/posts', ['authorization' => 'Bearer token']);
        $this->assertSame('BYPASS', $res->headers['X-Kip-Cache']);
        $this->assertSame('MISS', $this->get('/posts')->headers['X-Kip-Cache'], 'the authorized request stored no row');
        $this->assertSame('HIT', $this->get('/posts')->headers['X-Kip-Cache'], 'the same path without the header caches normally');
    }

    public function test_warmed_page_is_not_served_to_a_request_with_authorization_header(): void
    {
        $this->get('/posts'); // warm the cache
        $res = $this->get('/posts', ['authorization' => 'Bearer token']);
        $this->assertSame('BYPASS', $res->headers['X-Kip-Cache']);
    }

    public function test_etag_conditional_get_returns_304(): void
    {
        $this->get('/posts'); // warm (review D5b: no dead variable)
        $etag = $this->get('/posts')->headers['ETag'];
        $res = $this->get('/posts', ['if-none-match' => $etag]);
        $this->assertSame(304, $res->status);
        $this->assertSame('', $res->body);
        $this->assertSame($etag, $res->headers['ETag']); // RFC: ETag re-sent on 304
    }

    public function test_weak_validator_if_none_match_still_matches(): void // review D5d: proxies may weaken tags
    {
        $this->get('/posts');
        $etag = $this->get('/posts')->headers['ETag'];
        $res = $this->get('/posts', ['if-none-match' => 'W/' . $etag]);
        $this->assertSame(304, $res->status);
    }

    public function test_if_none_match_list_containing_the_etag_matches(): void // RFC 9110 §13.1.2
    {
        $this->get('/posts');
        $etag = $this->get('/posts')->headers['ETag'];
        $res = $this->get('/posts', ['if-none-match' => '"stale-one", ' . $etag . ', "stale-two"']);
        $this->assertSame(304, $res->status);
    }

    public function test_if_none_match_wildcard_matches_any_validator(): void // RFC 9110 §13.1.2
    {
        $this->get('/posts');
        $res = $this->get('/posts', ['if-none-match' => '*']);
        $this->assertSame(304, $res->status);
    }

    public function test_if_none_match_list_without_the_etag_does_not_match(): void
    {
        $this->get('/posts');
        $res = $this->get('/posts', ['if-none-match' => '"one", W/"two"']);
        $this->assertSame(200, $res->status);
    }

    public function test_304_carries_the_cache_relevant_headers_of_the_200(): void // RFC 9110 §15.4.5
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\App\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $etag = $app->handle(new Request('GET', '/cache-header-page', [], [], []))->headers['ETag'];
        $res = $app->handle(new Request('GET', '/cache-header-page', [], [], [], '', ['if-none-match' => $etag]));
        $this->assertSame(304, $res->status);
        $this->assertSame($etag, $res->headers['ETag']);
        $this->assertSame('max-age=60', $res->headers['Cache-Control']);
        $this->assertSame('Accept-Encoding', $res->headers['Vary']);
    }

    public function test_session_touching_pages_are_never_cached(): void // review D5f
    {
        $this->assertSame('MISS', $this->get('/auth/login')->headers['X-Kip-Cache']);
        $this->assertSame('MISS', $this->get('/auth/login')->headers['X-Kip-Cache']); // csrfToken() touch → never stored, every hit is a MISS
    }

    public function test_response_with_session_cookie_never_cached(): void // threat model: cache poisoning
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\App\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        // fixture route that sets a cookie header on a guest-cacheable GET
        // ('/cookie-page', not '/cookiepage': the router studly-cases on separators,
        // a bare path resolves to CookiepageController and 404s, testing nothing)
        $res1 = $app->handle(new Request('GET', '/cookie-page', [], [], []));
        $this->assertSame(200, $res1->status); // the fixture actually rendered
        $this->assertSame('MISS', $res1->headers['X-Kip-Cache']);
        $res2 = $app->handle(new Request('GET', '/cookie-page', [], [], []));
        $this->assertSame('MISS', $res2->headers['X-Kip-Cache']); // never stored, never HIT
    }

    public function test_response_with_arbitrary_case_set_cookie_spelling_never_cached(): void // defense: field names are case-insensitive
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\App\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        // Field-name case must not affect the cacheability judgment.
        $res1 = $app->handle(new Request('GET', '/cookie-case-page', [], [], []));
        $this->assertSame(200, $res1->status); // the fixture actually rendered
        $this->assertSame('MISS', $res1->headers['X-Kip-Cache']);
        $res2 = $app->handle(new Request('GET', '/cookie-case-page', [], [], []));
        $this->assertSame('MISS', $res2->headers['X-Kip-Cache']); // never stored, never HIT
    }
    public function test_deferred_writes_purge_cached_pages(): void
    {
        $this->get('/posts');
        $this->get('/posts');
        $this->app->defer(function (): void {
            $this->db->query("UPDATE posts SET title = 'changed' WHERE id = 1");
        });
        $this->app->runDeferred();
        $this->assertSame('MISS', $this->get('/posts')->headers['X-Kip-Cache']); // deferred writes invalidate like in-request ones
    }

    public function test_lowercase_app_etag_and_quoted_commas_survive_comparison(): void
    {
        $first = $this->get('/etag-page');
        $this->assertSame('"a,b"', $first->headers['ETag']); // the app's own tag, not the body hash
        $res = $this->get('/etag-page', ['if-none-match' => '"zz", "a,b"']);
        $this->assertSame(304, $res->status);
    }

    public function test_a_lowercase_app_etag_emits_exactly_one_validator_field(): void
    {
        // RFC 9110 §8.8.3: at most one ETag field. The framework canonicalizes the
        // app's spelling; PHP array keys are case-sensitive, so a naive withHeader
        // would carry both on the wire.
        $keys = fn (\Kip\Http\Response $r) => array_keys(array_filter($r->headers,
            fn (string $n) => strcasecmp($n, 'etag') === 0, ARRAY_FILTER_USE_KEY));

        $this->assertSame(['ETag'], $keys($this->get('/etag-page'))); // the 200

        // Fresh App: the UNCACHED 304 path, where the duplicate also arose (the
        // cached 304 was always clean; PageCache strips case variants on store).
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $res = $app->handle(new Request('GET', '/etag-page', [], [], [], '', ['if-none-match' => '"a,b"']));
        $this->assertSame(304, $res->status);
        $this->assertSame(['ETag'], $keys($res));
    }

    public function test_a_weak_response_etag_matches_a_plain_member(): void
    {
        $this->get('/weak-etag-page');
        $res = $this->get('/weak-etag-page', ['if-none-match' => '"v1"']);
        $this->assertSame(304, $res->status); // weak comparison: both sides drop W/
    }

    public function test_both_etag_spellings_collapse_to_one_canonical_field(): void
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\App\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $res = $app->handle(new Request('GET', '/dual-etag-page', [], [], []));
        $keys = array_keys(array_filter($res->headers,
            fn (string $n) => strcasecmp($n, 'etag') === 0, ARRAY_FILTER_USE_KEY));
        $this->assertSame(['ETag'], $keys); // one field, canonical spelling
        $this->assertSame('"one"', $res->headers['ETag']); // first spelling in header order wins
    }

    public function test_a_get_with_a_body_neither_reads_nor_writes_the_cache(): void
    {
        // RFC 9110 §9.3.1: a GET CAN carry a body. A body-dependent render must
        // not be served a shared entry, and must not store one (codex fold 2:
        // without the empty-body condition, a body-carrying GET poisons and is
        // poisoned by the shared cache). The cache DB is a file so the stored
        // rows can be counted directly.
        $file = tempnam(sys_get_temp_dir(), 'kip-json-cache');
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite:' . $file, 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $app->container->make(Database::class);
        $db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT, created_at TEXT)');
        $db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, author TEXT, body TEXT, created_at TEXT)');
        $db->query("INSERT INTO posts (title, body, created_at) VALUES ('T', 'B', '2026-01-01')");
        $bodied = fn (string $path): Request => new Request('GET', $path, [], [], [], '', [], [], 'filter=x');

        $this->assertSame('MISS', $app->handle(new Request('GET', '/posts', [], [], []))->headers['X-Kip-Cache']); // warm
        $this->assertSame('BYPASS', $app->handle($bodied('/posts'))->headers['X-Kip-Cache'],
            'a body-carrying GET must not be served the warmed entry');

        $cache = new Database('sqlite:' . $file);
        $this->assertCount(1, $cache->all('SELECT key FROM pages'), 'only the warm row exists');

        $app->handle($bodied('/posts/show/1')); // never-warmed path
        $this->assertCount(1, $cache->all('SELECT key FROM pages'),
            'the body-carrying GET on a fresh path stored no entry');
        unlink($file);
    }

    public function test_304_carries_content_location(): void
    {
        // The cache-header fixture lives in this file's namespace, so a second App
        // instance resolves it (same pattern as the header-copy pin above). Vary
        // refuses storage; the conditional still applies on the MISS path.
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\App\\',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $etag = $app->handle(new Request('GET', '/cache-header-page', [], [], []))->headers['ETag'];
        $res = $app->handle(new Request('GET', '/cache-header-page', [], [], [], '', ['if-none-match' => $etag]));
        $this->assertSame(304, $res->status);
        $this->assertSame('/canonical', $res->headers['Content-Location']);
    }

}
