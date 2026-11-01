<?php // tests/App/GuestVerbMismatchTest.php
namespace Kip\Tests\App;

use Kip\App;
use Kip\Database;
use Kip\Http\{Request, Response};
use Kip\Migrations\Migrator;
use Kip\Routing\{Auth, Post};
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class NotFoundHandlerOpenController
{
    #[Post]
    public function save(): string { return 'saved'; }
}

final class NotFoundHandlerGatedController
{
    #[Auth]
    #[Post]
    public function save(): string { return 'gated-saved'; }
}

/** The guest_verb_mismatch_as_404 option: gated routes do not confirm themselves to anonymous callers. */
final class GuestVerbMismatchTest extends TestCase
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

    public function test_default_guest_wrong_verb_on_a_gated_route_still_redirects(): void
    {
        $res = (new TestClient($this->app()))->get('/not-found-handler-gated/save');
        $this->assertSame(302, $res->status);
    }

    public function test_opt_in_guest_wrong_verb_on_a_gated_route_is_the_unknown_route_404(): void
    {
        $c = new TestClient($this->app(['guest_verb_mismatch_as_404' => true]));
        $guest = $c->get('/not-found-handler-gated/save');
        $unknown = $c->get('/no-such-route');
        $this->assertSame(404, $guest->status);
        $this->assertSame($unknown->body, $guest->body);
        $this->assertArrayNotHasKey('Allow', $guest->headers);
    }

    public function test_opt_in_leaves_logged_in_405_and_ungated_405_alone(): void
    {
        $app = $this->app(['guest_verb_mismatch_as_404' => true]);
        $app->container->make(Database::class)->query("INSERT INTO users (email, password_hash) VALUES ('u@x.com', 'h')");
        $c = new TestClient($app);
        $this->assertSame(405, $c->actingAs(1)->get('/not-found-handler-gated/save')->status);
        $this->assertSame(405, $c->get('/not-found-handler-open/save')->status);
    }

    public function test_opt_in_runs_the_not_found_handler_for_the_hidden_route(): void
    {
        $app = $this->app(['guest_verb_mismatch_as_404' => true]);
        $app->notFound(fn (Request $r): ?Response => Response::redirect('/', 302));
        $this->assertSame(302, (new TestClient($app))->get('/not-found-handler-gated/save')->status);
    }
}
