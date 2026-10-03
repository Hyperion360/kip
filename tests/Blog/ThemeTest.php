<?php // tests/Blog/ThemeTest.php
namespace Kip\Tests\Blog;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

// The blog's controllers share the App\Controllers namespace with the
// skeleton's, so this file requires its controller inside setUp() and runs
// isolated (same reason as NavTest).
//
// POST /theme is the public appearance switcher from the footer form: sets or
// clears the site-wide kip_theme cookie, then redirects back. Cross-site
// POSTs are rejected by the kernel's same-origin proof; the endpoint writes
// one whitelisted cookie and no other state.
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ThemeTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Nav.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Text.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/HomeController.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/ThemeController.php';
        $blog = dirname(__DIR__, 2) . '/examples/blog';
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => $blog . '/app/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, $blog . '/app/migrations'))->migrate();
        $this->client = new TestClient($this->app);
    }

    public function test_a_guest_can_pick_dark(): void
    {
        $res = $this->client->post('/theme', ['theme' => 'dark', 'back' => '/posts']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/posts', $res->headers['Location'] ?? null);
        $cookie = $res->headers['Set-Cookie'] ?? '';
        $this->assertStringContainsString('kip_theme=dark', $cookie);
        $this->assertStringContainsString('Path=/', $cookie);
        $this->assertStringContainsString('Max-Age=31536000', $cookie);
        $this->assertStringContainsString('SameSite=Lax; HttpOnly', $cookie);
    }

    public function test_https_request_sets_a_secure_theme_cookie(): void
    {
        $this->client->secure(true);
        $res = $this->client->post('/theme', ['theme' => 'dark', 'back' => '/']);
        $this->assertStringEndsWith('; SameSite=Lax; HttpOnly; Secure', $res->headers['Set-Cookie']);
    }

    public function test_the_cookie_round_trips_into_data_theme(): void
    {
        $this->client->post('/theme', ['theme' => 'dark', 'back' => '/']);
        $body = $this->client->get('/')->body;
        $this->assertStringContainsString('data-theme="dark"', $body);
    }

    public function test_auto_deletes_the_cookie(): void
    {
        $this->client->cookie('kip_theme', 'dark');
        $res = $this->client->post('/theme', ['theme' => 'auto', 'back' => '/']);
        $this->assertStringContainsString('Max-Age=0', $res->headers['Set-Cookie'] ?? '');
        $body = $this->client->get('/')->body;
        $this->assertStringNotContainsString('data-theme', $body);
    }

    public function test_an_unknown_value_falls_back_to_auto(): void
    {
        $res = $this->client->post('/theme', ['theme' => 'sepia', 'back' => '/']);
        $this->assertStringContainsString('Max-Age=0', $res->headers['Set-Cookie'] ?? '');
    }

    public function test_back_keeps_a_query_string_on_allowed_pages(): void
    {
        $res = $this->client->post('/theme', ['theme' => 'light', 'back' => '/posts?page=2']);
        $this->assertSame('/posts?page=2', $res->headers['Location'] ?? null);
    }

    public function test_back_to_a_post_page_is_allowed(): void
    {
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('T', 'B', '2026-09-01T10:00:00+00:00')");
        $res = $this->client->post('/theme', ['theme' => 'light', 'back' => '/posts/show/1']);
        $this->assertSame('/posts/show/1', $res->headers['Location'] ?? null);
    }

    public function test_back_whitelist_rejects_everything_else(): void
    {
        $cases = [
            '/posts/store' => '/',          // POST-only endpoint
            '/comments/store/1' => '/',     // POST-only endpoint
            '/auth/attempt' => '/',         // POST-only endpoint
            '/admin' => '/',                // not this app's page
            'https://evil.example/x' => '/',// scheme
            '/posts/show/1?x=/posts/show/1' => '/posts/show/1?x=/posts/show/1', // allowed page + query
        ];
        foreach ($cases as $back => $expected) {
            $res = $this->client->post('/theme', ['theme' => 'light', 'back' => $back]);
            $this->assertSame($expected, $res->headers['Location'] ?? null, "back={$back}");
        }
    }

    public function test_dot_segments_and_crlf_in_back_fall_back_to_root(): void
    {
        foreach (['/posts/show/../../etc', "/posts\r\nSet-Cookie: x=y"] as $back) {
            $res = $this->client->post('/theme', ['theme' => 'light', 'back' => $back]);
            $this->assertSame('/', $res->headers['Location'] ?? null);
        }
    }

    public function test_a_forged_post_without_csrf_is_accepted(): void
    {
        // Headerless clients (no Origin/Referer/Sec-Fetch-Site) pass the
        // kernel's same-origin proof by its README-documented accepted risk
        // for guest lanes with no ambient authority. TestClient is such a
        // client; the cross-site case above proves the gate itself.
        $res = $this->client->post('/theme', ['theme' => 'dark', 'back' => '/']);
        $this->assertSame(302, $res->status);
    }

    public function test_a_cross_site_post_is_rejected_by_the_kernel(): void
    {
        // The kernel's same-origin proof (src/App.php:476) gates every
        // unauthenticated POST: an Origin that does not match the Host is 403
        // before the controller runs.
        $res = $this->client->post('/theme', ['theme' => 'dark', 'back' => '/'],
            ['origin' => 'https://evil.example']);
        $this->assertSame(403, $res->status);
    }

    public function test_the_endpoint_never_touches_the_database(): void
    {
        $queries = 0;
        $this->db->onQuery(function () use (&$queries): void { $queries++; });
        $this->client->post('/theme', ['theme' => 'dark', 'back' => '/']);
        $this->db->onQuery(static fn () => null);
        $this->assertSame(0, $queries);
    }
}
