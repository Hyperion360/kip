<?php // tests/Auth/OAuthStubTest.php
namespace Kip\Tests\Auth;

use Kip\Auth\OAuthProvider;
use Kip\Session;
use Kip\Tests\OAuthStubServer;
use PHPUnit\Framework\TestCase;

/**
 * The wire half of the provider client, driven against the PHP-native stub
 * IdP (tests/Fixtures/oauth-stub.php): the full authorize/token/userinfo
 * dance, PKCE S256 end to end, single-use codes and states, redirect-URI
 * pinning, the GitHub form-encoded token behavior, the three user-info
 * shapes, and the follow-redirect refusal that keeps the bearer token from
 * ever leaving the endpoint it belongs to. The stub's checks are its own,
 * written from the RFC structure and shared with nothing in src/, so a
 * client bug cannot cancel itself out against a server that repeats it.
 */
final class OAuthStubTest extends TestCase
{
    use OAuthStubServer;

    private const PORT = 8097; // 8096 belongs to the S3 stub; ad-hoc servers stay above it

    protected function tearDown(): void
    {
        $this->stopOAuthStub();
    }

    /**
     * Client config over the running stub, oidc shape unless overridden.
     *
     * @param array<string, string> $stub the startOAuthStub() return value
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function config(array $stub, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $stub['client_id'],
            'client_secret' => $stub['client_secret'],
            'redirect_uri' => $stub['redirect_uri'],
            'authorize_url' => $stub['base'] . '/authorize',
            'token_url' => $stub['base'] . '/token',
            'user_url' => $stub['base'] . '/userinfo',
            'pkce' => true,
            'shape' => 'oidc',
        ], $overrides);
    }

    /** @return array<string, mixed> the parsed query of a URL */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    /**
     * The browser side of the dance: GET the authorize URL, never following
     * the redirect, and pull the code and state out of the 302 Location.
     *
     * @return array{0: string, 1: string} the code and the state
     */
    private function followAuthorize(string $url): array
    {
        [$status, $headers] = $this->fetch('GET', $url);
        $this->assertSame(302, $status, 'authorize must answer a redirect, the visitor follows it');
        $q = self::queryOf((string) $headers['location']);
        $this->assertNotSame('', (string) ($q['code'] ?? ''), 'the redirect carries the code');
        return [(string) $q['code'], (string) $q['state']];
    }

    /**
     * A whole client flow: start, follow the authorize redirect, complete the
     * callback. Returns the mapped identity plus the consumed state and code
     * so a test can replay them.
     *
     * @param array<string, mixed> $store the session the flows ride in
     * @return array{0: array{uid: string, email: ?string, verified: bool}, 1: string, 2: string}
     */
    private function dance(OAuthProvider $p, array &$store): array
    {
        $url = $p->start();
        [$code, $state] = $this->followAuthorize($url);
        $this->assertSame(self::queryOf($url)['state'], $state, 'the state rides to the IdP and back unchanged');
        $identity = $p->callback($state, $code);
        return [$identity, $state, $code];
    }

    /**
     * Mint a code by hand, the way a provider would: GET /authorize with the
     * given verifier's S256 challenge (or none), against the registered
     * redirect URI unless overridden.
     *
     * @param array<string, string> $stub
     */
    private function mintCode(array $stub, ?string $verifier, ?string $redirectUri = null): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $stub['client_id'],
            'redirect_uri' => $redirectUri ?? $stub['redirect_uri'],
            'state' => 'st',
        ];
        if ($verifier !== null) {
            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }
        [$status, $headers] = $this->fetch('GET', $stub['base'] . '/authorize?' . http_build_query($params));
        $this->assertSame(302, $status);
        return (string) self::queryOf((string) $headers['location'])['code'];
    }

    /**
     * A manual token exchange, the raw form the protocol defines. $overrides
     * replace or remove fields (null removes) for the negative paths.
     *
     * @param array<string, string> $stub
     * @param array<string, ?string> $overrides
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function exchange(array $stub, string $code, array $overrides = []): array
    {
        $form = array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $stub['client_id'],
            'client_secret' => $stub['client_secret'],
            'redirect_uri' => $stub['redirect_uri'],
            'code_verifier' => null,
        ], $overrides);
        $form = array_filter($form, static fn(?string $v): bool => $v !== null);
        return $this->fetch('POST', $stub['base'] . '/token', ['Accept: application/json'], http_build_query($form));
    }

    public function test_the_full_round_trip_maps_the_identity_and_consumes_the_state(): void
    {
        $stub = $this->startOAuthStub(self::PORT);
        $store = [];
        $p = new OAuthProvider('test', $this->config($stub), new Session($store));

        [$identity, $state, $code] = $this->dance($p, $store);

        $this->assertSame(['uid' => 'stub-user-1', 'email' => 'user@example.org', 'verified' => true], $identity);
        $this->assertSame([], $store[OAuthProvider::SESSION_KEY], 'the flow is consumed, the stash is empty');
        // The wire dance, exactly: authorize, exchange, user info, nothing else.
        $this->assertSame("GET /authorize\nPOST /token\nGET /userinfo\n", $this->oauthStubRequests());

        // A replay of the same callback finds no flow: the state was single-use.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no pending');
        $p->callback($state, $code);
    }

    public function test_the_stub_pins_the_redirect_uri_at_exchange(): void
    {
        $stub = $this->startOAuthStub(self::PORT);
        $code = $this->mintCode($stub, str_repeat('a', 43)); // PKCE is on: authorize needs a challenge

        [$status, , $body] = $this->exchange($stub, $code, [
            'redirect_uri' => 'http://127.0.0.1:' . self::PORT . '/attacker/callback',
        ]);

        $this->assertSame(400, $status);
        $this->assertStringContainsString('redirect_uri differs', $body, 'prefix and suffix tricks both die at exchange');
    }

    public function test_the_stub_enforces_pkce_at_every_step(): void
    {
        $stub = $this->startOAuthStub(self::PORT);
        $right = str_repeat('a', 43);
        $wrong = str_repeat('b', 43);

        // No challenge at all on an IdP that requires one
        [$status, , $body] = $this->fetch('GET', $stub['base'] . '/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $stub['client_id'],
            'redirect_uri' => $stub['redirect_uri'], 'state' => 'st',
        ]));
        $this->assertSame(400, $status);
        $this->assertStringContainsString('code_challenge', $body);

        // A challenge with the plain method instead of S256
        [$status, , $body] = $this->fetch('GET', $stub['base'] . '/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $stub['client_id'],
            'redirect_uri' => $stub['redirect_uri'], 'state' => 'st',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $right, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'plain',
        ]));
        $this->assertSame(400, $status);
        $this->assertStringContainsString('S256', $body);

        // The verifier missing at exchange
        [$status, , $body] = $this->exchange($stub, $this->mintCode($stub, $right));
        $this->assertSame(400, $status);
        $this->assertStringContainsString('code_verifier is required', $body);

        // The verifier present but wrong (the same length, a different value)
        [$status, , $body] = $this->exchange($stub, $this->mintCode($stub, $right), ['code_verifier' => $wrong]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('does not match', $body);

        // The control: the right verifier completes the exchange
        [$status, , $body] = $this->exchange($stub, $this->mintCode($stub, $right), ['code_verifier' => $right]);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('access_token', $body);
    }

    public function test_a_used_code_is_refused_a_second_time(): void
    {
        $stub = $this->startOAuthStub(self::PORT);
        $verifier = str_repeat('a', 43);
        $code = $this->mintCode($stub, $verifier);

        [$status] = $this->exchange($stub, $code, ['code_verifier' => $verifier]);
        $this->assertSame(200, $status);

        [$status, , $body] = $this->exchange($stub, $code, ['code_verifier' => $verifier]);
        $this->assertSame(400, $status, 'codes are single-use, a replayed exchange must fail');
        $this->assertStringContainsString('already-used', $body);
    }

    public function test_a_wrong_client_secret_fails_loud_without_leaking_the_code(): void
    {
        $stub = $this->startOAuthStub(self::PORT);
        $store = [];
        $p = new OAuthProvider('test', $this->config($stub, ['client_secret' => 'not-the-secret']), new Session($store));

        $url = $p->start();
        [$code] = $this->followAuthorize($url);

        try {
            $p->callback(self::queryOf($url)['state'], $code);
            $this->fail('expected the wrong secret to fail the flow');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage(), 'the status surfaces, no body detail');
            $this->assertStringNotContainsString($code, $e->getMessage(), 'never the code');
            $this->assertStringNotContainsString('stubtok', $e->getMessage(), 'never a token');
        }
    }

    public function test_a_poisoned_user_endpoint_redirect_is_never_followed(): void
    {
        $stub = $this->startOAuthStub(self::PORT, ['KIP_OAUTH_STUB_POISON' => '1']);
        $store = [];
        $p = new OAuthProvider('test', $this->config($stub), new Session($store));

        try {
            $this->dance($p, $store);
            $this->fail('expected the redirecting user endpoint to fail the flow');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('302', $e->getMessage(), 'a redirect is an error, named as one');
        }
        $this->assertStringNotContainsString('/leak', $this->oauthStubRequests(), 'the bearer token never reached the second hop');
        $this->assertStringEndsWith("GET /userinfo\n", $this->oauthStubRequests(), 'userinfo was the last request, no follow-up happened');
    }

    public function test_github_shape_uses_the_verified_primary_email(): void
    {
        // The GitHub web flow has no PKCE; /user reports no email, so the
        // emails endpoint decides. The alt address is verified but never
        // primary: it must lose to the primary one.
        $stub = $this->startOAuthStub(self::PORT, [
            'KIP_OAUTH_STUB_PKCE' => '0',
            'KIP_OAUTH_STUB_ALT_EMAIL' => 'alt@example.org',
        ]);
        $store = [];
        $p = new OAuthProvider('test', $this->config($stub, [
            'pkce' => false,
            'shape' => 'github',
            'user_url' => $stub['base'] . '/user',
            'emails_url' => $stub['base'] . '/user/emails',
        ]), new Session($store));

        [$identity] = $this->dance($p, $store);

        $this->assertSame(['uid' => 'stub-user-1', 'email' => 'user@example.org', 'verified' => true], $identity);
        // The token endpoint answered JSON here, which it only does for a
        // request carrying Accept: application/json: the client always sends it.
        $this->assertSame("GET /authorize\nPOST /token\nGET /user\nGET /user/emails\n", $this->oauthStubRequests());
    }

    public function test_an_all_unverified_github_mailbox_stays_unverified(): void
    {
        $stub = $this->startOAuthStub(self::PORT, [
            'KIP_OAUTH_STUB_PKCE' => '0',
            'KIP_OAUTH_STUB_VERIFIED' => '0',
        ]);
        $store = [];
        $p = new OAuthProvider('test', $this->config($stub, [
            'pkce' => false,
            'shape' => 'github',
            'user_url' => $stub['base'] . '/user',
            'emails_url' => $stub['base'] . '/user/emails',
        ]), new Session($store));

        [$identity] = $this->dance($p, $store);

        $this->assertSame('user@example.org', $identity['email'], 'the primary address is still reported');
        $this->assertFalse($identity['verified'], 'but unverified: the login-or-register policy will refuse it');
    }

    public function test_microsoft_shape_carries_no_verification_marker(): void
    {
        // The msgraph response has an id and a mailbox, and no verification
        // marker anywhere: verified is always false, so this shape can never
        // CREATE an account, only attach to one that already exists.
        $stub = $this->startOAuthStub(self::PORT);
        $store = [];
        $p = new OAuthProvider('test', $this->config($stub, [
            'shape' => 'msgraph',
            'user_url' => $stub['base'] . '/me',
        ]), new Session($store));

        [$identity] = $this->dance($p, $store);

        $this->assertSame(['uid' => 'stub-user-1', 'email' => 'user@example.org', 'verified' => false], $identity);
    }

    public function test_the_token_endpoint_answers_form_encoded_without_the_accept_header(): void
    {
        // The GitHub default: without Accept: application/json the endpoint
        // answers a form-encoded string no JSON parser can read. That is why
        // the client sends the header on every exchange (pinned by the github
        // shape test above, whose dance parsed JSON).
        $stub = $this->startOAuthStub(self::PORT);
        $verifier = str_repeat('a', 43);
        $code = $this->mintCode($stub, $verifier);

        $form = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $stub['client_id'],
            'client_secret' => $stub['client_secret'],
            'redirect_uri' => $stub['redirect_uri'],
            'code_verifier' => $verifier,
        ]);
        [$status, $headers, $body] = $this->fetch('POST', $stub['base'] . '/token', ['Content-Type: application/x-www-form-urlencoded'], $form);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('application/x-www-form-urlencoded', (string) $headers['content-type']);
        $this->assertMatchesRegularExpression('/^access_token=stubtok\./', $body);
        $this->assertNull(json_decode($body, true), 'useless to a JSON parser: the header is not optional');
    }
}
