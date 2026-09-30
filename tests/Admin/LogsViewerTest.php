<?php // tests/Admin/LogsViewerTest.php
namespace Kip\Tests\Admin;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class LogsViewerTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;
    private string $logFile;
    private \PDO $logPdo;

    protected function setUp(): void
    {
        // App owns its log Database internally; a file DSN is how the test
        // reaches the same database to seed rows and inspect the result.
        $this->logFile = tempnam(sys_get_temp_dir(), 'kip-logdb-');
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'log_db' => ['dsn' => 'sqlite:' . $this->logFile, 'retention_days' => 30],
            'admin' => ['enabled' => true],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT, is_admin INTEGER NOT NULL DEFAULT 0)');
        $this->db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('admin@x.com', 'h', 1), ('user@x.com', 'h', 0)");
        $this->logPdo = new \PDO('sqlite:' . $this->logFile);
        $this->client = new TestClient($this->app);
    }

    protected function tearDown(): void
    {
        unset($this->logPdo, $this->app); // release the file before unlink (Windows-safe habit)
        @unlink($this->logFile);
    }

    /** @param list<array{0:string,1:string,2:int,3:?int}> $rows [method, path, status, user_id] */
    private function seed(array $rows): void
    {
        $st = $this->logPdo->prepare('INSERT INTO requests (created_at, method, path, status, duration_ms, ip, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $i => [$m, $p, $s, $u]) {
            $st->execute([date('c', time() - count($rows) + $i), $m, $p, $s, 1.5, '127.0.0.1', $u]);
        }
    }

    /** @return list<array{0:string,1:string,2:int,3:?int}> */
    public static function matrix(): array
    {
        return [
            ['GET', '/api/users', 200, 7],
            ['POST', '/api/users', 422, null],
            ['GET', '/posts', 200, null],
            ['GET', '/api/orders', 500, 7],
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $res = $this->client->get('/admin/logs');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location'] ?? null);
    }

    public function test_non_admin_user_gets_403(): void
    {
        $this->assertSame(403, $this->client->actingAs(2)->get('/admin/logs')->status);
    }

    public function test_admin_sees_rows_newest_first_and_panel_link(): void
    {
        $this->seed(self::matrix());
        $res = $this->client->actingAs(1)->get('/admin/logs');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/admin/logs"', $res->body); // header entry point (fold 11)
        foreach (['created_at', 'method', 'path', 'status', 'duration_ms', 'ip', 'user_id'] as $col) {
            $this->assertStringContainsString($col, $res->body);
        }
        $this->assertGreaterThan(strpos($res->body, '/api/orders'), strpos($res->body, '/api/users')); // newest first
    }

    public function test_filters_return_correct_subsets(): void
    {
        $this->seed(self::matrix());
        $admin = $this->client->actingAs(1);
        $body = fn(array $q): string => $admin->get('/admin/logs', $q)->body;

        $b = $body(['method' => 'POST']);
        $this->assertStringContainsString('422', $b);
        $this->assertStringNotContainsString('/posts', $b);
        $this->assertStringNotContainsString('>500<', $b); // status cell only: a bare '500' also matches the frame CSS (font-weight:500)

        $b = $body(['status' => '5']);
        $this->assertStringContainsString('/api/orders', $b);
        $this->assertStringNotContainsString('/posts', $b);
        $this->assertStringNotContainsString('422', $b);

        $b = $body(['path' => '/api']);
        $this->assertStringContainsString('422', $b);   // /api/users POST
        $this->assertStringContainsString('500', $b);   // /api/orders GET
        $this->assertStringNotContainsString('/posts', $b);

        $b = $body(['user_id' => '7']);
        $this->assertStringContainsString('/api/orders', $b);
        $this->assertStringNotContainsString('422', $b);
        $this->assertStringNotContainsString('/posts', $b);

        $b = $body(['guests' => '1']);
        $this->assertStringContainsString('/posts', $b);
        $this->assertStringNotContainsString('/api/orders', $b);
    }

    public function test_active_filters_open_the_disclosure_and_are_summarized(): void
    {
        $seed = $this->client->actingAs(1);
        $plain = $seed->get('/admin/logs');
        $this->assertStringContainsString('<details class="kip-filters">', $plain->body);
        $this->assertStringNotContainsString('class="kip-filters" open', $plain->body);
        $filtered = $seed->get('/admin/logs', ['status' => '4']);
        $this->assertStringContainsString('<details class="kip-filters" open>', $filtered->body);
        $this->assertStringContainsString('4xx', $filtered->body);
    }

    public function test_status_chips_reflect_the_class(): void
    {
        $this->seed([['GET', '/ok', 200, null], ['GET', '/warn', 404, null]]);
        $body = $this->client->actingAs(1)->get('/admin/logs')->body;
        $this->assertStringContainsString('>200</span>', $body);
        $this->assertStringContainsString('>404</span>', $body);
    }

    public function test_stored_and_reflected_values_are_escaped(): void // fold 1: XSS pins
    {
        $this->seed([['GET', '<script>alert(1)</script>', 200, null]]);
        $res = $this->client->actingAs(1)->get('/admin/logs');
        $this->assertStringNotContainsString('<script>', $res->body);      // stored path escaped
        $this->assertStringContainsString('&lt;script&gt;', $res->body);

        $res = $this->client->actingAs(1)->get('/admin/logs', ['path' => '<svg onload=alert(1)>']);
        $this->assertStringNotContainsString('<svg', $res->body);          // reflected filter escaped
        $this->assertStringContainsString('&lt;svg onload=alert(1)&gt;', $res->body);
    }

    public function test_zero_rows_render_empty_with_newest_link(): void
    {
        $res = $this->client->actingAs(1)->get('/admin/logs', ['method' => 'GET']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Newest', $res->body);
        $this->assertStringNotContainsString('Next', $res->body);
        $this->assertStringNotContainsString('Prev', $res->body);
    }

    public function test_fifty_rows_is_exactly_one_page(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; $i++) { $rows[] = ['GET', "r{$i}", 200, null]; }
        $this->seed($rows);
        $res = $this->client->actingAs(1)->get('/admin/logs');
        $this->assertStringContainsString('>r1<', $res->body);  // oldest of the 50 shown
        $this->assertStringContainsString('>r50<', $res->body);
        $this->assertStringNotContainsString('Next', $res->body);
    }

    public function test_fifty_one_rows_page_two_holds_the_one_leftover(): void
    {
        $rows = [];
        for ($i = 1; $i <= 51; $i++) { $rows[] = ['GET', "r{$i}", 200, null]; }
        $this->seed($rows);
        $p1 = $this->client->actingAs(1)->get('/admin/logs');
        $this->assertStringNotContainsString('>r1<', $p1->body);           // 51st row waits on page two
        $this->assertMatchesRegularExpression('#href="/admin/logs\?before=2"#', $p1->body); // cursor = oldest shown id
        $p2 = $this->client->get('/admin/logs', ['before' => '2']);
        $this->assertSame(200, $p2->status);
        $this->assertStringContainsString('>r1<', $p2->body);
        $this->assertStringNotContainsString('Next', $p2->body);
    }

    public function test_walk_120_rows_forward_and_back(): void // fold 3 + 6: no gaps, no duplicates
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) { $rows[] = ['GET', "r{$i}", 200, null]; }
        $this->seed($rows);
        $admin = $this->client->actingAs(1);
        $page = $admin->get('/admin/logs');
        $this->assertStringContainsString('>r120<', $page->body);
        $this->assertStringContainsString('>r71<', $page->body);
        $this->assertStringNotContainsString('>r70<', $page->body);
        $this->assertMatchesRegularExpression('#before=71#', $page->body);

        $page = $this->client->get('/admin/logs', ['before' => '71']); // page two: 70..21
        $this->assertStringContainsString('>r70<', $page->body);
        $this->assertStringContainsString('>r21<', $page->body);
        $this->assertStringNotContainsString('>r71<', $page->body);
        $this->assertStringNotContainsString('>r20<', $page->body);

        $page = $this->client->get('/admin/logs', ['before' => '21']); // page three: 20..1
        $this->assertStringContainsString('>r20<', $page->body);
        $this->assertStringNotContainsString('Next', $page->body);

        $page = $this->client->get('/admin/logs', ['after' => '20']); // Prev lands flush: 70..21
        $this->assertStringContainsString('>r70<', $page->body);
        $this->assertStringContainsString('>r21<', $page->body);
        $this->assertStringNotContainsString('>r71<', $page->body);
        $this->assertStringNotContainsString('>r20<', $page->body);
        $this->assertMatchesRegularExpression('#after=70#', $page->body); // Prev link to page one

        // Prev from here goes to page one. The walk's own requests were audited
        // as rows newer than the seeds, so a Prev link is CORRECT at this point;
        // the nothing-newer case is pinned by its own test below.
        $page = $this->client->get('/admin/logs', ['after' => '70']);
        $this->assertStringContainsString('>r120<', $page->body);
        $this->assertStringNotContainsString('>r70<', $page->body);
    }

    public function test_prev_link_disappears_when_nothing_is_newer(): void
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) { $rows[] = ['GET', "r{$i}", 200, null]; }
        $this->seed($rows);
        // after=70 with exactly 50 rows above it (the first request of this
        // test, so its own audit row does not exist at render time): the page
        // fills completely and the probe finds nothing newer.
        $page = $this->client->actingAs(1)->get('/admin/logs', ['after' => '70']);
        $this->assertStringContainsString('>r120<', $page->body);
        $this->assertStringContainsString('>r71<', $page->body);
        $this->assertStringNotContainsString('&larr; Prev', $page->body);
        $this->assertMatchesRegularExpression('#before=71#', $page->body); // Next (older) still offered
    }

    public function test_stale_next_cursor_after_retention_renders_empty_window(): void // fold 5
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) { $rows[] = ['GET', "r{$i}", 200, null]; }
        $this->seed($rows);
        $this->logPdo->exec('DELETE FROM requests WHERE id < 51'); // retention took the older half
        $res = $this->client->actingAs(1)->get('/admin/logs', ['before' => '51', 'method' => 'GET']);
        $this->assertSame(200, $res->status);                       // empty window, not an error
        $this->assertStringContainsString('No requests', $res->body);
        $this->assertMatchesRegularExpression('#href="/admin/logs\?method=GET"#', $res->body); // Newest keeps filters
    }

    public function test_malformed_input_is_ignored_never_fatal(): void // fold 4
    {
        $this->seed(self::matrix());
        $admin = $this->client->actingAs(1);
        foreach ([
            ['before' => 'abc'], ['before' => '-5'], ['before' => '99e99'], ['before' => ['1']],
            ['user_id' => '1.5'], ['status' => '9'], ['method' => 'PURGE'], ['path' => []],
            ['before' => '10', 'after' => '3'],
        ] as $q) {
            $res = $admin->get('/admin/logs', $q);
            $this->assertSame(200, $res->status, json_encode($q));
        }
        // garbage cursor behaves like the first page; before wins over after
        $this->assertStringContainsString('/api/orders', $admin->get('/admin/logs', ['before' => 'abc'])->body);
        $this->assertStringContainsString('/api/orders', $admin->get('/admin/logs', ['before' => '10', 'after' => '3'])->body);
    }

    public function test_viewer_appends_only_its_own_audit_row_and_changes_nothing(): void // fold 7
    {
        $this->seed(self::matrix());
        $before = $this->logPdo->query('SELECT * FROM requests ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $res = $this->client->actingAs(1)->get('/admin/logs');
        $this->assertSame(200, $res->status);
        $after = $this->logPdo->query('SELECT * FROM requests ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(count($before) + 1, $after);                   // exactly one new row
        $this->assertSame($before, array_slice($after, 0, count($before))); // seeded rows untouched
        $this->assertSame('/admin/logs', $after[count($after) - 1]['path']); // it is the viewer's own request
    }

    public function test_post_to_the_viewer_is_405_for_an_admin(): void // read-only: no write endpoints
    {
        $res = $this->client->actingAs(1)->post('/admin/logs');
        $this->assertSame(405, $res->status);
        $this->assertSame('GET', $res->headers['Allow'] ?? null);
    }

    public function test_without_log_db_config_the_viewer_denies_and_never_autowires(): void
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'admin' => ['enabled' => true],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $app->container->make(Database::class);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT, is_admin INTEGER NOT NULL DEFAULT 0)');
        $db->query("INSERT INTO users (email, password_hash, is_admin) VALUES ('a@x.com', 'h', 1)");
        $res = (new TestClient($app))->actingAs(1)->get('/admin/logs');
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('log_db', $res->body);
        // The autowire hazard pin: no requests table may appear in the content database.
        $n = $db->one("SELECT COUNT(*) c FROM sqlite_master WHERE type = 'table' AND name = 'requests'")['c'] ?? null;
        $this->assertSame(0, (int) $n);
    }
}
