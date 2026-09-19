<?php // tests/Testing/TestClientTest.php
namespace Kip\Tests\Testing;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class TestClientTest extends TestCase
{
    private App $app;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $this->app->container->make(Database::class);
        $db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT, created_at TEXT)');
        $db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, author TEXT, body TEXT, created_at TEXT)');
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $db->query("INSERT INTO users (email, password_hash) VALUES ('t@x.y', 'hashvalue123456')"); // actingAs seeds the epoch from this
        $this->client = new TestClient($this->app);
    }

    public function test_get_returns_response(): void
    {
        $res = $this->client->get('/posts');
        $this->assertSame(200, $res->status);
    }

    public function test_session_persists_across_requests(): void
    {
        $this->client->actingAs(1);
        $res = $this->client->get('/posts/create');
        $this->assertSame(200, $res->status); // #[Auth] passed. Session survived between calls
    }

    public function test_post_with_token_passes_csrf_on_auth_route(): void
    {
        $this->client->actingAs(1);
        $res = $this->client->postWithToken('/posts/store', ['title' => 'T', 'body' => 'B']);
        $this->assertSame(302, $res->status);
    }

    public function test_post_without_token_is_403_on_auth_route(): void
    {
        $this->client->actingAs(1);
        $res = $this->client->post('/posts/store', ['title' => 'T', 'body' => 'B']);
        $this->assertSame(403, $res->status);
    }

    public function test_fresh_client_is_cookieless_guest(): void
    {
        $res = $this->client->get('/posts/create');
        $this->assertSame(302, $res->status); // guest → login redirect, no session leaked from nowhere
    }
}
