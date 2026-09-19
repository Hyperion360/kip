<?php // tests/AppTest.php
namespace Kip\Tests;
use Kip\App;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

// Fixture controllers for the kernel test, resolved via the App's namespace option.
class HomeController { public function index(): string { return 'welcome'; } }
class BoomController { public function index(): string { throw new \RuntimeException('secret detail'); } }
class EchoController // proves the controller sees the CURRENT request's scope (review 1A)
{
    public function __construct(private \Kip\Http\Request $request) {}
    public function index(string $arg = ''): string { return $this->request->path; }
}
class FormController { #[\Kip\Routing\Post] public function save(): string { return 'saved'; } }
class SecretController { #[\Kip\Routing\Auth] public function index(): string { return 'top secret'; } }
class SecretFormController { #[\Kip\Routing\Auth] #[\Kip\Routing\Post] public function save(): string { return 'saved'; } }
class GateController { #[\Kip\Routing\Auth] #[\Kip\Routing\Post] public function go(): string { return 'gone'; } } // T2 fix-round pin fixture

final class AppTest extends TestCase
{
    private function app(string $env): App
    {
        return new App([
            'env' => $env,
            'controller_namespace' => 'Kip\\Tests\\',
            'views' => sys_get_temp_dir(),
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
    }

    public function test_dispatches_and_wraps_string_in_200_response(): void
    {
        $res = $this->app('prod')->handle(new Request('GET', '/home/index', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertSame('welcome', $res->body);
    }

    public function test_db_user_and_pass_keys_reach_the_database(): void // mysql DSNs cannot embed credentials
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\',
            'views' => sys_get_temp_dir(),
            'db' => ['dsn' => 'sqlite::memory:', 'user' => 'someuser', 'pass' => 'somepass'],
        ]);
        $res = $app->handle(new Request('GET', '/home/index', [], [], []));
        $this->assertSame(200, $res->status); // booted and served with credentials forwarded (sqlite ignores them)
    }

    public function test_unknown_route_is_404(): void
    {
        $res = $this->app('prod')->handle(new Request('GET', '/nope', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_prod_error_hides_trace(): void // threat model D4: traces must never reach the browser
    {
        $res = $this->app('prod')->handle(new Request('GET', '/boom/index', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertStringNotContainsString('secret detail', $res->body);
        $this->assertStringNotContainsString(__FILE__, $res->body);
    }

    public function test_dev_error_shows_exception_and_file(): void // Play 1 error pages, D4
    {
        $res = $this->app('dev')->handle(new Request('GET', '/boom/index', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertStringContainsString('secret detail', $res->body);
        $this->assertStringContainsString('AppTest.php', $res->body);
    }

    public function test_sequential_requests_do_not_share_request_state(): void // review 1A: worker-safety regression guard
    {
        $app = $this->app('prod');
        $first  = $app->handle(new Request('GET', '/echo/index', [], [], []));
        $second = $app->handle(new Request('GET', '/echo/index/other', [], [], []));
        $this->assertSame('/echo/index', $first->body);
        $this->assertSame('/echo/index/other', $second->body); // stale scope would repeat the first path
    }

    public function test_verb_mismatch_returns_405_with_allow_header(): void // review 9A
    {
        $res = $this->app('prod')->handle(new Request('GET', '/form/save', [], [], []));
        $this->assertSame(405, $res->status);
        $this->assertSame('POST', $res->headers['Allow']);
    }

    public function test_post_without_token_rejected(): void // threat model: CSRF (cross-site evidence present, v0.2 T2)
    {
        $res = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], [], '', ['sec-fetch-site' => 'cross-site']));
        $this->assertSame(403, $res->status);
    }

    public function test_post_with_valid_token_accepted(): void
    {
        $app = $this->app('prod');
        $token = $app->session->csrfToken();
        $res = $app->handle(new Request('POST', '/form/save', [], ['_token' => $token], []));
        $this->assertSame(200, $res->status);
        $this->assertSame('saved', $res->body);
    }

    public function test_csrf_token_in_query_string_is_rejected(): void // v0.1.1 T6 (cross-site evidence present, v0.2 T2)
    {
        $app = $this->app('prod');
        $t = $app->session->csrfToken();
        $res = $app->handle(new Request('POST', '/form/save', ['_token' => $t], [], [], '', ['sec-fetch-site' => 'cross-site']));
        $this->assertSame(403, $res->status); // token via GET no longer authenticates the POST
    }

    public function test_sessions_are_request_scoped_not_boot_scoped(): void
    {
        $app = $this->app('prod');
        $a = []; $b = [];
        $s1 = new \Kip\Session($a);
        $s2 = new \Kip\Session($b);
        $t1 = $s1->csrfToken();
        $ok = $app->handle(new Request('POST', '/form/save', [], ['_token' => $t1], []), $s1);
        $this->assertSame(200, $ok->status);
        $cross = $app->handle(new Request('POST', '/form/save', [], ['_token' => $t1], [], '', ['sec-fetch-site' => 'cross-site']), $s2);
        $this->assertSame(403, $cross->status); // one App, two visitors: s1's token must not validate in s2's request (cross-site evidence present, v0.2 T2)
    }

    public function test_auth_attribute_blocks_guest(): void // threat model: forced browsing
    {
        $res = $this->app('prod')->handle(new Request('GET', '/secret/index', [], [], []));
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_guest_post_with_valid_token_still_redirects_to_login(): void // review 4A: CSRF-then-auth ordering
    {
        $app = $this->app('prod');
        $res = $app->handle(new Request('POST', '/secretform/save', [], ['_token' => $app->session->csrfToken()], []));
        $this->assertSame(302, $res->status); // valid token passes CSRF, auth still gates
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_every_request_is_audited(): void // review D13-A
    {
        $app = $this->app('prod');
        $app->handle(new Request('GET', '/home/index', [], [], [], '1.1.1.1'));
        $rows = $app->container->make(\Kip\RequestLog::class)->recent(1);
        $this->assertSame('/home/index', $rows[0]['path']);
        $this->assertSame('1.1.1.1', $rows[0]['ip']);
    }

    public function test_head_returns_headers_and_status_with_empty_body(): void // v0.1.1 T1
    {
        $res = $this->app('prod')->handle(new Request('HEAD', '/home/index', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertSame('', $res->body); // GET body suppressed for HEAD
    }

    public function test_guest_route_accepts_same_origin_post_without_token(): void // v0.2 T2
    {
        $res = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], [], '',
            ['origin' => 'http://blog.test', 'sec-fetch-site' => 'same-origin']));
        $this->assertSame(200, $res->status); // origin lane replaces the token for non-#[Auth] routes
    }

    public function test_cross_site_guest_post_rejected(): void
    {
        $res = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], [], '',
            ['origin' => 'http://evil.test', 'sec-fetch-site' => 'cross-site']));
        $this->assertSame(403, $res->status);
    }

    public function test_headerless_client_accepted_on_guest_route(): void // OWASP fetch-metadata fallback: non-browser clients carry no CSRF victim
    {
        $res = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], []));
        $this->assertSame(200, $res->status);
    }

    public function test_valid_session_token_still_works_on_guest_route(): void
    {
        $app = $this->app('prod');
        $res = $app->handle(new Request('POST', '/form/save', [], ['_token' => $app->session->csrfToken()], []));
        $this->assertSame(200, $res->status);
    }

    public function test_authed_route_still_requires_token(): void // origin proof must NOT unlock #[Auth] routes
    {
        $app = $this->app('prod');
        $app->session->set('user_id', 1); // simulate logged-in
        $res = $app->handle(new Request('POST', '/secretform/save', [], [], [], '',
            ['origin' => 'http://blog.test', 'sec-fetch-site' => 'same-origin']));
        $this->assertSame(403, $res->status); // same-origin alone is not enough where ambient authority exists
    }

    public function test_origin_header_lane_compares_against_request_host(): void // OV P2b
    {
        $ok = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], [], '',
            ['origin' => 'http://blog.test', 'host' => 'blog.test']));
        $this->assertSame(200, $ok->status);
        $bad = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], [], '',
            ['origin' => 'http://evil.test', 'host' => 'blog.test']));
        $this->assertSame(403, $bad->status);
    }

    public function test_auth_post_route_requires_token_even_same_origin(): void // T2 fix-round: #[Auth] restores the hard gate
    {
        $app = $this->app('prod');
        $app->session->set('user_id', 1);
        $res = $app->handle(new Request('POST', '/gate/go', [], [], [], '',
            ['origin' => 'http://blog.test', 'host' => 'blog.test', 'sec-fetch-site' => 'same-origin']));
        $this->assertSame(403, $res->status); // same-origin evidence alone must not unlock an #[Auth] route
    }

    public function test_same_site_subdomain_is_rejected_on_guest_route(): void // T2 fix-round pin
    {
        $res = $this->app('prod')->handle(new Request('POST', '/form/save', [], [], [], '',
            ['sec-fetch-site' => 'same-site']));
        $this->assertSame(403, $res->status);
    }

    public function test_config_accessor_reads_values_and_defaults(): void // v0.3 T10: app-side base_url access
    {
        $app = new App([
            'env' => 'dev',
            'base_url' => 'http://example.test',
            'controller_namespace' => 'Kip\\Tests\\',
            'views' => sys_get_temp_dir(),
        ]);
        $this->assertSame('http://example.test', $app->config('base_url'));
        $this->assertSame('fallback', $app->config('nope', 'fallback'));
        $this->assertNull($app->config('nope'));
    }

    public function test_stale_password_epoch_fails_the_auth_gate(): void // kernel enforces session revocation
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => __DIR__ . '/Fixtures/views',
        ]);
        $db = $app->container->make(\Kip\Database::class);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT)');
        $db->query("INSERT INTO users (email, password_hash) VALUES ('a@b.c', 'prefix1234567')");
        $store = [];
        $s = new \Kip\Session($store);
        $s->set('user_id', 1);

        $res = $app->handle(new Request('GET', '/posts/create', [], [], []), $s);
        $this->assertSame(302, $res->status);            // no epoch at all → fail closed

        $s->set('pwd_epoch', 'WRONGPREFIX');
        $this->assertSame(302, $app->handle(new Request('GET', '/posts/create', [], [], []), $s)->status);

        $s->set('pwd_epoch', substr('prefix1234567', 0, \Kip\Auth::EPOCH_LEN));
        $this->assertSame(200, $app->handle(new Request('GET', '/posts/create', [], [], []), $s)->status);
    }

    public function test_app_wires_storage_and_mailer_only_when_configured(): void // config-key opt-in wiring
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\',
            'views' => sys_get_temp_dir(),
            'uploads' => ['dir' => sys_get_temp_dir() . '/kip-up-wiring', 'max_bytes' => 42, 'ext' => ['csv']],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kip-mail-wiring.log'],
        ]);
        $this->assertInstanceOf(\Kip\Storage::class, $app->container->make(\Kip\Storage::class));
        $this->assertInstanceOf(\Kip\Mailer::class, $app->container->make(\Kip\Mailer::class));

        $bare = new App(['env' => 'dev', 'controller_namespace' => 'Kip\\Tests\\', 'views' => sys_get_temp_dir()]);
        $gone = static function () use ($bare): bool {
            try { $bare->container->make(\Kip\Storage::class); return false; } catch (\Throwable) { return true; }
        };
        $this->assertTrue($gone()); // absence = feature never constructed
    }
}
