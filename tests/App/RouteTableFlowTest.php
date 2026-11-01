<?php // tests/App/RouteTableFlowTest.php
namespace Kip\Tests\App;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Routing\{Auth, Post};
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class RouteTableController
{
    public function feed(): string { return '<rss/>'; }
    public function page(string $slug): string { return "page:$slug"; }
    #[Post]
    public function submit(): string { return 'submitted'; }
    #[Auth]
    #[Post]
    public function admin(): string { return 'admin-done'; }
}

/**
 * Pattern routes through the whole kernel: the same rate limiter, login
 * gate and CSRF gate a convention route meets. The dotted path is the
 * sharp case: the convention grammar refuses dots, so the limiter's '*'
 * fallback must still count a POST to a dotted pattern route.
 */
final class RouteTableFlowTest extends TestCase
{
    private function client(array $rateLimit = []): TestClient
    {
        $app = new App([
            'env' => 'prod',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ] + ($rateLimit === [] ? [] : ['rate_limit' => $rateLimit]));
        $db = $app->container->make(Database::class);
        (new Migrator($db, dirname(__DIR__, 2) . '/skeleton/app/migrations'))->migrate();
        $db->query("INSERT INTO users (email, password_hash) VALUES ('u@x.com', 'h')");
        $app->route('/feed.xml', RouteTableController::class, 'feed');
        $app->route('/feed.xml/submit', RouteTableController::class, 'submit');
        $app->route('/admin.panel', RouteTableController::class, 'admin');
        $app->route('/<slug>', RouteTableController::class, 'page');
        return new TestClient($app);
    }

    public function test_dotted_and_parameter_routes_serve_through_the_kernel(): void
    {
        $c = $this->client();
        $this->assertSame('<rss/>', $c->get('/feed.xml')->body);
        $this->assertSame('page:about', $c->get('/about')->body);
        $this->assertSame(404, $c->get('/nope.txt')->status);
    }

    public function test_post_to_a_dotted_pattern_route_is_counted_by_the_star_fallback(): void
    {
        $c = $this->client(['*' => ['max' => 1, 'window' => 1_000_000_000]]);
        $this->assertSame(200, $c->postWithToken('/feed.xml/submit')->status);
        $this->assertSame(429, $c->postWithToken('/feed.xml/submit')->status, 'the second POST is over the cap');
    }

    public function test_junk_dot_paths_no_pattern_owns_stay_uncounted(): void
    {
        $c = $this->client(['*' => ['max' => 0, 'window' => 1_000_000_000]]);
        // max 0 would 429 anything charged; an unrouted dotted POST is a plain 404 and writes no row.
        $this->assertSame(404, $c->post('/junk.php')->status);
    }

    public function test_pattern_route_keeps_the_login_gate_and_csrf(): void
    {
        $c = $this->client();
        $res = $c->postWithToken('/admin.panel');
        $this->assertSame(302, $res->status, 'a guest is redirected to login before anything else');
        $logged = $c->actingAs(1);
        $this->assertSame(403, $logged->post('/admin.panel')->status, 'a gated route needs the session token');
        $this->assertSame('admin-done', $logged->postWithToken('/admin.panel')->body);
    }

    public function test_guest_post_on_a_pattern_route_still_gets_the_cross_site_check(): void
    {
        $c = $this->client();
        $res = $c->post('/feed.xml/submit', [], ['sec-fetch-site' => 'cross-site']);
        $this->assertSame(403, $res->status);
    }
}
