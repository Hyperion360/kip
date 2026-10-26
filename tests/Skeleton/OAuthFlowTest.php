<?php // tests/Skeleton/OAuthFlowTest.php
namespace Kip\Tests\Skeleton;

use Kip\{App, Auth, Database, Session};
use Kip\Auth\OAuthProvider;
use Kip\Testing\TestClient;
use Kip\Tests\OAuthStubServer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/skeleton/app/src/Controllers/AuthController.php';
require_once dirname(__DIR__, 2) . '/skeleton/app/src/Controllers/HomeController.php';
require_once dirname(__DIR__, 2) . '/skeleton/app/src/Controllers/OauthController.php';

/**
 * The skeleton's OAuth wiring end to end, the stub IdP on one side and the
 * real routes on the other: /oauth/start/{provider} sends the visitor away
 * with the session-bound state, /oauth/callback/{provider} runs the
 * login-or-register policy, and every failure lands on the login page with
 * an enum-only error flag, never a rendered page at the callback URL.
 */
final class OAuthFlowTest extends TestCase
{
    use OAuthStubServer;

    private const PORT = 8098; // 8097 is the provider wire suite, 8096 the S3 stub

    private App $app;
    private TestClient $client;
    private Database $db;

    protected function tearDown(): void
    {
        $this->stopOAuthStub();
    }

    /**
     * The skeleton app with a 'test' provider over the stub, plus the
     * skeleton's own tables (the real 009 migration among them). The
     * 'github' entry is configured-but-unset, the way the shipped config
     * looks without its env vars.
     *
     * @param array<string, string> $stubEnv
     * @param array<string, mixed> $configExtra merged into the App config (cache_db, flags)
     */
    private function boot(array $stubEnv = [], array $configExtra = []): void
    {
        // The cache-less readiness memo is process-wide and every test here
        // names its provider 'test': a fresh boot must not inherit the last
        // test's verdict.
        \Kip\Auth\OAuthHealth::resetClaims();
        $stub = $this->startOAuthStub(self::PORT, $stubEnv);
        $config = [
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__, 2) . '/skeleton/app/views',
            'base_url' => 'http://127.0.0.1:' . self::PORT,
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kip-oauth-flow-test.log', 'from' => 'noreply@localhost'],
            'oauth' => ['providers' => [
                'test' => [
                    'client_id' => $stub['client_id'],
                    'client_secret' => $stub['client_secret'],
                    'authorize_url' => $stub['base'] . '/authorize',
                    'token_url' => $stub['base'] . '/token',
                    'user_url' => $stub['base'] . '/userinfo',
                ],
                'github' => ['client_id' => null, 'client_secret' => null],
            ]],
        ];
        $this->app = new App([...$config, ...$configExtra]);
        $this->db = $this->app->container->make(Database::class);
        foreach (['001_create_users', '002_create_login_attempts', '004_create_password_resets', '009_create_oauth_identities'] as $file) {
            (require dirname(__DIR__, 2) . "/skeleton/app/migrations/{$file}.php")->up($this->db);
        }
        $this->client = new TestClient($this->app);
    }

    /** @return array{0: string, 1: string} the code and state the IdP sent back */
    private function browserSideDance(): array
    {
        $start = $this->client->get('/oauth/start/test');
        $this->assertSame(302, $start->status, 'start sends the visitor to the provider');
        $this->assertStringStartsWith('http://127.0.0.1:' . self::PORT . '/authorize?', $start->headers['Location']);
        [$status, $headers] = $this->fetch('GET', $start->headers['Location']);
        $this->assertSame(302, $status);
        $back = (string) $headers['location'];
        $this->assertStringStartsWith('http://127.0.0.1:' . self::PORT . '/oauth/callback/test?', $back, 'the stub returns to the registered callback');
        parse_str((string) parse_url($back, PHP_URL_QUERY), $q);
        return [(string) $q['code'], (string) $q['state']];
    }

    /** The flow count in the client's session, the multi-tab stash. */
    private function pendingFlows(): int
    {
        $flows = $this->client->session->get(OAuthProvider::SESSION_KEY);
        return is_array($flows) ? count($flows) : 0;
    }

    public function test_the_whole_dance_lands_logged_in_and_redirects_home(): void
    {
        $this->boot();
        [$code, $state] = $this->browserSideDance();

        $res = $this->client->get('/oauth/callback/test', ['code' => $code, 'state' => $state]);

        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
        $user = $this->db->one("SELECT * FROM users WHERE email = 'user@example.org'");
        $this->assertNotFalse($user, 'the verified email seeded an account');
        $linked = $this->db->one("SELECT user_id FROM oauth_identities WHERE provider = 'test' AND provider_uid = 'stub-user-1'");
        $this->assertSame((int) $user['id'], (int) $linked['user_id'], 'the identity row points at it');
        $auth = new Auth($this->db, $this->client->session, static function (): void {});
        $this->assertTrue($auth->sessionValid(), 'the callback session is a real, valid login');
        $this->assertSame(0, $this->pendingFlows(), 'the flow is consumed');
    }

    public function test_every_oauth_response_forbids_referrers_and_caching(): void
    {
        $this->boot();
        $start = $this->client->get('/oauth/start/test');
        $this->assertSame('no-referrer', $start->headers['Referrer-Policy']);
        $this->assertSame('no-store', $start->headers['Cache-Control']);

        [$code, $state] = $this->browserSideDance();
        $res = $this->client->get('/oauth/callback/test', ['code' => $code, 'state' => $state]);
        $this->assertSame('no-referrer', $res->headers['Referrer-Policy'], 'the callback URL carries the code and state');
        $this->assertSame('no-store', $res->headers['Cache-Control']);
    }

    public function test_a_wrong_state_redirects_to_login_and_cancels_nothing(): void
    {
        $this->boot();
        $this->client->get('/oauth/start/test');
        $this->client->get('/oauth/start/test');
        $this->assertSame(2, $this->pendingFlows());

        $res = $this->client->get('/oauth/callback/test', ['code' => 'x', 'state' => str_repeat('f', 64)]);

        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login?oauth=failed', $res->headers['Location'], 'an enum flag, never provider text or a code');
        $this->assertNull($this->client->session->get('user_id'), 'the session stays a guest');
        $this->assertSame(2, $this->pendingFlows(), 'an unknown state cancels no other tab\'s flow');

        $login = $this->client->get('/auth/login', ['oauth' => 'failed']);
        $this->assertSame(200, $login->status);
        $this->assertStringContainsString('did not complete', $login->body, 'the enum flag renders its message');
    }

    public function test_an_unverified_email_is_refused_without_creating_an_account(): void
    {
        $this->boot(['KIP_OAUTH_STUB_VERIFIED' => '0']);
        [$code, $state] = $this->browserSideDance();

        $res = $this->client->get('/oauth/callback/test', ['code' => $code, 'state' => $state]);

        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login?oauth=refused', $res->headers['Location']);
        $this->assertNull($this->client->session->get('user_id'));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'no account is created');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM oauth_identities')['c'], 'nothing is linked');
    }

    public function test_a_verified_email_that_already_exists_is_refused_too(): void
    {
        $this->boot();
        $store = [];
        (new Auth($this->db, new Session($store), static function (): void {}))->register('user@example.org', 'pass-pass-pass');
        [$code, $state] = $this->browserSideDance();

        $res = $this->client->get('/oauth/callback/test', ['code' => $code, 'state' => $state]);

        $this->assertSame('/auth/login?oauth=refused', $res->headers['Location'], 'a provider email never resolves to an existing account');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'exactly the one pre-existing account');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM oauth_identities')['c']);
    }

    public function test_a_signed_in_session_links_the_identity_instead_of_creating_a_user(): void
    {
        // Unverified on purpose: linking rides the authenticated session and
        // never consults the email, which is why a provider without a
        // verification marker (the Microsoft shape) can attach but never create.
        $this->boot(['KIP_OAUTH_STUB_VERIFIED' => '0']);
        $store = [];
        (new Auth($this->db, new Session($store), static function (): void {}))->register('local@x.y', 'pass-pass-pass');
        $this->client->actingAs(1);

        [$code, $state] = $this->browserSideDance();
        $res = $this->client->get('/oauth/callback/test', ['code' => $code, 'state' => $state]);

        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM users')['c'], 'no second user');
        $linked = $this->db->one("SELECT user_id FROM oauth_identities WHERE provider = 'test'");
        $this->assertSame(1, (int) $linked['user_id'], 'the identity is attached to the signed-in account');
        $this->assertSame(1, $this->client->session->get('user_id'), 'still the same session');
    }

    public function test_the_login_page_lists_configured_providers_only(): void
    {
        $this->boot();

        $login = $this->client->get('/auth/login');

        $this->assertSame(200, $login->status);
        $this->assertStringContainsString('href="/oauth/start/test"', $login->body, 'the configured provider has a plain link');
        $this->assertStringNotContainsString('/oauth/start/github', $login->body, 'an entry without secrets is not offered');
        $this->assertStringNotContainsString('onclick', $login->body, 'plain links, no inline handlers');
    }

    public function test_an_unknown_or_unconfigured_provider_is_a_404(): void
    {
        $this->boot();

        $this->assertSame(404, $this->client->get('/oauth/start/google')->status);
        $this->assertSame(404, $this->client->get('/oauth/start/github')->status, 'configured-but-unset is unconfigured');
        $this->assertSame(404, $this->client->get('/oauth/callback/ghost')->status);
    }

    public function test_the_login_page_hides_a_provider_whose_credentials_fail_the_probe(): void
    {
        $this->boot(['KIP_OAUTH_STUB_PROBE' => 'invalid_client']); // the stub now indicts every credential
        $login = $this->client->get('/auth/login');
        $this->assertSame(200, $login->status);
        $this->assertStringNotContainsString('oauth/start/', (string) $login->body, 'a failing provider never reaches the skin');
        $this->assertStringContainsString('action="/auth/attempt"', (string) $login->body, 'the password form always renders');
    }

    public function test_the_login_page_shows_a_provider_whose_credentials_pass(): void
    {
        $this->boot(); // the stub's ordinary token answers indict the bogus code, not the client
        $login = $this->client->get('/auth/login');
        $this->assertStringContainsString('oauth/start/test', (string) $login->body);
    }

    public function test_the_callback_answers_post_with_the_same_refusal_contract(): void
    {
        $this->boot();
        $post = $this->client->post('/oauth/callback/test', ['state' => 'forged', 'code' => 'nope']);
        $this->assertSame(302, $post->status, 'form_post callbacks route: no 405');
        $this->assertStringContainsString('/auth/login?oauth=failed', (string) $post->headers['Location']);
        $this->assertSame('no-store', $post->headers['Cache-Control'] ?? '', 'the hardened response either verb');
    }

    public function test_a_real_browser_form_post_callback_passes_the_kernel_and_a_forged_state_is_still_refused(): void
    {
        // Apple form-posts the code back from appleid.apple.com: a cross-site
        // POST that carries Sec-Fetch-Site: cross-site and a foreign Origin.
        // The kernel's same-origin proof would 403 exactly that caller, so the
        // callback is #[CrossSite]: the kernel admits it and the session-bound
        // single-use state is the proof. A forged state still lands on the
        // clean login redirect, never a 403 and never a session.
        $this->boot();
        $crossSite = ['sec-fetch-site' => 'cross-site', 'origin' => 'https://appleid.apple.com'];
        $post = $this->client->post('/oauth/callback/test', ['state' => 'forged', 'code' => 'nope'], $crossSite);
        $this->assertSame(302, $post->status, 'the kernel admits the cross-site form_post; the controller decides');
        $this->assertStringContainsString('/auth/login?oauth=failed', (string) $post->headers['Location']);
        $this->assertNull($this->client->session->get('user_id'), 'a forged state never logs anyone in');

        // Control: a cross-site POST to a route WITHOUT #[CrossSite] is still refused.
        $other = $this->client->post('/auth/attempt', ['email' => 'a@b.c', 'password' => 'x'], $crossSite);
        $this->assertSame(403, $other->status, 'the exemption is per action, the rest of the app keeps its origin proof');
    }

    public function test_probe_off_renders_the_button_without_any_network_call(): void
    {
        // The air-gapped escape hatch: 'probe' => false means config-complete IS
        // ready, and the stub must never hear a probe. The scripted failure
        // proves the probe would have hidden the button had it run.
        $this->boot(['KIP_OAUTH_STUB_PROBE' => 'invalid_client'], ['oauth' => ['probe' => false, 'providers' => [
            'test' => [
                'client_id' => 'stub-client', 'client_secret' => 'stub-secret',
                'authorize_url' => 'http://127.0.0.1:' . self::PORT . '/authorize',
                'token_url' => 'http://127.0.0.1:' . self::PORT . '/token',
                'user_url' => 'http://127.0.0.1:' . self::PORT . '/userinfo',
            ],
        ]]]);
        $login = $this->client->get('/auth/login');
        $this->assertStringContainsString('oauth/start/test', (string) $login->body, 'probe off: a configured provider renders');
        $log = $this->oauthStub['store'] . '/requests.log';
        $this->assertFalse(is_file($log) && str_contains((string) file_get_contents($log), 'POST /token'), 'probe off: no network call at all');
    }

    public function test_the_two_bundled_bins_stay_byte_identical(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileEquals($root . '/skeleton/bin/kip', $root . '/examples/blog/bin/kip', 'the hard constraint, now pinned');
    }

    public function test_a_failed_verdict_persists_in_the_cache_db_across_requests(): void
    {
        // The production wiring: cache_db is a real file-backed sqlite, the
        // probe is scripted unhealthy, and the SECOND render must read the
        // cached verdict instead of probing again.
        $cacheFile = tempnam(sys_get_temp_dir(), 'kip-oauth-cache-');
        $this->assertNotFalse($cacheFile);
        try {
            $this->boot(['KIP_OAUTH_STUB_PROBE' => 'invalid_client'], ['cache_db' => ['dsn' => 'sqlite:' . $cacheFile]]);
            $first = $this->client->get('/auth/login');
            $this->assertSame(200, $first->status);
            $this->assertStringNotContainsString('oauth/start/', (string) $first->body, 'a failing provider never reaches the skin');
            $row = (new Database('sqlite:' . $cacheFile))->one("SELECT healthy FROM oauth_health WHERE provider = 'test'");
            $this->assertNotNull($row, 'the verdict landed in the cache database');
            $this->assertSame(0, (int) $row['healthy']);

            $second = $this->client->get('/auth/login');
            $this->assertSame(200, $second->status);
            $this->assertStringNotContainsString('oauth/start/', (string) $second->body);
            $probes = substr_count((string) file_get_contents($this->stubRequestLog()), 'POST /token');
            $this->assertSame(1, $probes, "one probe across both renders, the second read the cache (got {$probes})");
        } finally {
            foreach ([$cacheFile, $cacheFile . '-wal', $cacheFile . '-shm'] as $f) {
                if (is_file($f)) @unlink($f);
            }
            $this->stopOAuthStub();
        }
    }

    /** The stub's request log path (the trait's store dir carries requests.log). */
    private function stubRequestLog(): string
    {
        return (string) $this->oauthStub['store'] . '/requests.log';
    }
}
