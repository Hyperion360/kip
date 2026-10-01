<?php // tests/Blog/NavTest.php
namespace Kip\Tests\Blog;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The blog's own controllers, loaded directly: no other test declares
// App\Controllers\HomeController or App\Controllers\AuthController, so they
// cannot collide.
require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Nav.php';
require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/HomeController.php';
require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/AuthController.php';

// The blog nav must be session-aware: a logged-in admin gets the Admin link
// and Log out, a plain user gets Log out without Admin, a guest gets Log in.
// Mirrors tests/Skeleton/NavTest.php against the example app's controllers.
final class NavTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;

    protected function setUp(): void
    {
        $blog = dirname(__DIR__, 2) . '/examples/blog';
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'admin' => ['enabled' => true],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => $blog . '/app/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, $blog . '/app/migrations'))->migrate();
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('root@x.com', 'h', 1)");
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('plain@x.com', 'h', 0)");
        $this->client = new TestClient($this->app);
    }

    public function test_guest_nav_shows_login_and_no_admin_link(): void
    {
        $body = $this->client->get('/')->body;
        $this->assertStringContainsString('href="/auth/login"', $body);
        $this->assertStringNotContainsString('href="/admin"', $body);
        $this->assertStringNotContainsString('Log out', $body);
    }

    public function test_admin_nav_shows_admin_link_and_logout(): void
    {
        $this->client->actingAs(1);
        $body = $this->client->get('/')->body;
        $this->assertStringContainsString('href="/admin"', $body);
        $this->assertStringContainsString('Log out', $body);
        $this->assertStringNotContainsString('href="/auth/login"', $body);
    }

    public function test_plain_user_nav_shows_logout_without_admin_link(): void
    {
        $this->client->actingAs(2);
        $body = $this->client->get('/')->body;
        $this->assertStringContainsString('Log out', $body);
        $this->assertStringNotContainsString('href="/admin"', $body);
    }

    public function test_nav_is_session_aware_on_the_posts_and_login_pages_too(): void
    {
        $this->client->actingAs(1);
        $this->assertStringContainsString('href="/admin"', $this->client->get('/posts')->body);
        $login = $this->client->get('/auth/login')->body; // logged in: still the admin nav
        $this->assertStringContainsString('href="/admin"', $login);
        $this->assertStringContainsString('name="_token"', $login); // and a renderable form either way
        $guest = (new TestClient($this->app))->get('/auth/login')->body; // logged out: login form token survives the merge
        $this->assertStringContainsString('name="_token"', $guest);
        $this->assertStringContainsString('href="/auth/login"', $guest);
    }
}
