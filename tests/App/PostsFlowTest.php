<?php // tests/App/PostsFlowTest.php
namespace Kip\Tests\App;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class PostsFlowTest extends TestCase
{
    private App $app;
    private Database $db;
    private \Kip\Testing\TestClient $client;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        $db = $this->db;
        $db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT, created_at TEXT)');
        $db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, author TEXT, body TEXT, created_at TEXT)');
        $db->query('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)', ['First <b>Post</b>', 'Hello', date('c')]);
        $this->client = new \Kip\Testing\TestClient($this->app);
    }

    public function test_index_lists_posts_escaped(): void
    {
        $res = $this->client->get('/posts');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('First &lt;b&gt;Post&lt;/b&gt;', $res->body); // XSS guard
    }

    public function test_show_displays_one_post(): void
    {
        $this->assertStringContainsString('Hello', $this->client->get('/posts/show/1')->body);
    }

    public function test_missing_post_is_404(): void
    {
        $res = $this->app->handle(new Request('GET', '/posts/show/999', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_no_audit_table_leaks_into_main_db(): void // T12c-fix
    {
        $this->app->handle(new Request('GET', '/posts', [], [], []));
        $db = $this->app->container->make(Database::class);
        $this->assertNull($db->one("SELECT name FROM sqlite_master WHERE name = 'requests'"));
    }

    public function test_layout_nav_links_are_present(): void // v0.1.1 T10
    {
        $res = $this->app->handle(new Request('GET', '/posts', [], [], []));
        $this->assertStringContainsString('href="/posts"', $res->body);
        $this->assertStringContainsString('href="/posts/create"', $res->body); // guests get redirected by the #[Auth] gate. Link may always show
    }

    public function test_index_paginates_at_20(): void // v0.2 T6
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->query('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
                ["Post $i", 'b', sprintf('2026-01-%02d', min($i, 28))]);
        }
        $p1 = $this->app->handle(new Request('GET', '/posts', [], [], []));
        $this->assertSame(20, substr_count($p1->body, '<li>'));
        $this->assertStringContainsString('href="/posts?page=2"', $p1->body);
        $p2 = $this->app->handle(new Request('GET', '/posts', ['page' => '2'], [], []));
        $this->assertGreaterThanOrEqual(5, substr_count($p2->body, '<li>'));
        $this->assertStringContainsString('href="/posts?page=1"', $p2->body);
        $p3 = $this->app->handle(new Request('GET', '/posts', ['page' => '-3'], [], []));
        $this->assertSame(20, substr_count($p3->body, '<li>')); // clamps to page 1
    }
}
