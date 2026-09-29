<?php // tests/AppTest.php
namespace Kip\Tests;
use Kip\App;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/AppFeatures/Billing/BillingController.php';

// Fixture controllers for the kernel test, resolved via the App's namespace option.
class HomeController { public function index(): string { return 'welcome'; } }
class BoomController { public function index(): string { throw new \RuntimeException('secret detail'); } }
class EchoController // proves the controller sees the CURRENT request's scope (review 1A)
{
    public function __construct(private \Kip\Http\Request $request) {}
    public function index(): string { return $this->request->path; }
    public function again(string $arg = ''): string { return $this->request->path; } // /echo/index/<arg> is not a canonical spelling
}
class TxLeakController // opens a transaction, writes, then throws
{
    public function __construct(private \Kip\Database $db) {}
    public function index(): string
    {
        $this->db->begin();
        $this->db->query("INSERT INTO tx_probe (v) VALUES ('leaked')");
        throw new \RuntimeException('mid-transaction failure');
    }
}
class TxWriteController // performs and commits its own write
{
    public function __construct(private \Kip\Database $db) {}
    public function index(): string
    {
        $this->db->begin();
        $this->db->query("INSERT INTO tx_probe (v) VALUES ('committed')");
        $this->db->commit();
        return 'written';
    }
}
class FormController { #[\Kip\Routing\Post] public function save(): string { return 'saved'; } }
class RecallController // touches the session during the request (starts a lazy one)
{
    public function __construct(private \Kip\Session $session) {}
    public function index(): string { return (string) ($this->session->get('visits', 0) + 1); }
}
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

    /** App with a container Database, for fixture controllers that transact. */
    private function dbApp(): App
    {
        return new App([
            'env' => 'prod',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\',
            'views' => sys_get_temp_dir(),
        ]);
    }

    public function test_dispatches_and_wraps_string_in_200_response(): void
    {
        $res = $this->app('prod')->handle(new Request('GET', '/', [], [], []));
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
        $res = $app->handle(new Request('GET', '/', [], [], []));
        $this->assertSame(200, $res->status); // booted and served with credentials forwarded (sqlite ignores them)
    }

    public function test_unknown_route_is_404(): void
    {
        $res = $this->app('prod')->handle(new Request('GET', '/nope', [], [], []));
        $this->assertSame(404, $res->status);
    }

    public function test_prod_error_hides_trace(): void // threat model D4: traces must never reach the browser
    {
        $res = $this->app('prod')->handle(new Request('GET', '/boom', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertStringNotContainsString('secret detail', $res->body);
        $this->assertStringNotContainsString(__FILE__, $res->body);
    }

    public function test_dev_error_shows_exception_and_file(): void // Play 1 error pages, D4
    {
        $res = $this->app('dev')->handle(new Request('GET', '/boom', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertStringContainsString('secret detail', $res->body);
        $this->assertStringContainsString('AppTest.php', $res->body);
    }

    public function test_dev_error_is_a_self_contained_document_with_request_context(): void
    {
        $res = $this->app('dev')->handle(new Request('GET', '/boom', [], [], []));
        $this->assertSame(500, $res->status);
        $this->assertStringStartsWith('<!DOCTYPE html>', $res->body);
        $this->assertStringContainsString('<style>', $res->body);
        $this->assertStringContainsString('GET /boom', $res->body); // what triggered it
    }

    public function test_sequential_requests_do_not_share_request_state(): void // review 1A: worker-safety regression guard
    {
        $app = $this->app('prod');
        $first  = $app->handle(new Request('GET', '/echo', [], [], []));
        $second = $app->handle(new Request('GET', '/echo/again/other', [], [], []));
        $this->assertSame('/echo', $first->body);
        $this->assertSame('/echo/again/other', $second->body); // stale scope would repeat the first path
    }

    public function test_a_controller_transaction_that_throws_is_unwound(): void // worker-safety: one request must not leave a transaction open
    {
        $app = $this->dbApp();
        $db = $app->container->make(\Kip\Database::class);
        $db->query('CREATE TABLE tx_probe (id INTEGER PRIMARY KEY, v TEXT)');

        $res = $app->handle(new Request('GET', '/tx-leak', [], [], []));

        $this->assertSame(500, $res->status); // the error response is still rendered
        $this->assertSame(0, $db->transactionDepth()); // no open transaction survives the request
        $this->assertNull($db->one("SELECT id FROM tx_probe WHERE v = 'leaked'")); // the failed action's write is gone
    }

    public function test_a_request_after_a_failed_transaction_writes_normally(): void // same App, next request
    {
        $app = $this->dbApp();
        $db = $app->container->make(\Kip\Database::class);
        $db->query('CREATE TABLE tx_probe (id INTEGER PRIMARY KEY, v TEXT)');

        $this->assertSame(500, $app->handle(new Request('GET', '/tx-leak', [], [], []))->status);
        $ok = $app->handle(new Request('GET', '/tx-write', [], [], []));

        $this->assertSame(200, $ok->status);
        $this->assertNotNull($db->one("SELECT id FROM tx_probe WHERE v = 'committed'")); // own write committed
        $this->assertSame(0, $db->transactionDepth());
        $this->assertNull($db->one("SELECT id FROM tx_probe WHERE v = 'leaked'")); // exactly one row: the follow-up's own
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
        $res = $this->app('prod')->handle(new Request('GET', '/secret', [], [], []));
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_guest_post_with_valid_token_still_redirects_to_login(): void // review 4A: CSRF-then-auth ordering
    {
        $app = $this->app('prod');
        $res = $app->handle(new Request('POST', '/secret-form/save', [], ['_token' => $app->session->csrfToken()], []));
        $this->assertSame(302, $res->status); // valid token passes CSRF, auth still gates
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_every_request_is_audited(): void // review D13-A
    {
        $app = $this->app('prod');
        $app->handle(new Request('GET', '/', [], [], [], '1.1.1.1'));
        $rows = $app->container->make(\Kip\RequestLog::class)->recent(1);
        $this->assertSame('/', $rows[0]['path']);
        $this->assertSame('1.1.1.1', $rows[0]['ip']);
    }

    public function test_session_user_id_string_is_audited_as_int(): void // strict_types boundary
    {
        // A session written by older code (or another reader) may carry '7'.
        // handle() coerces it for the audit log instead of throwing.
        $app = $this->app('prod');
        $store = [];
        $s = new \Kip\Session($store);
        $s->set('user_id', '7');
        $res = $app->handle(new Request('GET', '/', [], [], []), $s);
        $this->assertSame(200, $res->status);
        $rows = $app->container->make(\Kip\RequestLog::class)->recent(1);
        $this->assertSame(7, $rows[0]['user_id']); // int 7 in the audit row, not the '7' the session held
    }

    public function test_non_numeric_session_user_id_is_audited_as_guest(): void
    {
        // user_id => 'nonsense' logs as a guest row and the response still
        // returns.
        $app = $this->app('prod');
        $store = [];
        $s = new \Kip\Session($store);
        $s->set('user_id', 'nonsense');
        $res = $app->handle(new Request('GET', '/', [], [], []), $s);
        $this->assertSame(200, $res->status);
        $rows = $app->container->make(\Kip\RequestLog::class)->recent(1);
        $this->assertNull($rows[0]['user_id']); // a guest row exists, not a TypeError with none
    }

    public function test_fractional_string_session_user_id_audits_as_guest(): void // audit exactness
    {
        // '1.5' is numeric, so the old is_numeric() coercion truncated it to 1
        // and attributed the request to the wrong user. Only integer-shaped
        // values audit as a user; everything else is a guest row.
        // sessionValid() still fail-closes independently on bad data.
        $app = $this->app('prod');
        $store = [];
        $s = new \Kip\Session($store);
        $s->set('user_id', '1.5');
        $res = $app->handle(new Request('GET', '/', [], [], []), $s);
        $this->assertSame(200, $res->status);
        $rows = $app->container->make(\Kip\RequestLog::class)->recent(1);
        $this->assertNull($rows[0]['user_id']); // audited as guest, never truncated to 1
    }

    public function test_only_integer_shaped_session_user_ids_audit_as_that_user(): void
    {
        // '5' audits as 5; a real int passes through unchanged.
        $app = $this->app('prod');
        $store = [];
        $s = new \Kip\Session($store);
        $s->set('user_id', '5');
        $app->handle(new Request('GET', '/', [], [], []), $s);
        $this->assertSame(5, $app->container->make(\Kip\RequestLog::class)->recent(1)[0]['user_id']);
        $store2 = [];
        $s2 = new \Kip\Session($store2);
        $s2->set('user_id', 9);
        $app->handle(new Request('GET', '/', [], [], []), $s2);
        $this->assertSame(9, $app->container->make(\Kip\RequestLog::class)->recent(1)[0]['user_id']);
    }

    public function test_head_returns_headers_and_status_with_empty_body(): void // v0.1.1 T1
    {
        $res = $this->app('prod')->handle(new Request('HEAD', '/', [], [], []));
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

    public function test_guest_wrong_verb_on_a_gated_route_redirects_rather_than_405(): void // pentest: 405 confirmed the route to guests
    {
        $res = $this->app('prod')->handle(new Request('GET', '/secret-form/save', [], [], []));
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_logged_in_wrong_verb_on_a_gated_route_is_405(): void
    {
        $app = $this->app('prod');
        $app->session->set('user_id', 1);
        $res = $app->handle(new Request('GET', '/secret-form/save', [], [], []));
        $this->assertSame(405, $res->status);
        $this->assertSame('POST', $res->headers['Allow']);
    }

    public function test_a_failing_deferred_task_is_logged_and_the_rest_still_run(): void
    {
        $app = $this->app('prod');
        $ran = [];
        $send = static function (string $to): void { throw new \RuntimeException('boom'); };
        $app->defer(static function () use ($send): void { $send('secret@example.com'); });
        $app->defer(static function () use (&$ran): void { $ran[] = 'second'; });
        $log = tempnam(sys_get_temp_dir(), 'kip-log-');
        $previous = ini_set('error_log', $log);
        try { $app->runDeferred(); } finally { ini_set('error_log', (string) $previous); }
        $logged = (string) file_get_contents($log);
        unlink($log);
        $this->assertStringContainsString('Deferred task failed: RuntimeException: boom at ', $logged);
        $this->assertStringNotContainsString('secret@', $logged, 'no trace arguments in the log');
        $this->assertSame(['second'], $ran, 'a throwing task must not stop the ones queued after it');
        $app->runDeferred();
        $this->assertSame(['second'], $ran, 'the queue is cleared once run');
    }

    /** A front controller that never calls runDeferred() still gets its work done at shutdown. */
    public function test_deferred_work_runs_at_shutdown_when_runDeferred_is_never_called(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'kip-defer-');
        unlink($marker);
        $script = sprintf('require %s; $app = new Kip\\App([]); $app->defer(static function (): void { touch(%s); });',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($marker, true));
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($marker);
        unlink($marker);
    }

    /** A deferred send must not keep the visitor's session locked (an existence oracle). */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_deferred_work_runs_with_the_session_released(): void
    {
        session_start();
        $app = $this->app('prod');
        $status = null;
        $app->defer(static function () use (&$status): void { $status = session_status(); });
        $app->runDeferred();
        $this->assertSame(PHP_SESSION_NONE, $status);
    }

    /** A request that touched the session but queued nothing must not leave it open. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_runDeferred_closes_the_session_even_with_an_empty_queue(): void
    {
        $app = $this->app('prod');
        $s = \Kip\Session::lazy(new \Kip\SessionStarter());
        $res = $app->handle(new Request('GET', '/recall', [], [], []), $s); // the action reads the session, starting it
        $this->assertSame(200, $res->status);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status()); // the request really did open it
        $app->runDeferred(); // nothing was deferred
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status()); // one session per request, queue or no queue
    }

    public function test_guest_post_without_token_on_a_gated_route_redirects_rather_than_403(): void
    {
        $res = $this->app('prod')->handle(new Request('POST', '/secret-form/save', [], [], []));
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
    }

    public function test_authed_route_still_requires_token(): void // origin proof must NOT unlock #[Auth] routes
    {
        $app = $this->app('prod');
        $app->session->set('user_id', 1); // simulate logged-in
        $res = $app->handle(new Request('POST', '/secret-form/save', [], [], [], '',
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

    public function test_string_config_integers_boot_and_behave(): void
    {
        // App-authored config may carry '30' for an int setting; the framework
        // coerces at its own boundary, so the app boots today and after strict_types.
        $app = new App([
            'log_db' => ['dsn' => 'sqlite::memory:', 'retention_days' => '30'],
            'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => '3600', 'max_pages' => '50'],
            'views' => sys_get_temp_dir(),
        ]);
        $this->assertInstanceOf(App::class, $app); // no TypeError at construction
        $this->assertInstanceOf(
            App::class,
            new App(['cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 3600], 'views' => sys_get_temp_dir()]) // the int form too
        );
    }

    public function test_non_numeric_config_integers_are_a_boot_error_naming_the_key(): void
    {
        // A cast would turn 'hour' into 0, a plausible TTL. Boot states the key
        // and the received type instead.
        try {
            new App([
                'cache_db' => ['dsn' => 'sqlite::memory:', 'ttl_seconds' => 'hour'],
                'views' => sys_get_temp_dir(),
            ]);
            $this->fail('expected InvalidArgumentException for cache_db.ttl_seconds');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cache_db.ttl_seconds', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage()); // the received type
        }
    }

    /** @return string a temp app dir with views/ and Features/Billing/views/invoice.php in place */
    private function featureAppDir(): string
    {
        $base = sys_get_temp_dir() . '/kip-app-feat-' . bin2hex(random_bytes(6));
        mkdir($base . '/views', 0777, true);
        mkdir($base . '/Features/Billing/views', 0777, true);
        file_put_contents($base . '/Features/Billing/views/invoice.php', 'feature invoice <?= $this->e($id) ?>');
        return $base;
    }

    /** @return list<string> every file and directory under $dir, deepest first, for unlink/rmdir in order */
    private static function rmList(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $out[] = $f->getPathname();
        $out[] = $dir;
        return $out;
    }

    /** Feature folders (ch. 3): a Features dir with the convention layout routes and renders with zero config. */
    public function test_a_feature_folder_renders_end_to_end(): void
    {
        $base = $this->featureAppDir();
        try {
            $app = new App([
                'env' => 'prod',
                'app_dir' => $base,
                'controller_namespace' => 'Kip\\Tests\\None\\',   // no plain controller: only the feature form can resolve
                'log_db' => ['dsn' => 'sqlite::memory:'],
            ]);
            $res = $app->handle(new Request('GET', '/billing/invoice/42', [], [], []));
            $this->assertSame(200, $res->status);
            $this->assertSame('feature invoice 42', $res->body);
        } finally {
            foreach (self::rmList($base) as $p) @unlink($p) ?: @rmdir($p);
        }
    }

    /** Review P1-2: an explicit feature_namespace => null must stay OFF even though the folder exists; ?? would turn it back on. */
    public function test_an_explicit_null_feature_namespace_disables_the_feature_form(): void
    {
        $base = $this->featureAppDir();
        try {
            $app = new App([
                'env' => 'prod',
                'app_dir' => $base,
                'controller_namespace' => 'Kip\\Tests\\None\\',
                'feature_namespace' => null,
                'log_db' => ['dsn' => 'sqlite::memory:'],
            ]);
            $this->assertSame(404, $app->handle(new Request('GET', '/billing/invoice/42', [], [], []))->status);
        } finally {
            foreach (self::rmList($base) as $p) @unlink($p) ?: @rmdir($p);
        }
    }

    /** Review P2-2: no Features directory means no feature resolution at all; layered apps behave unchanged. */
    public function test_an_app_without_a_features_dir_behaves_unchanged(): void
    {
        $base = sys_get_temp_dir() . '/kip-app-layered-' . bin2hex(random_bytes(6));
        mkdir($base . '/views', 0777, true);
        try {
            $app = new App([
                'env' => 'prod',
                'app_dir' => $base,
                'controller_namespace' => 'Kip\\Tests\\',
                'log_db' => ['dsn' => 'sqlite::memory:'],
            ]);
            $this->assertSame(404, $app->handle(new Request('GET', '/billing', [], [], []))->status); // App\Features\Billing\BillingController is loaded, but not routable
            $this->assertSame(200, $app->handle(new Request('GET', '/', [], [], []))->status);         // plain resolution still serves
        } finally {
            foreach (self::rmList($base) as $p) @unlink($p) ?: @rmdir($p);
        }
    }

    /** Edge case from the plan: a features_dir pointing at a missing directory is empty resolution, never an error. */
    public function test_a_features_dir_pointing_at_a_missing_directory_stays_quiet(): void
    {
        $base = sys_get_temp_dir() . '/kip-app-missing-' . bin2hex(random_bytes(6));
        mkdir($base . '/views', 0777, true);
        try {
            $app = new App([
                'env' => 'prod',
                'app_dir' => $base,
                'features_dir' => $base . '/Features',            // configured, but absent on disk
                'controller_namespace' => 'Kip\\Tests\\None\\',
                'log_db' => ['dsn' => 'sqlite::memory:'],
            ]);
            $this->assertSame(404, $app->handle(new Request('GET', '/billing', [], [], []))->status);
        } finally {
            foreach (self::rmList($base) as $p) @unlink($p) ?: @rmdir($p);
        }
    }
    public function test_a_deferred_task_that_fails_inside_a_transaction_is_unwound(): void
    {
        $app = $this->dbApp();
        $db = $app->container->make(\Kip\Database::class);
        $db->query('CREATE TABLE defer_probe (id INTEGER PRIMARY KEY, v TEXT)');
        $app->defer(function () use ($db): void {
            $db->begin();
            $db->query("INSERT INTO defer_probe (v) VALUES ('leaked')");
            throw new \RuntimeException('boom');
        });
        $app->runDeferred();
        $this->assertSame(0, $db->transactionDepth()); // the failure was logged, the depth restored
        $this->assertNull($db->one("SELECT id FROM defer_probe WHERE v = 'leaked'"));
    }

}
