<?php // tests/Blog/QueryBudgetTest.php
namespace Kip\Tests\Blog;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The blog's own controller, loaded directly: no other test declares
// App\Controllers\PostsController, so it cannot collide.
require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/PostsController.php';

/**
 * The performance contract (guide chapter 15) on the tutorial app itself: each
 * guest page runs at most one query against the content database, on the blog's
 * real schema and migrations.
 */
final class QueryBudgetTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        $blog = dirname(__DIR__, 2) . '/examples/blog';
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => $blog . '/app/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, $blog . '/app/migrations'))->migrate();
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('Hello', 'First post', '2026-09-01T10:00:00+00:00')");
        // Inserted out of order: the page must still list them oldest first, ties by id.
        $this->db->query("INSERT INTO comments (post_id, author, body, created_at) VALUES (1, 'Cy', 'third', '2026-09-03T10:00:00+00:00')");
        $this->db->query("INSERT INTO comments (post_id, author, body, created_at) VALUES (1, 'Ann', 'first', '2026-09-02T10:00:00+00:00')");
        $this->db->query("INSERT INTO comments (post_id, author, body, created_at) VALUES (1, 'Bo', 'second', '2026-09-02T10:00:00+00:00')");
    }

    /** @return array{0: \Kip\Http\Response, 1: int} */
    private function render(string $path): array
    {
        $queries = 0;
        $this->db->onQuery(function () use (&$queries): void { $queries++; });
        $res = (new TestClient($this->app))->get($path);
        $this->db->onQuery(static fn () => null);
        return [$res, $queries];
    }

    public function test_post_page_with_comments_is_one_query(): void
    {
        [$res, $queries] = $this->render('/posts/show/1');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries);
        $first = strpos($res->body, 'first');
        $second = strpos($res->body, 'second');
        $third = strpos($res->body, 'third');
        $this->assertNotFalse($first);
        $this->assertTrue($first < $second && $second < $third, 'comments oldest first, ties by id');
    }

    public function test_post_page_without_comments_is_one_query(): void
    {
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('Quiet', 'No replies', '2026-09-04T10:00:00+00:00')");
        [$res, $queries] = $this->render('/posts/show/2');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries);
    }

    public function test_listing_is_one_query_and_uses_an_index_not_a_sort(): void
    {
        [$res, $queries] = $this->render('/posts');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries);
        $plan = implode("\n", array_column($this->db->all(
            'EXPLAIN QUERY PLAN SELECT * FROM posts ORDER BY created_at DESC, id DESC LIMIT 21 OFFSET 0'), 'detail'));
        $this->assertStringNotContainsString('TEMP B-TREE', $plan);
    }
}
