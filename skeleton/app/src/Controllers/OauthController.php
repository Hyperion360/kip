<?php
namespace App\Controllers;

use Kip\{App, Auth, Auth\OAuthProvider, Http\Request, Http\Response, Session};

/**
 * "Sign in with a provider": start() sends the visitor to the provider with
 * a session-bound single-use state, callback() maps the returned identity
 * through the login-or-register policy. Every failure lands on the login
 * page with an enum-only flag, never a rendered page at the callback URL:
 * that URL carries the authorization code and state in its query string, and
 * the response keeps them out of the next hop's Referer and every cache on
 * the way (Referrer-Policy: no-referrer, Cache-Control: no-store).
 */
final class OauthController
{
    public function __construct(
        private Auth $auth,
        private Session $session,
        private Request $request,
        private App $app,
    ) {}

    /**
     * The configured providers, config order: an entry with both a client
     * id and a client secret. Anything else (absent, or secrets left null)
     * is unconfigured and neither offered on the login page nor routable.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $enabled = [];
        foreach ($this->providers() as $name => $entry) {
            if (self::configured($name, $entry)) $enabled[] = $name;
        }
        return $enabled;
    }

    /** GET /oauth/start/{provider}: away to the provider, principal recorded. */
    public function start(string $provider): Response
    {
        $client = $this->client($provider);
        if ($client === null) return new Response('Unknown provider', 404);
        return $this->away($client->start($this->principal()));
    }

    /** GET /oauth/callback/{provider}: the login-or-register policy. */
    public function callback(string $provider): Response
    {
        $client = $this->client($provider);
        if ($client === null) return new Response('Unknown provider', 404);
        $principal = $this->principal();
        try {
            $identity = $client->callback($this->request->str('state'), $this->request->str('code'), $principal);
            if ($principal !== null) {
                // A signed-in start attaches the identity to THIS account; the
                // provider flow already refused a principal change mid-flight.
                // Ownership never transfers: a provider linked to another user refuses.
                return $this->auth->linkOAuthIdentity($principal, $provider, $identity['uid'])
                    ? $this->away('/')
                    : $this->refused('refused');
            }
            $result = $this->auth->loginOrRegisterOAuth($provider, $identity['uid'], $identity['email'], $identity['verified']);
            return $result === null ? $this->refused('refused') : $this->away('/');
        } catch (\Throwable) {
            // The flow failed (bad state, expiry, provider error, missing
            // migration): fail closed on the clean login URL, enum only.
            return $this->refused('failed');
        }
    }

    /**
     * The provider client for $provider, or null when it is not configured.
     * The redirect URI is built from base_url at BOTH ends of the flow, never
     * from the request, and must match the provider's registration exactly.
     * A configured-but-broken entry fails loud here (the framework's
     * exception surfaces), instead of quietly pretending to be a 404.
     */
    private function client(string $provider): ?OAuthProvider
    {
        $entry = $this->providers()[$provider] ?? null;
        if (!self::configured($provider, $entry)) return null;
        $entry['redirect_uri'] = rtrim((string) $this->app->config('base_url', ''), '/') . '/oauth/callback/' . $provider;
        return new OAuthProvider($provider, $entry, $this->session);
    }

    /** @return array<string, mixed> the oauth.providers config map, empty when absent */
    private function providers(): array
    {
        $oauth = $this->app->config('oauth');
        $providers = is_array($oauth) ? ($oauth['providers'] ?? []) : [];
        return is_array($providers) ? $providers : [];
    }

    /** @param array<string, mixed>|null $entry */
    private static function configured(string $name, mixed $entry): bool
    {
        return is_string($name) && is_array($entry)
            && is_string($entry['client_id'] ?? null) && $entry['client_id'] !== ''
            && is_string($entry['client_secret'] ?? null) && $entry['client_secret'] !== '';
    }

    /** The logged-in user id, or null for a guest: what a flow binds to. */
    private function principal(): ?int
    {
        if (!$this->auth->sessionValid()) return null;
        $id = $this->session->peek('user_id');
        if (is_int($id)) return $id;
        // App-authored session stores may hold a numeric string; only an
        // integer-shaped one counts (the audit log's own rule).
        return is_string($id) && preg_match('/^\d+$/', $id) === 1 ? (int) $id : null;
    }

    /** A redirect that never leaks onward: no Referer, nothing cached. */
    private function away(string $to): Response
    {
        return $this->hardened(Response::redirect($to));
    }

    private function refused(string $flag): Response
    {
        return $this->hardened(Response::redirect('/auth/login?oauth=' . $flag));
    }

    private function hardened(Response $response): Response
    {
        return $response
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store');
    }
}
