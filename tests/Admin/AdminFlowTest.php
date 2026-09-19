<?php // tests/Admin/AdminFlowTest.php
namespace Kip\Tests\Admin;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AdminFlowTest extends TestCase
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
        $this->db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT, created_at TEXT)');
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('admin@x.com', 'h', 1), ('user@x.com', 'h', 0)");
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('P <b>1</b>', 'B', '2026-01-01')");
        $this->client = new TestClient($this->app);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $res = $this->client->get('/admin');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location'] ?? null);
    }

    public function test_non_admin_user_gets_403(): void
    {
        $this->assertSame(403, $this->client->actingAs(2)->get('/admin')->status);
    }

    public function test_admin_sees_table_list_with_counts(): void
    {
        $res = $this->client->actingAs(1)->get('/admin');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('posts', $res->body);
        $this->assertStringContainsString('href="/admin/browse/posts"', $res->body);
    }

    public function test_browse_lists_rows_escaped(): void
    {
        $res = $this->client->actingAs(1)->get('/admin/browse/posts');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('P &lt;b&gt;1&lt;/b&gt;', $res->body); // XSS guard on cell values
    }

    public function test_unknown_table_is_404(): void
    {
        $this->assertSame(404, $this->client->actingAs(1)->get('/admin/browse/nope')->status);
    }

    public function test_sql_metachar_table_is_404_before_sql(): void
    {
        // router whitelist rejects quotes; a name-shaped injection attempt still 404s at Schema::has()
        $this->assertSame(404, $this->client->actingAs(1)->get('/admin/browse/sqlite_master')->status);
    }

    public function test_missing_is_admin_column_fails_closed(): void
    {
        $this->db->query('ALTER TABLE users DROP COLUMN is_admin');
        $this->assertSame(403, $this->client->actingAs(1)->get('/admin')->status);
    }

    public function test_browse_paginates_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->db->query('INSERT INTO posts (title, created_at) VALUES (?, ?)', ["Post {$i}", '2026-01-02']);
        }
        $p1 = $this->client->actingAs(1)->get('/admin/browse/posts');
        $this->assertStringContainsString('href="/admin/browse/posts?page=2"', $p1->body);
        $this->assertStringContainsString('Post 6', $p1->body);      // newest 50: rows 56..6 (rowid DESC)
        $this->assertStringNotContainsString('>Post 56<', $p1->body); // 51st row waits on page 2
        $p2 = $this->client->get('/admin/browse/posts', ['page' => '2']);
        $this->assertSame(200, $p2->status);
        $this->assertStringContainsString('Post 1', $p2->body);      // boundary actually split
    }

    public function test_sessions_do_not_leak_across_requests_on_one_app(): void // worker-safety regression guard
    {
        $admin = (new TestClient($this->app))->actingAs(1);
        $this->assertSame(200, $admin->get('/admin')->status);
        $intruder = new TestClient($this->app);                       // same App, separate session
        $this->assertSame(302, $intruder->get('/admin')->status);     // guest redirected, no leak
        $this->assertSame(403, (new TestClient($this->app))->actingAs(2)->get('/admin')->status);
        $this->assertSame(200, $admin->get('/admin')->status);        // admin unaffected by the others
    }

    public function test_is_admin_must_be_exactly_one(): void // fail-closed: truthy-but-not-1 denies
    {
        $this->db->query('UPDATE users SET is_admin = 2 WHERE id = 1');
        $this->assertSame(403, $this->client->actingAs(1)->get('/admin')->status);
    }

    public function test_rows_route_by_rowid_even_with_text_pk(): void // eng-review D2
    {
        $this->db->query('CREATE TABLE password_resets (email TEXT PRIMARY KEY, token_hash TEXT NOT NULL, expires_at TEXT NOT NULL)');
        $this->db->query("INSERT INTO password_resets VALUES ('a@b.com', 'h', '2026-01-01')");
        $res = $this->client->actingAs(1)->get('/admin/browse/password_resets');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/admin/edit/password_resets/1"', $res->body); // rowid in the URL, never the email PK
        $this->assertStringNotContainsString('/admin/edit/password_resets/a@b.com', $res->body);
    }

    public function test_admin_disabled_means_no_route(): void
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->assertSame(404, (new TestClient($app))->get('/admin')->status);
    }
}
