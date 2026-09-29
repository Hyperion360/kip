<?php // tests/App/RateLimitFlowTest.php
namespace Kip\Tests\App;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The rate-limiting battery end to end against the fixture ApiController:
 * enforcement sits in App::process() BEFORE routing (a 429 never reaches a
 * controller, a session, or a transaction), only non-GET/HEAD requests pay,
 * and the query budget rules hold (unconfigured apps, unconfigured prefixes,
 * and page renders issue zero limiter queries). Windows here use
 * 1_000_000_000 seconds: the bucket that time() is in never rolls during a
 * test run, so real-clock requests are deterministic and the Retry-After
 * value is exactly bracketable.
 */
final class RateLimitFlowTest extends TestCase
{
    private App $app;
    private TestClient $client;
    private Database $db;

    /** @param array<string, mixed> $over */
    private function makeApp(array $over = []): App
    {
        return new App($over + [
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
            'rate_limit' => ['api' => ['max' => 2, 'window' => 1_000_000_000]],
        ]);
    }

    protected function setUp(): void
    {
        $this->app = $this->makeApp();
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, dirname(__DIR__, 2) . '/skeleton/app/migrations'))->migrate();
        $this->db->query("INSERT INTO users (email, password_hash) VALUES ('u@x.com', 'h')");
        $this->client = new TestClient($this->app);
    }

    public function test_posts_under_the_limit_reach_the_controller(): void
    {
        $res = $this->client->postJson('/api/create', ['n' => 1]);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame('{"received":{"n":1}}', $res->body);
        $this->assertSame(200, $this->client->postJson('/api/create', ['n' => 2])->status, 'max=2: the second POST passes');
    }

    public function test_over_the_limit_is_a_plain_429_with_retry_after(): void
    {
        $this->client->postJson('/api/create', ['n' => 1]);
        $this->client->postJson('/api/create', ['n' => 2]);
        $before = time();
        $res = $this->client->postJson('/api/create', ['n' => 3]);
        $after = time();
        $this->assertSame(429, $res->status);
        $this->assertSame('Too many requests', $res->body);
        // The window bucket time() sits in starts at 1_000_000_000, so the
        // wait is exactly 2_000_000_000 - now, bracketed by the sampling.
        $retry = (int) $res->headers['Retry-After'];
        $this->assertGreaterThanOrEqual(2_000_000_000 - $after, $retry);
        $this->assertLessThanOrEqual(2_000_000_000 - $before, $retry);
        $this->assertSame(429, $this->client->postJson('/api/create', ['n' => 4])->status, 'still over the limit');
    }

    public function test_max_zero_blocks_the_first_post(): void
    {
        $app = $this->makeApp(['rate_limit' => ['api' => ['max' => 0, 'window' => 60]]]);
        (new Migrator($app->container->make(Database::class), dirname(__DIR__, 2) . '/skeleton/app/migrations'))->migrate();
        $res = (new TestClient($app))->postJson('/api/create', ['n' => 1]);
        $this->assertSame(429, $res->status);
        $this->assertArrayHasKey('Retry-After', $res->headers);
    }

    public function test_rate_limiting_precedes_csrf(): void
    {
        // A tokenless POST from a logged-in session fails CSRF (403) while
        // under the limit; once over, the same request is 429, proving the
        // counter gate runs first.
        $client = $this->client->actingAs(1);
        $this->assertSame(403, $client->postJson('/api/secret', ['x' => 1])->status);
        $this->assertSame(403, $client->postJson('/api/secret', ['x' => 1])->status);
        $this->assertSame(429, $client->postJson('/api/secret', ['x' => 1])->status);
    }

    public function test_rate_limiting_precedes_routing(): void
    {
        // The route does not exist, so the first two requests 404, but they
        // still consume budget: the limiter judges the URL prefix, not the
        // matched route, and the third is a 429 before the router runs.
        $this->assertSame(404, $this->client->postJson('/api/nonexistent', ['x' => 1])->status);
        $this->assertSame(404, $this->client->postJson('/api/nonexistent', ['x' => 1])->status);
        $this->assertSame(429, $this->client->postJson('/api/nonexistent', ['x' => 1])->status);
    }

    public function test_put_requests_count_like_posts(): void
    {
        $this->assertSame(200, $this->client->request('PUT', '/api/replace')->status);
        $this->assertSame(200, $this->client->request('PUT', '/api/replace')->status);
        $this->assertSame(429, $this->client->request('PUT', '/api/replace')->status);
    }

    public function test_gets_and_heads_never_touch_the_counter(): void
    {
        $queries = 0;
        $this->db->onQuery(function () use (&$queries): void { $queries++; });
        $this->assertSame(200, $this->client->get('/api/list')->status);
        $this->assertSame(200, $this->client->request('HEAD', '/api/list')->status);
        $this->assertSame(0, $queries, 'page renders pay nothing, even on a configured prefix');
        $this->db->onQuery(static fn () => null);
    }

    public function test_posts_to_an_unconfigured_prefix_pay_nothing(): void
    {
        $app = $this->makeApp(['rate_limit' => ['auth' => ['max' => 2, 'window' => 60]]]);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = (new TestClient($app))->postJson('/api/create', ['n' => 1]);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame(0, $queries, 'an unconfigured prefix resolves in PHP, the database is never asked');
        $db->onQuery(static fn () => null);
    }

    public function test_an_app_without_rate_limit_config_issues_no_queries_for_a_post(): void
    {
        $app = $this->makeApp();
        $config = $app->config('rate_limit'); // the shared makeApp config; rebuild without it
        $this->assertNotNull($config);
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = (new TestClient($app))->postJson('/api/create', ['n' => 1]);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame(0, $queries, 'an unconfigured app never constructs a limiter query');
        $db->onQuery(static fn () => null);
    }

    public function test_an_expired_window_allows_again(): void
    {
        // A row from a window that has fully elapsed (start 0, the bucket
        // 0..1_000_000_000) must not block: the request opens the CURRENT
        // bucket fresh.
        $this->db->query('INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?, ?, ?, ?)',
            ['Api', '127.0.0.1', 0, 99]);
        $res = $this->client->postJson('/api/create', ['n' => 1]);
        $this->assertSame(200, $res->status, $res->body);
        $row = $this->db->one('SELECT hits FROM rate_limits WHERE prefix = ? AND ip = ? AND window_start = ?',
            ['Api', '127.0.0.1', 1_000_000_000]);
        $this->assertNotNull($row, 'the current bucket got its own row');
        $this->assertSame(1, (int) $row['hits'], 'a new window is a new bucket');
    }

    public function test_rate_limit_config_without_a_database_is_a_boot_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('db.dsn');
        new App(['rate_limit' => ['api' => ['max' => 2, 'window' => 60]]]);
    }

    // ------------------------------------------------------ cache interplay

    public function test_limiter_writes_never_purge_but_app_upserts_still_do(): void
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
            'rate_limit' => ['api' => ['max' => 100, 'window' => 1_000_000_000]],
        ]);
        $db = $app->container->make(Database::class);
        (new Migrator($db, dirname(__DIR__, 2) . '/skeleton/app/migrations'))->migrate();
        $db->query('CREATE TABLE things (id INTEGER PRIMARY KEY, n INTEGER NOT NULL)');
        $client = new TestClient($app);

        // A page that reads the counter table caches normally and is tagged
        // rate_limits.
        $this->assertSame('MISS', $client->get('/rate-limits')->headers['X-Kip-Cache']);
        $this->assertSame('HIT', $client->get('/rate-limits')->headers['X-Kip-Cache']);

        // Counted POSTs write rate_limits on every request; those writes are
        // bookkeeping and must not churn a page tagged with the table.
        $this->assertSame(200, $client->postJson('/api/create', ['x' => 1])->status);
        $this->assertSame(200, $client->postJson('/api/create', ['x' => 2])->status);
        $this->assertSame('HIT', $client->get('/rate-limits')->headers['X-Kip-Cache'],
            'the limiter upsert never purges cached pages');

        // An app upsert in the performance-contract idiom (ON CONFLICT ...
        // DO UPDATE SET) still purges the page reading its table: the SET
        // keyword exemption must not swallow real write tags.
        $this->assertSame('MISS', $client->get('/rate-limits/things')->headers['X-Kip-Cache']);
        $this->assertSame('HIT', $client->get('/rate-limits/things')->headers['X-Kip-Cache']);
        $this->assertSame(200, $client->post('/rate-limits/store')->status);
        $this->assertSame('MISS', $client->get('/rate-limits/things')->headers['X-Kip-Cache'],
            'a real content upsert still purges');
    }
}
