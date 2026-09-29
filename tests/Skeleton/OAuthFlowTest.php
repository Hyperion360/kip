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
     */
    private function boot(array $stubEnv = []): void
    {
        $stub = $this->startOAuthStub(self::PORT, $stubEnv);
        $this->app = new App([
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
        ]);
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
}
