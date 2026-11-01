<?php // tests/App/NotFoundHandlerTest.php
namespace Kip\Tests\App;

use Kip\App;
use Kip\Database;
use Kip\Http\{Request, Response};
use Kip\Migrations\Migrator;
use Kip\Routing\Post;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class NotFoundHandlerController
{
    public function index(): string { return 'home-ish'; }
    #[Post]
    public function save(): string { return 'saved'; }
}

/** App::notFound(): the hook for requests that match no route, and the guest verb-mismatch opt-in. */
final class NotFoundHandlerTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function app(array $extra = []): App
    {
        $app = new App($extra + [
            'env' => 'prod',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\App\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $app->container->make(Database::class);
        (new Migrator($db, dirname(__DIR__, 2) . '/skeleton/app/migrations'))->migrate();
        return $app;
    }

    public function test_handler_redirects_dotted_and_capitalised_paths(): void
    {
        $app = $this->app();
        $app->notFound(fn (Request $r): ?Response => match ($r->path) {
            '/old.html', '/About' => Response::redirect('/about', 301),
            default => null,
        });
        $c = new TestClient($app);
        $res = $c->get('/old.html');
        $this->assertSame(301, $res->status);
        $this->assertSame('/about', $res->headers['Location']);
        $this->assertSame(301, $c->get('/About')->status);
    }

    public function test_null_falls_through_to_the_standard_404(): void
    {
        $app = $this->app();
        $app->notFound(fn (Request $r): ?Response => null);
        $res = (new TestClient($app))->get('/nothing.here');
        $this->assertSame(404, $res->status);
        $this->assertSame('Page not found', $res->body);
    }

    public function test_handler_does_not_run_for_matched_routes_or_405s(): void
    {
        $app = $this->app();
        $calls = 0;
        $app->notFound(function (Request $r) use (&$calls): ?Response { $calls++; return null; });
        $c = new TestClient($app);
        $this->assertSame(200, $c->get('/not-found-handler')->status);
        $this->assertSame(405, $c->get('/not-found-handler/save')->status);
        $this->assertSame(0, $calls);
        $c->get('/missing');
        $this->assertSame(1, $calls);
    }

    public function test_handler_sees_every_verb_and_decides(): void
    {
        $app = $this->app();
        $seen = [];
        $app->notFound(function (Request $r) use (&$seen): ?Response {
            $seen[] = $r->method;
            return $r->method === 'GET' ? Response::redirect('/', 301) : null;
        });
        $c = new TestClient($app);
        $this->assertSame(301, $c->get('/a.html')->status);
        $this->assertSame(404, $c->post('/a.html')->status);
        $this->assertSame(['GET', 'POST'], $seen);
    }

    public function test_head_gets_status_and_headers_without_a_body(): void
    {
        $app = $this->app();
        $app->notFound(fn (Request $r): ?Response => Response::redirect('/about', 301));
        $res = (new TestClient($app))->request('HEAD', '/old.html');
        $this->assertSame(301, $res->status);
        $this->assertSame('', $res->body);
    }

    public function test_rate_limiter_still_runs_before_the_handler(): void
    {
        $app = $this->app(['rate_limit' => ['not-found-handler' => ['max' => 0, 'window' => 1_000_000_000]]]);
        $calls = 0;
        $app->notFound(function (Request $r) use (&$calls): ?Response { $calls++; return null; });
        $res = (new TestClient($app))->post('/not-found-handler/gone');
        $this->assertSame(429, $res->status);
        $this->assertSame(0, $calls, 'an over-limit request never reaches app code');
    }

    public function test_a_throwing_handler_becomes_the_standard_500(): void
    {
        $app = $this->app();
        $app->notFound(function (Request $r): ?Response { throw new \RuntimeException('boom'); });
        $this->assertSame(500, (new TestClient($app))->get('/x.html')->status);
    }

    public function test_only_one_handler_may_be_registered(): void
    {
        $app = $this->app();
        $app->notFound(fn (Request $r): ?Response => null);
        $this->expectException(\LogicException::class);
        $app->notFound(fn (Request $r): ?Response => null);
    }
}
