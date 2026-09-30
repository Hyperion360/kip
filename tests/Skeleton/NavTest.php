<?php // tests/Skeleton/NavTest.php
namespace Kip\Tests\Skeleton;

use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/skeleton/app/src/Controllers/HomeController.php';

// Regression: ISSUE-002 — the skeleton nav was static (Home + Log in), so a
// logged-in admin had no visible path to the panel, logs viewer, or SQL
// browser, and saw "Log in" forever. Found by Danilo testing the skeleton
// live, 2026-09-30.
final class NavTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__, 2) . '/skeleton/app/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        (require dirname(__DIR__, 2) . '/skeleton/app/migrations/001_create_users.php')->up($this->db);
        (require dirname(__DIR__, 2) . '/skeleton/app/migrations/003_add_users_is_admin.php')->up($this->db);
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
}
