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
    private ?\Kip\Auth\OAuthHealth $oauthHealth = null;

    public function __construct(
        private Auth $auth,
        private Session $session,
        private Request $request,
        private App $app,
    ) {}

    /**
     * The configured provider names whose credentials currently pass the
     * readiness probe: the list the login page renders as buttons.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return $this->health()->ready(); // the gate's own notion of ready, one implementation
    }

    /** GET /oauth/start/{provider}: away to the provider, principal recorded. */
    public function start(string $provider): Response
    {
        $client = $this->client($provider);
        if ($client === null) return new Response('Unknown provider', 404);
        return $this->away($client->start($this->principal()));
    }

    /** GET or POST /oauth/callback/{provider}: the login-or-register policy. POST is Apple's form_post. */
    #[\Kip\Routing\Get]
    #[\Kip\Routing\Post]
    #[\Kip\Routing\CrossSite]
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
     * Every configured provider entry with the redirect URI pinned: the one
     * shape the flow (client()), the gate (health()), and the views (enabled())
     * all read, so a provider is offered, routed, and probed on identical facts.
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        $oauth = $this->app->config('oauth');
        $providers = is_array($oauth) ? ($oauth['providers'] ?? []) : [];
        $base = rtrim((string) $this->app->config('base_url', ''), '/');
        $entries = [];
        foreach ($providers as $name => $entry) {
            if (OAuthProvider::configured((string) $name, $entry)) {
                $entry['redirect_uri'] = $base . '/oauth/callback/' . $name;
                $entries[(string) $name] = $entry;
            }
        }
        return $entries;
    }

    private function health(): \Kip\Auth\OAuthHealth
    {
        if ($this->oauthHealth !== null) return $this->oauthHealth;
        $dsn = $this->app->config('cache_db')['dsn'] ?? null;
        $cache = null;
        if (is_string($dsn) && $dsn !== '' && !str_contains($dsn, ':memory:')) {
            try { $cache = new \Kip\Database($dsn); } catch (\Throwable) { $cache = null; } // an unwritable cache degrades to per-request memory, never a failed login page
        }
        $oauth = $this->app->config('oauth');
        return $this->oauthHealth = new \Kip\Auth\OAuthHealth($cache, $this->entries(), !is_array($oauth) || ($oauth['probe'] ?? null) !== false);
    }

    /**
     * The provider client for $provider, or null when it is not configured.
     * A configured-but-broken entry fails loud here (the framework's
     * exception surfaces), instead of quietly pretending to be a 404.
     */
    private function client(string $provider): ?OAuthProvider
    {
        $entry = $this->entries()[$provider] ?? null;
        if ($entry === null) return null;
        return new OAuthProvider($provider, $entry, $this->session);
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
