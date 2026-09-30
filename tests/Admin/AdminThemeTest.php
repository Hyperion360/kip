<?php // tests/Admin/AdminThemeTest.php
namespace Kip\Tests\Admin;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * POST /admin/theme: the zero-JS appearance switch. Value whitelist, return-path
 * whitelist, CSRF, and the cookie contract (auto = cleared, forced = one year).
 */
final class AdminThemeTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'admin' => ['enabled' => true],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT, is_admin INTEGER NOT NULL DEFAULT 0)');
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('admin@x.com', 'h', 1)");
        $this->client = (new TestClient($this->app))->actingAs(1);
    }

    public function test_post_sets_cookie_and_redirects_back(): void
    {
        $res = $this->client->postWithToken('/admin/theme', ['theme' => 'dark', 'back' => '/admin/browse/posts']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/admin/browse/posts', $res->headers['Location'] ?? null);
        $this->assertStringContainsString('kip_theme=dark', $res->headers['Set-Cookie'] ?? '');
        $this->assertStringContainsString('SameSite=Lax', $res->headers['Set-Cookie'] ?? '');
    }

    public function test_auto_clears_the_cookie(): void
    {
        $res = $this->client->postWithToken('/admin/theme', ['theme' => 'auto', 'back' => '/admin']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('kip_theme=;', $res->headers['Set-Cookie'] ?? '');
        $this->assertStringContainsString('Max-Age=0', $res->headers['Set-Cookie'] ?? '');
    }

    public function test_invalid_value_and_offsite_back_fall_back_safely(): void
    {
        $res = $this->client->postWithToken('/admin/theme', ['theme' => 'neon', 'back' => 'https://evil.example/']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/admin', $res->headers['Location'] ?? null);
        $this->assertStringContainsString('Max-Age=0', $res->headers['Set-Cookie'] ?? '');
    }

    public function test_back_with_control_bytes_falls_back_not_500s(): void
    {
        $res = $this->client->postWithToken('/admin/theme', ['theme' => 'light', 'back' => "/admin\r\nSet-Cookie: x=1"]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/admin', $res->headers['Location'] ?? null);
    }

    public function test_theme_without_token_is_403_for_an_admin(): void
    {
        $this->assertSame(403, $this->client->post('/admin/theme', ['theme' => 'dark'])->status);
    }

    public function test_guest_is_redirected_not_answered(): void
    {
        $this->assertSame(302, (new TestClient($this->app))->post('/admin/theme', ['theme' => 'dark'])->status);
    }
}
