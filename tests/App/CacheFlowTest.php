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
        $res1 = $app->handle(new Request('GET', '/cookiepage/index', [], [], []));
        $this->assertSame('MISS', $res1->headers['X-Kip-Cache']);
        $res2 = $app->handle(new Request('GET', '/cookiepage/index', [], [], []));
        $this->assertSame('MISS', $res2->headers['X-Kip-Cache']); // never stored, never HIT
    }
}
