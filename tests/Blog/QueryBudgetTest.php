<?php // tests/Blog/QueryBudgetTest.php
namespace Kip\Tests\Blog;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

// The blog's HomeController shares the App\Controllers namespace with the
// skeleton's, and tests/Skeleton/NavTest.php requires those at file load in
// the main PHPUnit process, so this file requires the blog controllers inside
// setUp() and runs isolated (same reason as blog NavTest).

/**
 * The performance contract (guide chapter 15) on the tutorial app itself: each
 * guest page runs at most one query against the content database, on the blog's
 * real schema and migrations.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class QueryBudgetTest extends TestCase
{
    private App $app;
    private Database $db;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Nav.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Text.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/HomeController.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/PostsController.php';
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

    /**
     * Render $path with $query, count its queries, and fail if any of them plans as a table scan or a
     * sort. The plans come from the SQL the controller actually ran, captured by the tap.
     *
     * @param array<string, string> $query
     * @return array{0: \Kip\Http\Response, 1: int}
     */
    private function render(string $path, array $query = []): array
    {
        $sql = [];
        $this->db->onQuery(function (string $q) use (&$sql): void { $sql[] = $q; });
        $res = (new TestClient($this->app))->get($path, $query);
        $this->db->onQuery(static fn () => null);
        foreach ($sql as $q) {
            $plan = implode("\n", array_column($this->db->all('EXPLAIN QUERY PLAN ' . $q, array_fill(0, substr_count($q, '?'), 1)), 'detail'));
            $this->assertStringNotContainsString('TEMP B-TREE', $plan, $q);
            $this->assertDoesNotMatchRegularExpression('/^SCAN \w+$/m', $plan, "a full scan: {$q}");
        }
        return [$res, count($sql)];
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

    /**
     * Request input is not UTF-8 validated, so a comment can carry invalid bytes.
     * SQLite's json_object() passes them through, and a plain json_decode() of the
     * aggregate then returns null: one bad comment turned the post page into a 500.
     */
    public function test_a_comment_with_invalid_utf8_does_not_break_the_post_page(): void
    {
        $this->db->query("INSERT INTO comments (post_id, author, body, created_at) VALUES (1, ?, 'fourth', '2026-09-05T10:00:00+00:00')",
            ["Dee \xff\xfe"]);
        [$res, $queries] = $this->render('/posts/show/1');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries);
        $this->assertStringContainsString('fourth', $res->body, 'the comment still shows');
        $this->assertStringContainsString('third', $res->body, 'and so do the others');
    }

    public function test_listing_is_one_query_and_uses_an_index_not_a_sort(): void
    {
        [$res, $queries] = $this->render('/posts');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries); // render() also fails it on a scan or a sort
    }

    public function test_home_is_one_query_and_lists_the_three_newest_posts(): void
    {
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('Second', 'B2', '2026-09-02T10:00:00+00:00')");
        $this->db->query("INSERT INTO posts (title, body, created_at) VALUES ('Third', 'B3', '2026-09-03T10:00:00+00:00')");
        [$res, $queries] = $this->render('/');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries);
        $this->assertTrue(strpos($res->body, 'Third') < strpos($res->body, 'Second'), 'newest first');
        // 'First post' is the oldest post's excerpt: the hero h1 above the list
        // already contains 'Hello', so the title string would match too early.
        $this->assertTrue(strpos($res->body, 'Second') < strpos($res->body, 'First post'), 'then older');
        $this->assertStringNotContainsString('Older posts', $res->body, 'home shows three, no pager');
    }

    public function test_page_two_shows_the_newer_posts_link_in_one_query(): void
    {
        for ($i = 2; $i <= 25; $i++) {
            $this->db->query("INSERT INTO posts (title, body, created_at) VALUES (?, 'B', ?)",
                ["Post $i", gmdate('c', time() - $i * 3600)]);
        }
        [$res, $queries] = $this->render('/posts', ['page' => '2']);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertLessThanOrEqual(1, $queries);
        $this->assertStringContainsString('Newer posts', $res->body);
    }
}
