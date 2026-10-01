<?php // tests/Blog/NavTest.php
namespace Kip\Tests\Blog;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

// The blog's controllers share the App\Controllers namespace with the
// skeleton's, and tests/Skeleton/NavTest.php requires those at file load in
// the main PHPUnit process, so this file requires its controllers inside
// setUp() and runs isolated.
//
// The blog nav must be session-aware: a logged-in admin gets the Admin link
// and Log out, a plain user gets Log out without Admin, a guest gets Log in.
// Mirrors tests/Skeleton/NavTest.php against the example app's controllers.
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class NavTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Text.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Nav.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/HomeController.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/AuthController.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/PostsController.php';
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

    public function test_frame_carries_the_theme_cookie_and_path(): void
    {
        $this->client->cookie('kip_theme', 'dark');
        $body = $this->client->get('/')->body;
        $this->assertStringContainsString('data-theme="dark"', $body);
    }

    public function test_an_invalid_theme_cookie_renders_auto(): void
    {
        $this->client->cookie('kip_theme', 'javascript:alert(1)');
        $body = $this->client->get('/')->body;
        $this->assertStringNotContainsString('data-theme', $body);
    }

    public function test_the_theme_switch_marks_the_active_choice(): void
    {
        $guest = $this->client->get('/')->body;
        $this->assertStringContainsString('value="auto" aria-pressed="true"', $guest);
        $this->client->cookie('kip_theme', 'dark');
        $dark = $this->client->get('/')->body;
        $this->assertStringContainsString('value="dark" aria-pressed="true"', $dark);
        $this->assertStringContainsString('value="auto" aria-pressed="false"', $dark);
    }

    public function test_the_theme_form_carries_a_valid_back_target(): void
    {
        $body = $this->client->get('/posts', ['page' => '2'])->body;
        $this->assertStringContainsString('name="back" value="/posts?page=2"', $body);
    }
}
