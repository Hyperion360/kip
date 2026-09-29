<?php // tests/App/PolicyFlowTest.php
namespace Kip\Tests\App;
use Kip\App;
use Kip\Database;
use Kip\Session;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The policy battery end to end: registration, gate ordering (login, then
// CSRF, then the closure), the 403-not-redirect denial, loud failures for
// unknown names and non-bool verdicts, JSON consistency, worker isolation.
final class PolicyFlowTest extends TestCase
{
    private App $app;
    private TestClient $client;

    protected function setUp(): void
    {
        \Kip\Tests\Fixtures\Controllers\PolicyController::$ran = false;
        $this->app = $this->makeApp('dev');
        // One Session parameter on purpose: closures may declare fewer inputs
        // than the kernel passes (Session, Request); extras are ignored.
        $this->app->policy('can-edit', fn(Session $s): bool => $s->get('role') === 'editor');
        $this->client = $this->seededClient($this->app);
    }

    /** @param array<string, mixed> $extra */
    private function makeApp(string $env, array $extra = []): App
    {
        return new App($extra + [
            'env' => $env,
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
    }

    /** A client over an app whose users table satisfies the epoch gate for user 1. */
    private function seededClient(App $app): TestClient
    {
        $db = $app->container->make(Database::class);
        $db->query('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $db->query("INSERT OR IGNORE INTO users (email, password_hash) VALUES ('u@x.com', 'h')");
        return new TestClient($app);
    }

    private function login(string $role): TestClient
    {
        $this->client->actingAs(1);
        $this->client->session->set('role', $role);
        return $this->client;
    }

    public function test_an_editor_passes_the_policy_and_the_action_runs(): void
    {
        $res = $this->login('editor')->get('/policy/edit');
        $this->assertSame(200, $res->status);
        $this->assertSame('edited', $res->body);
        $this->assertTrue(\Kip\Tests\Fixtures\Controllers\PolicyController::$ran);
    }

    /** The nearer declaration wins: a child class policy beats a parent method policy (closing-pass finding). */
    public function test_a_child_class_policy_beats_the_parent_method_policy(): void
    {
        $app = $this->makeApp('dev');
        $app->policy('member', fn(Session $s): bool => true);  // the parent's weaker gate: always passes
        $app->policy('admin', fn(Session $s): bool => false);  // the child's narrower gate: always denies
        $client = $this->seededClient($app);
        $client->actingAs(1);
        $res = $client->get('/inheritshow');
        $this->assertSame(403, $res->status, 'the child class policy must decide, not the parent method policy reached through the inherited reflection');
    }

    /** Control: with the gates swapped, the same shape allows through. */
    public function test_a_child_class_policy_beats_the_parent_method_policy_control(): void
    {
        $app = $this->makeApp('dev');
        $app->policy('member', fn(Session $s): bool => false);
        $app->policy('admin', fn(Session $s): bool => true);
        $client = $this->seededClient($app);
        $client->actingAs(1);
        $res = $client->get('/inheritshow');
        $this->assertSame(200, $res->status);
        $this->assertSame('parent-decision', $res->body);
    }

    public function test_a_logged_in_viewer_is_denied_with_the_framework_403_shape(): void
    {
        $res = $this->login('viewer')->get('/policy/edit');
        $this->assertSame(403, $res->status);
        $this->assertSame('Forbidden', $res->body); // the admin panel's shape, not a redirect
        $this->assertArrayNotHasKey('Location', $res->headers, 'the user is logged in, not lost');
        $this->assertFalse(\Kip\Tests\Fixtures\Controllers\PolicyController::$ran, 'a denied action must not run');
    }

    public function test_a_guest_redirects_to_login_never_403(): void
    {
        $res = $this->client->get('/policy/edit');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location'] ?? null);
    }

    public function test_unknown_policy_is_a_loud_error_naming_it(): void
    {
        $client = $this->seededClient($this->makeApp('dev')); // no policy registered
        $client->actingAs(1);
        $res = $client->get('/policy/edit');
        $this->assertSame(500, $res->status, 'never a silent allow');
        $this->assertStringContainsString('can-edit', $res->body);
        $this->assertStringContainsString('App::policy', $res->body, 'the remedy must be in the error');
    }

    public function test_unknown_policy_in_prod_stays_opaque(): void
    {
        $client = $this->seededClient($this->makeApp('prod')); // no policy registered
        $client->actingAs(1);
        $res = $client->get('/policy/edit');
        $this->assertSame(500, $res->status);
        $this->assertSame('Something went wrong', $res->body);
        $this->assertStringNotContainsString('can-edit', $res->body);
    }

    public function test_duplicate_policy_names_are_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Policy 'can-edit' is already registered");
        $this->app->policy('can-edit', fn() => true);
    }

    public function test_a_non_bool_verdict_is_a_loud_error(): void
    {
        // `?:` shorthand returning a truthy string is the classic accidental allow.
        $app = $this->makeApp('dev');
        $app->policy('can-edit', fn() => 'definitely');
        $client = $this->seededClient($app);
        $client->actingAs(1);
        $res = $client->get('/policy/edit');
        $this->assertSame(500, $res->status, 'a truthy string must not allow');
        $this->assertStringContainsString('can-edit', $res->body);
        $this->assertStringContainsString('bool', $res->body);
        $this->assertFalse(\Kip\Tests\Fixtures\Controllers\PolicyController::$ran);
    }

    public function test_tokenless_post_on_a_policy_route_is_the_csrf_403(): void
    {
        $res = $this->login('editor')->post('/policy/save', ['x' => 1]);
        $this->assertSame(403, $res->status);
        $this->assertSame('Invalid or missing CSRF token', $res->body, 'CSRF runs before the policy gate');
    }

    public function test_post_with_token_but_denied_policy_is_forbidden(): void
    {
        $res = $this->login('viewer')->postWithToken('/policy/save', ['x' => 1]);
        $this->assertSame(403, $res->status);
        $this->assertSame('Forbidden', $res->body);
        $this->assertFalse(\Kip\Tests\Fixtures\Controllers\PolicyController::$ran);
    }

    public function test_a_denied_json_route_keeps_the_kernel_plain_403(): void
    {
        $res = $this->login('viewer')->get('/policy/stats');
        $this->assertSame(403, $res->status);
        $this->assertSame('Forbidden', $res->body);
        $this->assertStringNotContainsString('json', (string) ($res->headers['Content-Type'] ?? ''), 'kernel error responses are never JSON-wrapped');
    }

    public function test_a_passing_json_policy_route_wraps_as_application_json(): void
    {
        $res = $this->login('editor')->get('/policy/stats');
        $this->assertSame(200, $res->status);
        $this->assertSame('application/json', $res->headers['Content-Type'] ?? null);
        $this->assertSame('{"edited":true}', $res->body);
    }

    public function test_two_sessions_on_one_app_are_judged_independently(): void
    {
        $editor = new TestClient($this->app);
        $editor->actingAs(1);
        $editor->session->set('role', 'editor');
        $viewer = new TestClient($this->app);
        $viewer->actingAs(1);
        $viewer->session->set('role', 'viewer');
        $this->assertSame(200, $editor->get('/policy/edit')->status);
        $this->assertSame(403, $viewer->get('/policy/edit')->status);
        $this->assertSame(200, $editor->get('/policy/edit')->status, 'worker isolation: no verdict leaks between sessions');
    }

    public function test_policy_routes_are_never_served_from_the_page_cache(): void
    {
        $app = $this->makeApp('dev', ['cache_db' => ['dsn' => 'sqlite::memory:']]);
        $app->policy('can-edit', fn(Session $s): bool => $s->get('role') === 'editor');
        $client = $this->seededClient($app);
        $client->actingAs(1);
        $client->session->set('role', 'editor');
        $first = $client->get('/policy/edit');
        $second = $client->get('/policy/edit');
        $this->assertSame(200, $first->status);
        $this->assertSame(200, $second->status);
        $this->assertSame('BYPASS', $second->headers['X-Kip-Cache'] ?? null, 'an authed policy route is personal, never cached');
    }
}
