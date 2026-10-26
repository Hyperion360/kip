<?php // src/Auth/OAuthProvider.php

declare(strict_types=1);
namespace Kip\Auth;

use Kip\Session;

/**
 * One generic authorization-code client for "sign in with ..." providers,
 * driven by per-provider presets (Google, GitHub, Microsoft, Discord,
 * Facebook, Apple) plus explicit config for anything else. Session-bound
 * single-use state, PKCE S256 where the provider supports it, user-info
 * mapped to a provider uid plus email (Apple maps the id_token instead: no
 * user-info endpoint exists).
 *
 * The flow never trusts the request for the redirect URI: start() sends the
 * configured one, the flow pins it, and the token exchange repeats it
 * verbatim. Neither transport follows redirects (a user-info endpoint must
 * never be able to forward a bearer token elsewhere), and the access token
 * lives only in locals for the two requests that need it: never in the
 * session, never in an exception, never in a log line.
 */
final class OAuthProvider
{
    /** Session key under which pending flows wait for their callback. */
    public const SESSION_KEY = 'oauth_flows';
    /** A pending flow older than this is refused (seconds). */
    public const STATE_TTL = 600;
    /** Concurrent pending flows kept (multi-tab); the oldest is evicted beyond this. */
    public const MAX_PENDING = 3;
    /** Seconds a probe may take: the readiness gate rides page renders, so its network half is tight. */
    public const PROBE_TIMEOUT = 3;

    /**
     * Endpoint presets. A config entry with the same key wins, so an app can
     * point a preset at a proxy or override a scope without redefining the
     * rest. 'shape' picks the identity mapper: oidc (sub, email,
     * email_verified), github (id, plus the emails endpoint for a verified
     * primary address), msgraph (id, mail/userPrincipalName; the response
     * carries no verification marker, so verified is always false and the
     * provider can never CREATE an account, only attach to one), discord
     * (id, email, its own verified flag; without the email scope the
     * provider is attach-only), facebook (id, email; it returns only a
     * confirmed address, so presence marks verification), apple (the
     * identity decodes from the id_token the token endpoint returns; no
     * user-info call exists).
     */
    public const PRESETS = [
        'google' => [
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'user_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid email',
            'pkce' => true,
            'shape' => 'oidc',
        ],
        'github' => [
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'user_url' => 'https://api.github.com/user',
            'emails_url' => 'https://api.github.com/user/emails',
            'scope' => 'read:user user:email',
            'pkce' => false,
            'shape' => 'github',
        ],
        'microsoft' => [
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'user_url' => 'https://graph.microsoft.com/v1.0/me',
            'scope' => 'openid email profile',
            'pkce' => true,
            'shape' => 'msgraph',
        ],
        'discord' => [
            'authorize_url' => 'https://discord.com/oauth2/authorize',
            'token_url' => 'https://discord.com/api/oauth2/token',
            'user_url' => 'https://discord.com/api/users/@me',
            'scope' => 'identify email',
            'pkce' => true,
            'shape' => 'discord',
        ],
        'facebook' => [
            // Versionless endpoints: they outlive any pinned graph version and
            // are the documented shape for the login flow.
            'authorize_url' => 'https://www.facebook.com/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/oauth/access_token',
            'user_url' => 'https://graph.facebook.com/me?fields=id,email',
            'scope' => 'public_profile,email', // Facebook scopes are comma-separated
            'pkce' => false,
            'shape' => 'facebook',
        ],
        'apple' => [
            // No user_url: Apple publishes no user-info endpoint, the id_token is
            // the identity. response_mode MUST be form_post while the email scope
            // is requested: Apple's live authorize endpoint refuses the query
            // spelling for scope=email with invalid_request (its documented rule,
            // corroborated by several integrations' reports). The cross-site POST
            // drops the session cookie under SameSite=Lax, so hosts enabling Sign
            // in with Apple set session_cookie_samesite to None, which forces the
            // Secure flag (see SessionStarter). VERIFY AT SETUP against a live
            // Services ID: a stub can never prove Apple's side.
            'authorize_url' => 'https://appleid.apple.com/auth/authorize',
            'token_url' => 'https://appleid.apple.com/auth/token',
            'scope' => 'email',
            'pkce' => false,
            'shape' => 'apple',
            'client_secret_mode' => 'apple_jwt',
            'id_token_iss' => 'https://appleid.apple.com',
            'params' => ['response_mode' => 'form_post'],
        ],
    ];

    private const REQUIRED = ['client_id', 'client_secret', 'redirect_uri', 'authorize_url', 'token_url', 'user_url'];
    /** The apple shape: the client secret is minted (AppleSecret), the user_url does not exist, the signing key rides instead. */
    private const REQUIRED_APPLE = ['client_id', 'redirect_uri', 'authorize_url', 'token_url', 'apple_team_id', 'apple_key_id'];

    /** @var array<string, mixed> preset merged with config, config winning */
    private array $merged;

    /**
     * @param array<string, mixed> $config client_id, client_secret, redirect_uri
     *        (absolute https URL; http only on a loopback host for local
     *        development), plus any preset key to override
     */
    public function __construct(
        private string $name,
        #[\SensitiveParameter] array $config,
        private Session $session,
    ) {
        $this->merged = array_merge(self::PRESETS[$name] ?? [], $config);
        $isApple = self::isApple($name, $this->merged);
        foreach ($isApple ? self::REQUIRED_APPLE : self::REQUIRED as $key) {
            $value = $this->merged[$key] ?? null;
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException("OAuth provider '{$name}' config key '{$key}' is required");
            }
        }
        if ($isApple && self::keyOf($this->merged) === null) {
            throw new \InvalidArgumentException("OAuth provider '{$name}' needs 'apple_key' (PEM text) or 'apple_key_file'");
        }
        foreach ($isApple
            ? ['authorize_url', 'token_url', 'redirect_uri']
            : ['authorize_url', 'token_url', 'user_url', 'redirect_uri'] as $url) {
            self::assertEndpoint($this->merged[$url], "{$name} {$url}");
        }
        self::assertTransport(extension_loaded('curl'), extension_loaded('openssl'), (int) ini_get('allow_url_fopen') === 1);
    }

    /** True when this name+entry resolves to the Apple id-token flow.
     * @param array<string, mixed> $entry */
    private static function isApple(string $name, array $entry): bool
    {
        $preset = self::PRESETS[$name] ?? [];
        return ($entry['shape'] ?? $preset['shape'] ?? '') === 'apple'
            || ($entry['client_secret_mode'] ?? $preset['client_secret_mode'] ?? '') === 'apple_jwt';
    }

    /** The PEM text: literal, or read from the file, null when neither.
     * @param array<string, mixed> $merged */
    private static function keyOf(array $merged): ?string
    {
        // One resolver (AppleSecret::pem) owns the spellings and precedence; an
        // unreadable configured file is reported as exactly that, not as
        // "missing key material".
        try {
            return AppleSecret::pem($merged);
        } catch (\RuntimeException) {
            return null; // unreadable at construction or readiness: treated as absent, mint() names the cause when it runs
        }
    }

    /**
     * The full-credentials check the readiness gate and controllers share: the
     * client id plus, per mode, either the client secret or Apple's team, key
     * id, and key material. A pure config probe: the presence of a key file
     * path counts without reading it, only construction verifies the bytes.
     */
    public static function configured(string $name, mixed $entry): bool
    {
        if (!is_array($entry)) return false;
        $clientId = $entry['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') return false;
        if (self::isApple($name, $entry)) {
            $key = $entry['apple_key'] ?? null;
            $keyFile = $entry['apple_key_file'] ?? null;
            return ((is_string($key) && $key !== '') || (is_string($keyFile) && $keyFile !== ''))
                && is_string($entry['apple_team_id'] ?? null) && $entry['apple_team_id'] !== ''
                && is_string($entry['apple_key_id'] ?? null) && $entry['apple_key_id'] !== '';
        }
        return is_string($entry['client_secret'] ?? null) && $entry['client_secret'] !== '';
    }

    /**
     * Transport gate, a pure function of the transport facts (a loaded
     * extension cannot be unloaded at runtime), mirroring S3::assertTransport.
     */
    public static function assertTransport(bool $curl, bool $openssl, bool $allowUrlFopen): void
    {
        if (!$curl && !$openssl) {
            throw new \RuntimeException('OAuth needs ext-curl or ext-openssl (streams) for HTTPS');
        }
        if (!$curl && !$allowUrlFopen) {
            throw new \RuntimeException('OAuth without ext-curl needs allow_url_fopen enabled for stream transports');
        }
    }

    /**
     * Every provider endpoint and the redirect URI must be https, except on
     * a loopback host where http serves local development and the test stub.
     */
    public static function assertEndpoint(mixed $url, string $what): void
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        $scheme = parse_url((string) $url, PHP_URL_SCHEME);
        // Only http may bend the https rule, and only on a loopback host:
        // ftp://localhost or any other scheme has no development use case.
        $loopbackHttp = is_string($host)
            && in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost'], true)
            && $scheme === 'http';
        if ($scheme !== 'https' && !$loopbackHttp) {
            throw new \RuntimeException("{$what} must be an https URL (http is allowed only on a loopback host)");
        }
    }

    /**
     * The readiness gate's credential check. Generic providers get a sentinel
     * authorization_code POST: the code is bogus for everyone, so invalid_client
     * is the only answer that indicts the credentials; any other refusal proves
     * they were accepted. Facebook hides that distinction behind error objects,
     * so it gets its documented app-token endpoint instead (200 = healthy; the
     * token is discarded in locals like every other). 429 and transport trouble
     * return null: indeterminate, the health layer keeps the previous state.
     *
     * @return bool|null true healthy, false unhealthy, null indeterminate
     */
    public function probe(): ?bool
    {
        try {
            if (($this->merged['shape'] ?? 'oidc') === 'facebook') {
                // The app-token endpoint IS the configured token URL with the
                // client_credentials grant, so tests can point it at the stub.
                // POSTed as a form body: a GET would write the app secret into
                // the provider's request logs and any proxy on the way.
                [$status] = $this->request('POST', (string) $this->merged['token_url'], [
                    'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
                ], http_build_query(['grant_type' => 'client_credentials',
                    'client_id' => $this->merged['client_id'], 'client_secret' => $this->secret()]),
                    self::PROBE_TIMEOUT);
                if ($status === 429 || $status >= 500) return null;
                if ($status >= 200 && $status < 300) return true;
                if ($status >= 400) return false;
                return null;
            }
            [$status, $body] = $this->request('POST', (string) $this->merged['token_url'], [
                'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
            ], http_build_query([
                'grant_type' => 'authorization_code',
                'code' => bin2hex(random_bytes(16)),
                'redirect_uri' => $this->merged['redirect_uri'],
                'client_id' => $this->merged['client_id'],
                'client_secret' => $this->secret(),
            ]), self::PROBE_TIMEOUT);
        } catch (\RuntimeException) {
            return null;
        }
        // 429 and any 5xx are indeterminate before the body is read: an outage
        // that echoes an upstream invalid_client is not a credential verdict.
        if ($status === 429 || $status >= 500) return null;
        $parsed = json_decode($body, true);
        $error = is_array($parsed) && is_string($parsed['error'] ?? null) ? $parsed['error'] : null;
        if ($error === 'invalid_client') return false;
        if ($status >= 200 && $status < 300) return true;
        // A healthy verdict needs an OAuth-shaped refusal (a JSON error naming the
        // code, the grant, or the request): a bare 404, an HTML error page, or any
        // non-OAuth 4xx proves nothing about the credentials and stays null.
        if ($status >= 400 && $error !== null) return true; // 5xx already returned null above
        return null;
    }

    /**
     * Begin the flow: stash the single-use state (plus the PKCE verifier and
     * the redirect pin) in the session and return the authorize URL the
     * visitor is redirected to. $principal is the logged-in user id at start
     * time (null for a guest): the callback refuses the flow when it changed,
     * so a pending flow can never cross a login or logout.
     */
    public function start(?int $principal = null): string
    {
        $state = bin2hex(random_bytes(32));
        // PKCE defaults ON for a custom provider (no preset): the challenge is
        // one extra parameter most endpoints ignore, and a provider that cannot
        // take it fails visibly, so 'pkce' => false is an explicit opt-out.
        $verifier = ($this->merged['pkce'] ?? true) ? bin2hex(random_bytes(48)) : null; // 96 chars, inside the RFC 7636 range
        $params = [
            'response_type' => 'code',
            'client_id' => $this->merged['client_id'],
            'redirect_uri' => $this->merged['redirect_uri'],
            'state' => $state,
        ];
        $scope = trim((string) ($this->merged['scope'] ?? ''));
        if ($scope !== '') {
            $params['scope'] = $scope; // absent: a custom provider without presets uses the provider's defaults
        }
        if ($verifier !== null) {
            $params['code_challenge'] = self::challenge($verifier);
            $params['code_challenge_method'] = 'S256';
        }
        /** @var mixed $extra */
        foreach ((array) ($this->merged['params'] ?? []) as $key => $extra) {
            if (is_string($key) && (is_string($extra) || is_int($extra))) $params[$key] = $extra;
        }
        $flows = $this->flows();
        $flows[] = [
            'state' => $state,
            'verifier' => $verifier,
            'provider' => $this->name,
            'redirect_uri' => $this->merged['redirect_uri'],
            'principal' => $principal,
            'created' => time(),
        ];
        if (count($flows) > self::MAX_PENDING) {
            array_shift($flows); // the oldest tab loses its flow, it can simply start again
        }
        $this->session->set(self::SESSION_KEY, $flows);
        $url = (string) $this->merged['authorize_url'];
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Complete the flow: consume the state (single-use; an unknown state
     * consumes nothing but itself), exchange the code over the pinned
     * redirect URI, and map the user info. The access token never leaves
     * this method's locals.
     *
     * @return array{uid: string, email: ?string, verified: bool}
     */
    public function callback(string $state, #[\SensitiveParameter] string $code, ?int $principal = null): array
    {
        $flow = $this->consumeFlow($state);
        if ($flow === null) {
            throw new \RuntimeException("no pending {$this->name} sign-in flow matches this callback");
        }
        if (($flow['provider'] ?? '') !== $this->name) {
            throw new \RuntimeException('the callback provider differs from the flow, start the sign-in again');
        }
        if (time() - (int) $flow['created'] > self::STATE_TTL) {
            throw new \RuntimeException('the sign-in flow expired, start again');
        }
        if (($flow['redirect_uri'] ?? '') !== $this->merged['redirect_uri']) {
            throw new \RuntimeException('the configured redirect URI changed during the flow, start again');
        }
        if (($flow['principal'] ?? null) !== $principal) {
            throw new \RuntimeException('the signed-in account changed during the flow, start again');
        }
        return $this->identity($this->exchange($code, $flow));
    }

    /** RFC 7636 S256: base64url(SHA256(verifier)), padding stripped. */
    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return list<array<string, mixed>> the pending flows, shape-corrected */
    private function flows(): array
    {
        /** @var mixed $stored */
        $stored = $this->session->get(self::SESSION_KEY);
        if (!is_array($stored)) return [];
        $flows = [];
        foreach ($stored as $entry) {
            if (is_array($entry) && is_string($entry['state'] ?? null)) $flows[] = $entry;
        }
        return $flows;
    }

    /**
     * Removes and returns the flow whose state matches (hash_equals, and
     * only entries of this class's making). An unknown state leaves every
     * other pending flow alone: nothing an attacker can send cancels
     * another tab's login.
     *
     * @return array<string, mixed>|null
     */
    private function consumeFlow(string $state): ?array
    {
        $flows = $this->flows();
        foreach ($flows as $i => $entry) {
            if (hash_equals((string) $entry['state'], $state)) {
                unset($flows[$i]);
                $this->session->set(self::SESSION_KEY, array_values($flows));
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $flow
     * @return array<string, mixed> the parsed token response (access_token, and the id_token where the provider issues one)
     */
    private function exchange(string $code, array $flow): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->merged['client_id'],
            'client_secret' => $this->secret(),
            'redirect_uri' => (string) $flow['redirect_uri'],
        ];
        if (is_string($flow['verifier'] ?? null) && $flow['verifier'] !== '') {
            $form['code_verifier'] = (string) $flow['verifier'];
        }
        [$status, $body] = $this->request('POST', (string) $this->merged['token_url'], [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($form));
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("the {$this->name} token endpoint answered HTTP {$status}");
        }
        try {
            $parsed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException("the {$this->name} token response is not JSON");
        }
        if (!is_array($parsed) || !is_string($parsed['access_token'] ?? null) || $parsed['access_token'] === '') {
            throw new \RuntimeException("the {$this->name} token response carries no access token");
        }
        return $parsed;
    }

    /** The client secret as sent: the configured string, or the ES256 JWT minted for this request. */
    private function secret(): string
    {
        // The same predicate the constructor and configured() use, so a custom
        // entry spelled 'shape' => 'apple' without the mode still mints.
        if (self::isApple($this->name, $this->merged)) {
            return AppleSecret::mint($this->merged);
        }
        return (string) $this->merged['client_secret'];
    }

    /**
     * @param array<string, mixed> $token the parsed token response
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function identity(#[\SensitiveParameter] array $token): array
    {
        if (self::isApple($this->name, $this->merged)) {
            return $this->appleIdentity($token); // no user-info call exists for Apple
        }
        $bearer = (string) ($token['access_token'] ?? '');
        [$status, $body] = $this->request('GET', (string) $this->merged['user_url'], [
            'Accept: application/json',
            "Authorization: Bearer {$bearer}",
            'User-Agent: kip-oauth',
        ], null);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("the {$this->name} user endpoint answered HTTP {$status}");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("the {$this->name} user response is not JSON");
        }
        return match ((string) ($this->merged['shape'] ?? 'oidc')) {
            'github' => $this->githubIdentity($bearer, $data),
            'msgraph' => $this->msIdentity($data),
            'discord' => $this->discordIdentity($data),
            'facebook' => $this->facebookIdentity($data),
            default => $this->oidcIdentity($data),
        };
    }

    /**
     * Apple publishes no user-info endpoint: the identity rides the id_token JWT
     * inside the token response. That token arrived over the direct
     * server-to-server code exchange, so per OIDC Core 3.1.3.7 the TLS channel
     * stands in for signature verification; what is enforced is the exact issuer,
     * our own client id as audience, freshness, and the subject. No front-channel
     * id_token is ever read, so there is no impersonation path through the browser.
     *
     * @param array<string, mixed> $token
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function appleIdentity(array $token): array
    {
        $jwt = $token['id_token'] ?? null;
        if (!is_string($jwt) || substr_count($jwt, '.') !== 2) {
            throw new \RuntimeException("the {$this->name} token response carries no id_token");
        }
        [, $payload, ] = explode('.', $jwt, 3);
        $decoded = base64_decode(strtr($payload, '-_', '+/') . str_pad('', (4 - strlen($payload) % 4) % 4, '='), true);
        try {
            $claims = json_decode((string) $decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException("the {$this->name} id_token payload is not JSON");
        }
        if (!is_array($claims)) {
            throw new \RuntimeException("the {$this->name} id_token payload is not a claim set");
        }
        $iss = $claims['iss'] ?? null;
        if (!is_string($iss) || !hash_equals((string) ($this->merged['id_token_iss'] ?? 'https://appleid.apple.com'), $iss)) {
            throw new \RuntimeException("the {$this->name} id_token names a foreign issuer");
        }
        $aud = $claims['aud'] ?? null;
        if (!is_string($aud) || !hash_equals((string) $this->merged['client_id'], $aud)) {
            throw new \RuntimeException("the {$this->name} id_token names a different audience");
        }
        $exp = $claims['exp'] ?? null;
        if (!is_numeric($exp) || (int) $exp < time()) {
            throw new \RuntimeException("the {$this->name} id_token is expired");
        }
        $uid = $claims['sub'] ?? null;
        if (!is_string($uid) || $uid === '') {
            throw new \RuntimeException("the {$this->name} id_token carries no subject");
        }
        return [
            'uid' => $uid,
            'email' => self::emailOf($claims['email'] ?? null),
            'verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function oidcIdentity(array $data): array
    {
        $uid = $data['sub'] ?? null;
        if (!is_string($uid) || $uid === '') {
            throw new \RuntimeException("the {$this->name} user response carries no subject id");
        }
        return [
            'uid' => $uid,
            'email' => self::emailOf($data['email'] ?? null),
            // Some providers have shipped the boolean as a string; both spell.
            'verified' => filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function githubIdentity(#[\SensitiveParameter] string $token, array $data): array
    {
        $uid = $data['id'] ?? null;
        if (!is_int($uid) && !is_string($uid)) {
            throw new \RuntimeException("the {$this->name} user response carries no id");
        }
        $email = self::emailOf($data['email'] ?? null);
        if ($email === null && is_string($this->merged['emails_url'] ?? null) && $this->merged['emails_url'] !== '') {
            // /user/email is null for most accounts; the emails endpoint is
            // the only place GitHub reports which address is primary and
            // verified. Only a verified primary address counts as verified.
            [$status, $body] = $this->request('GET', $this->merged['emails_url'], [
                'Accept: application/json',
                "Authorization: Bearer {$token}",
                'User-Agent: kip-oauth',
            ], null);
            if ($status >= 200 && $status < 300) {
                $list = json_decode($body, true);
                $primary = null;
                foreach (is_array($list) ? $list : [] as $entry) {
                    if (!is_array($entry) || !is_string($entry['email'] ?? null)) continue;
                    if (filter_var($entry['primary'] ?? false, FILTER_VALIDATE_BOOL)) {
                        $primary = $entry;
                        if (filter_var($entry['verified'] ?? false, FILTER_VALIDATE_BOOL)) {
                            return ['uid' => (string) $uid, 'email' => $entry['email'], 'verified' => true];
                        }
                    }
                }
                if ($primary !== null) {
                    return ['uid' => (string) $uid, 'email' => (string) $primary['email'], 'verified' => false];
                }
            }
        }
        return ['uid' => (string) $uid, 'email' => $email, 'verified' => false];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function msIdentity(array $data): array
    {
        $uid = $data['id'] ?? null;
        if (!is_string($uid) || $uid === '') {
            throw new \RuntimeException("the {$this->name} user response carries no id");
        }
        // mail first (the mailbox), userPrincipalName as fallback. No
        // verification marker exists in this response: verified stays false,
        // so this provider can only attach to an existing account.
        return [
            'uid' => $uid,
            'email' => self::emailOf($data['mail'] ?? null) ?? self::emailOf($data['userPrincipalName'] ?? null),
            'verified' => false,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function discordIdentity(array $data): array
    {
        $uid = $data['id'] ?? null;
        if (!is_int($uid) && !is_string($uid)) {
            throw new \RuntimeException("the {$this->name} user response carries no id");
        }
        // Discord's own `verified` flag is its email-verification marker; without
        // the email scope both keys are absent and the provider is attach-only.
        return [
            'uid' => (string) $uid,
            'email' => self::emailOf($data['email'] ?? null),
            'verified' => filter_var($data['verified'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{uid: string, email: ?string, verified: bool}
     */
    private function facebookIdentity(array $data): array
    {
        $uid = $data['id'] ?? null;
        if (!is_int($uid) && !is_string($uid)) {
            throw new \RuntimeException("the {$this->name} user response carries no id");
        }
        // Facebook documents that it returns an email only when the person has a
        // confirmed address, and it ships no verification marker: presence is it.
        $email = self::emailOf($data['email'] ?? null);
        return ['uid' => (string) $uid, 'email' => $email, 'verified' => $email !== null];
    }

    private static function emailOf(mixed $email): ?string
    {
        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * @param list<string> $headers
     * @return array{int, string} the HTTP status and body
     */
    private function request(string $method, string $url, array $headers, ?string $form, int $timeout = 20): array
    {
        if (extension_loaded('curl')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException("cannot initialize curl for the {$this->name} request");
            }
            $opts = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false, // a redirect must never forward a bearer token
            ];
            if ($form !== null) {
                $opts[CURLOPT_POSTFIELDS] = $form;
            }
            curl_setopt_array($handle, $opts);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($body === false || $status === 0) {
                throw new \RuntimeException("the {$this->name} request failed: {$error}");
            }
        } else {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $form ?? '',
                'ignore_errors' => true,
                'timeout' => $timeout,
                'follow_location' => 0, // same rule as curl: no redirects
            ]]);
            $body = @file_get_contents($url, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) $status = (int) $m[1];
            }
            if ($body === false && $status === 0) {
                throw new \RuntimeException("the {$this->name} request failed: no HTTP response");
            }
        }
        if ($status >= 300 && $status < 400) {
            throw new \RuntimeException("the {$this->name} endpoint answered HTTP {$status} (redirect), which OAuth endpoints must not");
        }
        return [$status, (string) $body];
    }
}
