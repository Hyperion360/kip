<?php // tests/App/CommentsFlowTest.php
namespace Kip\Tests\App;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class CommentsFlowTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        $this->db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT, created_at TEXT)');
        $this->db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, author TEXT, body TEXT, created_at TEXT)');
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('T', 'B', '2026-01-01')");
    }

    private function post(array $data): \Kip\Http\Response
    {
        return $this->app->handle(new Request('POST', '/comments/store/1', [], $data, [], '', ['sec-fetch-site' => 'same-origin']));
    }

    public function test_valid_comment_is_saved_and_redirects(): void
    {
        $res = $this->post(['author' => 'Dan', 'body' => 'Nice!']);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM comments')['c']);
    }

    public function test_blank_body_is_rejected_with_422(): void
    {
        $res = $this->post(['author' => 'Dan', 'body' => '  ']);
        $this->assertSame(422, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM comments')['c']);
    }

    public function test_comment_on_missing_post_is_404(): void
    {
        $data = ['author' => 'Dan', 'body' => 'x', '_token' => $this->app->session->csrfToken()];
        $res = $this->app->handle(new Request('POST', '/comments/store/999', [], $data, []));
        $this->assertSame(404, $res->status);
    }
}
