<?php // src/Auth/OAuthProvider.php

declare(strict_types=1);
namespace Kip\Auth;

use Kip\Session;

/**
 * One generic authorization-code client for "sign in with ..." providers,
 * driven by per-provider presets (Google, GitHub, Microsoft) plus explicit
 * config for anything else. Session-bound single-use state, PKCE S256 where
 * the provider supports it, user-info mapped to a provider uid plus email.
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

    /**
     * Endpoint presets. A config entry with the same key wins, so an app can
     * point a preset at a proxy or override a scope without redefining the
     * rest. 'shape' picks the user-info mapper: oidc (sub, email,
     * email_verified), github (id, plus the emails endpoint for a verified
     * primary address), msgraph (id, mail/userPrincipalName; the response
     * carries no verification marker, so verified is always false and the
     * provider can never CREATE an account, only attach to one).
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
    ];

    private const REQUIRED = ['client_id', 'client_secret', 'redirect_uri', 'authorize_url', 'token_url', 'user_url'];

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
        foreach (self::REQUIRED as $key) {
            $value = $this->merged[$key] ?? null;
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException("OAuth provider '{$name}' config key '{$key}' is required");
            }
        }
        foreach (['authorize_url', 'token_url', 'user_url', 'redirect_uri'] as $url) {
            self::assertEndpoint($this->merged[$url], "{$name} {$url}");
        }
        self::assertTransport(extension_loaded('curl'), extension_loaded('openssl'), (int) ini_get('allow_url_fopen') === 1);
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

    /** @param array<string, mixed> $flow */
    private function exchange(string $code, array $flow): string
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->merged['client_id'],
            'client_secret' => $this->merged['client_secret'],
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
        return $parsed['access_token'];
    }

    /** @return array{uid: string, email: ?string, verified: bool} */
    private function identity(#[\SensitiveParameter] string $token): array
    {
        [$status, $body] = $this->request('GET', (string) $this->merged['user_url'], [
            'Accept: application/json',
            "Authorization: Bearer {$token}",
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
            'github' => $this->githubIdentity($token, $data),
            'msgraph' => $this->msIdentity($data),
            default => $this->oidcIdentity($data),
        };
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

    private static function emailOf(mixed $email): ?string
    {
        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * @param list<string> $headers
     * @return array{int, string} the HTTP status and body
     */
    private function request(string $method, string $url, array $headers, ?string $form): array
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
                CURLOPT_TIMEOUT => 20,
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
                'timeout' => 20,
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
