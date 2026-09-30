<?php // tests/Admin/AdminSqlBrowserTest.php
namespace Kip\Tests\Admin;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The read-only SQL browser pages (/admin/sql, /admin/schema, /admin/data):
 * the same deny() gate as the CRUD panel, every content read on the
 * engine-read-only handle, server-validated fixed filters, no free-text SQL.
 */
final class AdminSqlBrowserTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kip-browser-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'admin' => ['enabled' => true],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT, is_admin INTEGER NOT NULL DEFAULT 0)');
        $this->db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT, password_hash TEXT, created_at TEXT)');
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('admin@x.com', 'h', 1), ('user@x.com', 'h', 0)");
        $this->db->query("INSERT INTO posts (title, body, password_hash, created_at) VALUES ('P <b>1</b>', 'B', 'SECRETHASH', '2026-01-01')");
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('Alpha 1', 'B', '2026-01-02'), ('Alpha 2', 'B', '2026-01-03'), ('Beta', 'B', '2026-01-04')");
        $this->client = (new TestClient($this->app))->actingAs(1);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) @unlink($f);
    }

    /** @return list<string> */
    private static function pages(): array
    {
        return ['/admin/sql', '/admin/schema/posts', '/admin/data/posts'];
    }

    public function test_guest_is_redirected_on_every_browser_page(): void
    {
        foreach (self::pages() as $url) {
            $this->assertSame(302, (new TestClient($this->app))->get($url)->status, $url);
        }
    }

    public function test_non_admin_gets_403_on_every_browser_page(): void
    {
        foreach (self::pages() as $url) {
            $this->assertSame(403, (new TestClient($this->app))->actingAs(2)->get($url)->status, $url);
        }
    }

    public function test_unknown_table_is_404(): void
    {
        $this->assertSame(404, $this->client->get('/admin/schema/nope')->status);
        $this->assertSame(404, $this->client->get('/admin/data/nope')->status);
    }

    public function test_sql_internals_are_404(): void
    {
        $this->assertSame(404, $this->client->get('/admin/data/sqlite_master')->status);
        $this->assertSame(404, $this->client->get('/admin/schema/sqlite_master')->status);
    }

    public function test_sql_lists_tables_with_counts_and_links(): void
    {
        $res = $this->client->get('/admin/sql');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/admin/schema/posts"', $res->body);
        $this->assertStringContainsString('href="/admin/data/posts"', $res->body);
        $this->assertStringContainsString('href="/admin/schema/users"', $res->body);
        $this->assertStringContainsString('read-only', $res->body); // the contract is stated on the page
    }

    public function test_sql_hides_the_migration_ledger(): void
    {
        $this->db->query('CREATE TABLE _migrations (name TEXT)');
        $body = $this->client->get('/admin/sql')->body;
        $this->assertStringNotContainsString('/admin/data/_migrations', $body);
        $this->assertStringContainsString('/admin/data/posts', $body);
    }

    public function test_schema_shows_columns_and_create_statement(): void
    {
        $res = $this->client->get('/admin/schema/posts');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('title', $res->body);
        $this->assertStringContainsString('TEXT', $res->body);
        $this->assertStringContainsString('CREATE TABLE posts', $res->body);
        $this->assertStringContainsString('4', $res->body); // row count
    }

    public function test_data_renders_rows_escaped_and_hash_masked(): void
    {
        $res = $this->client->get('/admin/data/posts');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('P &lt;b&gt;1&lt;/b&gt;', $res->body); // XSS guard on cell values
        $this->assertStringNotContainsString('SECRETHASH', $res->body);          // masked, never the hash
        $this->assertStringContainsString('password_hash', $res->body);          // the column header stays
        $this->assertStringContainsString('rowid', $res->body);
    }

    public function test_data_paginates_at_50_and_carries_the_filter(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->db->query('INSERT INTO posts (title, created_at) VALUES (?, ?)', ["Post {$i}", '2026-01-05']);
        }
        $p1 = $this->client->get('/admin/data/posts', ['col' => 'title', 'op' => 'LIKE', 'val' => 'Post%']);
        $this->assertSame(200, $p1->status);
        $this->assertStringContainsString('Post 6', $p1->body);
        $this->assertStringNotContainsString('>Post 56<', $p1->body);
        $this->assertStringContainsString('page=2', $p1->body);
        $this->assertStringContainsString('col=title', $p1->body);   // the pager carries the filter
        $p2 = $this->client->get('/admin/data/posts', ['page' => '2', 'col' => 'title', 'op' => 'LIKE', 'val' => 'Post%']);
        $this->assertSame(200, $p2->status);
        $this->assertStringContainsString('Post 1', $p2->body);
        $this->assertStringNotContainsString('>Beta<', $p2->body);   // the filter held across pages
    }

    public function test_huge_page_numbers_are_clamped_not_fatal(): void
    {
        $res = $this->client->get('/admin/data/posts', ['page' => '99999999']);
        $this->assertSame(200, $res->status);
    }

    public function test_fixed_filters_narrow_by_bound_value(): void
    {
        $res = $this->client->get('/admin/data/posts', ['col' => 'title', 'op' => 'LIKE', 'val' => 'Alpha%']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Alpha 1', $res->body);
        $this->assertStringContainsString('Alpha 2', $res->body);
        $this->assertStringNotContainsString('>Beta<', $res->body);
    }

    public function test_a_filter_value_is_literal_data(): void // SQLi pin at the HTTP surface
    {
        $res = $this->client->get('/admin/data/posts', ['col' => 'title', 'op' => '=', 'val' => "x' OR 1=1 --"]);
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('>Alpha 1<', $res->body);
        $this->assertStringNotContainsString('>Beta<', $res->body);
    }

    public function test_invalid_filter_parts_are_422(): void
    {
        $this->assertSame(422, $this->client->get('/admin/data/posts', ['col' => 'password_hash', 'op' => '=', 'val' => 'a'])->status);
        $this->assertSame(422, $this->client->get('/admin/data/posts', ['col' => 'nosuch', 'op' => '=', 'val' => 'a'])->status);
        $this->assertSame(422, $this->client->get('/admin/data/posts', ['col' => 'title', 'op' => 'REGEXP', 'val' => 'a'])->status);
        $this->assertSame(422, $this->client->get('/admin/data/posts', ['col' => "title\"; DROP", 'op' => '=', 'val' => 'a'])->status);
    }

    public function test_memory_databases_get_a_clear_501(): void
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'admin' => ['enabled' => true],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $app->container->make(Database::class);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, is_admin INTEGER NOT NULL DEFAULT 0)');
        $db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $db->query("INSERT INTO users (password_hash, is_admin) VALUES ('h', 1)");
        $client = (new TestClient($app))->actingAs(1);
        foreach (self::pages() as $url) {
            $res = $client->get($url);
            $this->assertSame(501, $res->status, $url);
            $this->assertStringContainsString('file-backed SQLite', $res->body, $url);
        }
    }

    public function test_sql_browser_renders_schema_and_data_links_per_table(): void
    {
        $res = $this->client->get('/admin/sql');
        $this->assertStringContainsString('href="/admin/schema/users"', $res->body);
        $this->assertStringContainsString('>Data</a>', $res->body);
    }

    public function test_schema_view_has_tabs_pk_badge_and_keeps_create_readable(): void
    {
        $res = $this->client->get('/admin/schema/posts');
        $this->assertStringContainsString('CREATE TABLE posts', $res->body); // highlighted types must not break the statement
        $this->assertStringContainsString('kip-sql-t', $res->body);          // type tokens are colored
        $this->assertStringContainsString('>PK</span>', $res->body);
        $this->assertStringContainsString('aria-current="page">Schema</a>', $res->body);
        $this->assertStringContainsString('Edit rows', $res->body);
    }

    public function test_data_view_opens_filter_disclosure_when_active(): void
    {
        $plain = $this->client->get('/admin/data/posts');
        $this->assertStringContainsString('<details class="kip-filters">', $plain->body);
        $this->assertStringNotContainsString('class="kip-filters" open', $plain->body);
        $filtered = $this->client->get('/admin/data/posts', ['col' => 'title', 'op' => 'LIKE', 'val' => 'A%']);
        $this->assertStringContainsString('<details class="kip-filters" open>', $filtered->body);
        $this->assertStringContainsString('title LIKE', $filtered->body); // active-filter summary
    }
}
