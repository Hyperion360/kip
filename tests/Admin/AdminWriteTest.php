<?php // tests/Admin/AdminWriteTest.php
namespace Kip\Tests\Admin;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AdminWriteTest extends TestCase
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
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('admin@x.com', 'ORIGINALHASH', 1)");
        $this->client = (new TestClient($this->app))->actingAs(1);
    }

    public function test_create_form_renders_columns(): void
    {
        $res = $this->client->get('/admin/create/posts');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('name="title"', $res->body);
        $this->assertStringContainsString('name="body"', $res->body);
        $this->assertStringNotContainsString('name="id"', $res->body);         // pk never editable
        $this->assertStringNotContainsString('name="created_at"', $res->body); // *_at auto-managed
    }

    public function test_store_inserts_and_redirects(): void
    {
        $res = $this->client->postWithToken('/admin/store/posts', ['title' => 'Hi', 'body' => 'B']);
        $this->assertSame(302, $res->status);
        $row = $this->db->one('SELECT * FROM posts WHERE title = ?', ['Hi']);
        $this->assertNotNull($row);
        $this->assertNotSame('', (string) $row['created_at']);                 // auto-filled
    }

    public function test_store_without_token_is_403(): void
    {
        $this->assertSame(403, $this->client->post('/admin/store/posts', ['title' => 'x'])->status);
        $this->assertNull($this->db->one('SELECT * FROM posts WHERE title = ?', ['x']));
    }

    public function test_store_missing_required_field_is_422(): void
    {
        $this->assertSame(422, $this->client->postWithToken('/admin/store/posts', ['title' => '', 'body' => 'B'])->status);
    }

    public function test_update_edits_row(): void
    {
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('Old', 'B', '2026-01-01')");
        $res = $this->client->postWithToken('/admin/update/posts/1', ['title' => 'New', 'body' => 'B2']);
        $this->assertSame(302, $res->status);
        $this->assertSame('New', $this->db->one('SELECT title FROM posts WHERE id = 1')['title']);
        $this->assertSame('2026-01-01', $this->db->one('SELECT created_at FROM posts WHERE id = 1')['created_at']); // untouched
    }

    public function test_delete_removes_row(): void
    {
        $this->db->query("INSERT INTO posts (title, created_at) VALUES ('Doomed', '2026-01-01')");
        $res = $this->client->postWithToken('/admin/delete/posts/1', []);
        $this->assertSame(302, $res->status);
        $this->assertNull($this->db->one('SELECT * FROM posts WHERE id = 1'));
    }

    public function test_delete_has_a_zero_js_confirmation_step(): void // destructive actions are two-click
    {
        $this->db->query("INSERT INTO posts (title, created_at) VALUES ('Guarded', '2026-01-01')");
        $confirm = $this->client->get('/admin/confirmdelete/posts/1');
        $this->assertSame(200, $confirm->status);
        $this->assertStringContainsString('action="/admin/delete/posts/1"', $confirm->body);
        $this->assertNotNull($this->db->one('SELECT * FROM posts WHERE id = 1')); // GET alone deletes nothing
        $this->assertSame(404, $this->client->get('/admin/confirmdelete/posts/999')->status);
    }

    public function test_password_hash_never_rendered_and_blank_keeps_hash(): void
    {
        $form = $this->client->get('/admin/edit/users/1');
        $this->assertStringNotContainsString('ORIGINALHASH', $form->body);
        $this->client->postWithToken('/admin/update/users/1', ['email' => 'admin@x.com', 'password_hash' => '', 'is_admin' => '1']);
        $this->assertSame('ORIGINALHASH', $this->db->one('SELECT password_hash FROM users WHERE id = 1')['password_hash']);
    }

    public function test_nonblank_password_is_rehashed(): void
    {
        $this->client->postWithToken('/admin/update/users/1', ['email' => 'admin@x.com', 'password_hash' => 'newpw123', 'is_admin' => '1']);
        $hash = $this->db->one('SELECT password_hash FROM users WHERE id = 1')['password_hash'];
        $this->assertTrue(password_verify('newpw123', $hash));
    }

    public function test_checkbox_absent_means_zero(): void
    {
        $this->client->postWithToken('/admin/update/users/1', ['email' => 'admin@x.com', 'password_hash' => '']); // is_admin unchecked
        $this->assertSame(0, (int) $this->db->one('SELECT is_admin FROM users WHERE id = 1')['is_admin']);
    }

    public function test_edit_missing_row_is_404(): void
    {
        $this->assertSame(404, $this->client->get('/admin/edit/posts/999')->status);
    }

    public function test_update_missing_row_is_404_and_writes_nothing(): void
    {
        $this->assertSame(404, $this->client->postWithToken('/admin/update/posts/999', ['title' => 'x'])->status);
        $this->assertNull($this->db->one('SELECT * FROM posts WHERE id = 999'));
    }

    public function test_store_users_with_blank_password_is_422(): void
    {
        $this->assertSame(422, $this->client->postWithToken('/admin/store/users', ['email' => 'x@y.z', 'password_hash' => ''])->status);
        $this->assertNull($this->db->one('SELECT * FROM users WHERE email = ?', ['x@y.z']));
    }

    public function test_store_on_table_with_no_editable_columns_is_422(): void // INSERT () () is invalid SQL
    {
        $this->db->query('CREATE TABLE blobs_only (id INTEGER PRIMARY KEY, payload BLOB)');
        $this->assertSame(422, $this->client->postWithToken('/admin/store/blobs_only', [])->status);
    }
}
