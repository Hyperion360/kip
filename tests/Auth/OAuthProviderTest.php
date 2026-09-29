<?php // tests/Auth/OAuthProviderTest.php
namespace Kip\Tests\Auth;

use Kip\Auth\OAuthProvider;
use Kip\Session;
use PHPUnit\Framework\TestCase;

/**
 * The offline half of the provider client: URL construction, the session
 * flow stash, and every refusal that must fire BEFORE any network I/O.
 * The wire half (token exchange, user mapping) lives in OAuthStubTest
 * against the PHP-native stub IdP.
 */
final class OAuthProviderTest extends TestCase
{
    private const GOOGLE = [
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'redirect_uri' => 'https://app.example.org/oauth/callback/google',
    ];

    /** @param array<string, mixed> $store */
    private function provider(array &$store, array $config = self::GOOGLE, string $name = 'google'): OAuthProvider
    {
        return new OAuthProvider($name, $config, new Session($store));
    }

    /** @return array<string, mixed> the parsed authorize query */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    public function test_google_authorize_url_pins_every_parameter(): void
    {
        $store = [];
        $url = $this->provider($store)->start();

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', (string) parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH));
        $q = self::queryOf($url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('cid', $q['client_id']);
        $this->assertSame(self::GOOGLE['redirect_uri'], $q['redirect_uri'], 'the redirect URI is the configured one, verbatim');
        $this->assertSame('openid email', $q['scope']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $q['state']);
        $this->assertSame('S256', $q['code_challenge_method']);

        $flows = $store[OAuthProvider::SESSION_KEY];
        $this->assertCount(1, $flows);
        $this->assertSame($q['state'], $flows[0]['state']);
        $this->assertSame('google', $flows[0]['provider']);
        $this->assertSame(self::GOOGLE['redirect_uri'], $flows[0]['redirect_uri']);
        $this->assertNull($flows[0]['principal'], 'a guest start records a null principal');
        // PKCE: the challenge on the wire must be derivable from the verifier in the session
        $verifier = (string) $flows[0]['verifier'];
        $this->assertMatchesRegularExpression('/^[\x21-\x7e]{43,128}$/', $verifier, 'the verifier is RFC 7636 shaped');
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $q['code_challenge']
        );
    }

    public function test_github_omits_pkce_and_microsoft_sends_it(): void
    {
        $gh = [];
        $url = $this->provider($gh, array_merge(self::GOOGLE, ['redirect_uri' => 'https://app.example.org/oauth/callback/github']), 'github')->start();
        $this->assertArrayNotHasKey('code_challenge', self::queryOf($url), 'the GitHub web flow has no PKCE');

        $ms = [];
        $url = $this->provider($ms, array_merge(self::GOOGLE, ['redirect_uri' => 'https://app.example.org/oauth/callback/microsoft']), 'microsoft')->start();
        $q = self::queryOf($url);
        $this->assertSame('S256', $q['code_challenge_method']);
    }

    public function test_a_logged_in_start_records_the_principal(): void
    {
        $store = [];
        $this->provider($store)->start(5);
        $this->assertSame(5, $store[OAuthProvider::SESSION_KEY][0]['principal']);
    }

    public function test_extra_params_ride_the_authorize_url(): void
    {
        $store = [];
        $url = $this->provider($store, array_merge(self::GOOGLE, ['params' => ['access_type' => 'online']]))->start();
        $this->assertSame('online', self::queryOf($url)['access_type']);
    }

    public function test_unknown_provider_without_full_config_is_refused(): void
    {
        $store = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("OAuth provider 'test' config key 'authorize_url' is required");
        $this->provider($store, ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://a.b/cb'], 'test');
    }

    public function test_custom_provider_on_loopback_http_is_accepted(): void
    {
        $store = [];
        $p = $this->provider($store, [
            'client_id' => 'c', 'client_secret' => 's',
            'redirect_uri' => 'http://127.0.0.1:8097/oauth/callback/test',
            'authorize_url' => 'http://127.0.0.1:8097/authorize',
            'token_url' => 'http://127.0.0.1:8097/token',
            'user_url' => 'http://127.0.0.1:8097/userinfo',
            'pkce' => true,
        ], 'test');
        $url = $p->start();
        $this->assertStringContainsString('code_challenge', $url);
        $this->assertArrayNotHasKey('scope', self::queryOf($url), 'a scope-less custom provider omits the parameter, the provider default applies');
    }

    public function test_plain_http_off_loopback_is_refused(): void
    {
        $store = [];
        try {
            $this->provider($store, array_merge(self::GOOGLE, ['token_url' => 'http://provider.example.org/token']));
            $this->fail('expected the http endpoint to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
            $this->assertStringContainsString('token_url', $e->getMessage());
        }
    }

    public function test_transport_gate_names_both_remedies(): void
    {
        try {
            OAuthProvider::assertTransport(false, false, true);
            $this->fail('expected RuntimeException when neither transport exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ext-curl', $e->getMessage());
            $this->assertStringContainsString('ext-openssl', $e->getMessage());
        }
        try {
            OAuthProvider::assertTransport(false, true, false);
            $this->fail('expected RuntimeException when streams cannot open URLs');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('allow_url_fopen', $e->getMessage());
        }
    }

    public function test_wrong_state_is_refused_and_cancels_nothing(): void
    {
        $store = [];
        $p = $this->provider($store);
        $p->start();
        $p->start();

        try {
            $p->callback(str_repeat('f', 64), 'code-irrelevant');
            $this->fail('expected the unknown state to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('code-irrelevant', $e->getMessage());
        }
        $this->assertCount(2, $store[OAuthProvider::SESSION_KEY], 'an unknown state must not cancel other pending flows');
    }

    public function test_a_consumed_flow_is_single_use(): void
    {
        $store = [];
        $p = $this->provider($store);
        $p->start();
        // Force the TTL branch: the flow is consumed, then refused as expired,
        // which pins consumption without any network I/O. A replay of the
        // same state then finds nothing.
        $state = (string) $store[OAuthProvider::SESSION_KEY][0]['state'];
        $store[OAuthProvider::SESSION_KEY][0]['created'] = time() - OAuthProvider::STATE_TTL - 1;

        try {
            $p->callback($state, 'c');
            $this->fail('expected the expired flow to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        $this->assertSame([], $store[OAuthProvider::SESSION_KEY], 'the expired flow is consumed, gone for good');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no pending');
        $p->callback($state, 'c');
    }

    public function test_a_callback_for_another_provider_is_refused(): void
    {
        $store = [];
        $this->provider($store)->start(); // google flow
        $state = (string) $store[OAuthProvider::SESSION_KEY][0]['state'];

        $github = $this->provider($store, array_merge(self::GOOGLE, ['redirect_uri' => 'https://app.example.org/oauth/callback/github']), 'github');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('differs from the flow');
        $github->callback($state, 'c');
    }

    public function test_a_principal_change_mid_flow_is_refused(): void
    {
        $store = [];
        $p = $this->provider($store);
        $p->start(null); // guest begins
        $state = (string) $store[OAuthProvider::SESSION_KEY][0]['state'];

        // ... the visitor logs in with a password in another tab, then the
        // provider redirects back: the flow was a guest's, the callback is a
        // user's. Refused, before any network I/O.
        try {
            $p->callback($state, 'c', 7);
            $this->fail('expected the principal change to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('signed-in account changed', $e->getMessage());
        }

        // And the mirror: a user starts, logs out, the callback arrives guest.
        $store = [];
        $p2 = $this->provider($store);
        $p2->start(7);
        $state2 = (string) $store[OAuthProvider::SESSION_KEY][0]['state'];
        $this->expectException(\RuntimeException::class);
        $p2->callback($state2, 'c', null);
    }

    public function test_a_changed_redirect_uri_is_refused(): void
    {
        $store = [];
        $this->provider($store)->start();
        $state = (string) $store[OAuthProvider::SESSION_KEY][0]['state'];

        // The config moved between start and callback (base_url change,
        // crossed wires): the pinned URI no longer matches.
        $moved = $this->provider($store, array_merge(self::GOOGLE, ['redirect_uri' => 'https://app.example.org/moved/callback']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect URI changed');
        $moved->callback($state, 'c');
    }

    public function test_the_fourth_flow_evicts_the_oldest(): void
    {
        $store = [];
        $p = $this->provider($store);
        $first = self::queryOf($p->start())['state'];
        $p->start();
        $p->start();
        $p->start();

        $states = array_column($store[OAuthProvider::SESSION_KEY], 'state');
        $this->assertCount(OAuthProvider::MAX_PENDING, $states);
        $this->assertNotContains($first, $states, 'the oldest flow is dropped, its tab simply starts again');
    }
}
