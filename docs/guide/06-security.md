# 6. Security

Kip's security batteries are all in `Kip\App::process()`
(`src/App.php`), applied before your controller runs, not something you
opt into per route.

## Two CSRF lanes

Every non-`GET`/`HEAD` request is checked, but *how* depends on whether the
route is `#[Auth]`-protected:

**`#[Auth]` routes, session-bound token, mandatory.** The session's CSRF
token (`Session::csrfToken()`) must be submitted as `_token` in the POST
body. A missing or wrong token is a flat **403**, no exceptions. This is
ambient authority (a logged-in session) at stake.

```php
<input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
```

**Guest routes, same-origin proof, no token.** A route without `#[Auth]`
has no session to bind a token to (an anonymous visitor hasn't logged in),
so it's protected differently: the kernel checks the `Sec-Fetch-Site`
header, falling back to `Origin`/`Referer`, against the request's own
`Host`. A cross-site request fails this and gets 403; a same-origin form
submission, including a guest comment form with no `_token` field at all
, passes.

The one deliberate gap, in the framework's own words (`README.md`; the
check itself lives in `App::sameOriginProof()`):

> Guest-facing forms (e.g. comments) are protected by same-origin
> verification instead of a CSRF token, the kernel checks
> `Sec-Fetch-Site`, falling back to `Origin`/`Referer`, against the
> request's own host. A request carrying *none* of those headers is
> accepted rather than rejected: legacy browsers and privacy-hardened
> clients (header-stripping proxies, some mobile browsers) match that same
> signature, and rejecting them outright would break real guests. This is
> an accepted risk, not an oversight, on guest-only routes the residual
> exposure is unwanted-but-unauthenticated submissions (spam-shaped, not a
> state-changing attack against an authenticated session), and it's owned
> by the comment-throttle TODO rather than fixed here.
>
> Authenticated routes (login, post create/edit) still require a
> session-bound CSRF token in the POST body; the origin check above is the
> guest-only fallback where no session exists yet to hold a token. Note the
> login form itself carries the same residual: an attacker with neither
> `Sec-Fetch-Site` nor `Origin`/`Referer` could submit a cross-site login
> attempt (login-CSRF) that authenticates the *attacker's* session, not the
> victim's, low-value today, worth revisiting alongside the throttle TODO.

If you're deciding whether a new route needs `#[Auth]`: any route that
changes state *for a specific logged-in user* needs it, with a token in
the form. A route any anonymous visitor is meant to hit (a comment form, a
public contact form) uses the origin-proof lane instead, don't hand-roll
your own CSRF check for it.

## Sessions

- **Lazy start.** `session_start()` only fires the first time a request
  actually touches session data, reading a logged-in user, calling
  `csrfToken()`, etc. (`Kip\Session::lazy()` + `SessionStarter::start()`,
  `src/Session.php` / `src/SessionStarter.php`). A guest `GET` that never
  touches the session gets no `Set-Cookie` header at all.
- **Cookieless guests.** That matters beyond tidiness: an unconditional
  session cookie on every response would make every page uncacheable (any
  cookie bypasses the page cache, see [chapter 7](07-performance.md)).
  Lazy sessions are what keeps a guest browsing your blog cacheable.
- **Fixation defense.** `Kip\Auth::attempt()` calls a session-id
  regenerator on successful login (`session_regenerate_id(true)` by
  default) *before* setting `user_id`. A session that existed pre-login
  is never the one that carries post-login authority. Before that,
  `SessionStarter` turns on `session.use_strict_mode`, so PHP replaces a
  session id the server never issued instead of adopting one planted in
  the visitor's cookie.
- **Logout rotation.** `Kip\Auth::logout()` does the same thing in
  reverse: it forgets `user_id`, rotates the CSRF token
  (`rotateCsrf()`), and regenerates the session id, the same fixation
  defense used on login, so a stale pre-logout token or session id is
  worthless afterward.
- **Cookie flags.** `SessionStarter` sets `httponly: true`, `samesite:
  'Lax'`, and `secure` based on whether the *current* request is HTTPS.
  directly, or via `X-Forwarded-Proto` when `trusted_proxy` is on (see
  `public/index.php`'s `$https` calculation).

## Login throttling

`Kip\Auth::throttled(string $email, string $ip): bool` counts failed
attempts matching either the email or the IP (`WHERE email = ? OR ip = ?`
against the `login_attempts` table) in the last 15 minutes and returns
true at 5 or more. `Auth::attempt()` refuses to even
check the password once throttled, and a successful login clears the
counter for that email. `AuthController::attempt()` checks `throttled()`
itself first so it can render a 429 response with a message, rather than
letting `attempt()` fail silently:

```php
if ($this->auth->throttled($email, $this->request->ip)) {
    return new Response($this->view->render('auth/login', [...]), 429);
}
```

**The email half is a deliberate trade-off.** Because the count includes
every failure for the email from any IP, anyone who knows a user's email
can lock that account out of login for 15 minutes with five wrong
passwords, even from an address the user never shares. Counting per
email and IP pair instead would stop that, but it would also let an
attacker with many IPs make five guesses per address against one
account. Kip keeps the stricter rule. The lockout is temporary, and the
user has a way back in: password reset uses its own counter (below), and
a successful reset clears the login failures for that email.

**Split buckets for resets.** Once `login_attempts` carries the `kind`
column (skeleton migration `006_add_login_attempts_kind`), password-reset
requests count against their **own** limits: 3 per account, 10 per IP,
per 15 minutes, instead of sharing the login counter. The split is
deliberate in both directions: reset spam can no longer lock a victim
out of *login*, and a login-failure flood can no longer block the
*forgot-password* recovery path. Apps without the column keep the older,
stricter shared-counter behavior (the framework detects the column at
runtime). Skeleton migration `007_index_login_attempts_by_time` indexes
the table by email, by IP and by attempt time, so every throttle check
and prune is an index lookup rather than a read of the whole table;
without it a credential-stuffing burst makes each login slower.
Tightening reset limits further when login attempts look
abusive (step-up verification) is deliberately not built, see
[`../design-decisions.md`](../design-decisions.md).

## General rate limiting

The login throttle above protects one thing: `Auth::attempt()`. To cap
abuse anywhere else (registration spam, comment floods, API scrapers),
configure a fixed-window limiter per URL prefix:

```php
// app/config.php
'rate_limit' => [
    'auth' => ['max' => 10, 'window' => 60],      // 10 POSTs per minute per address
    'comments' => ['max' => 30, 'window' => 60],
],
```

A prefix is the first URL segment, so `'auth'` covers `/auth/login`,
`/auth/logout`, `/auth/remind`, every POST under that path. Dashed and
underscored spellings of one controller name (`/my-billing` and
`/my_billing`) share a single bucket: the limiter canonicalizes through
the router's own separator rule, so an attacker cannot dodge the cap by
switching spelling. The empty string `''` is the prefix for `POST /`.
Configuring an `admin` prefix covers everything under `/admin/*`,
whichever namespace serves it; a `posts` prefix does not cover
`/admin/...` paths, because only the first segment counts.

A `'*'` key is the fallback: when the request's first segment has no
explicit entry, the fallback's `max` and `window` apply. The fallback
counts only spellings the router's segment grammar admits: the root, or
a first segment of at most 64 bytes (a limiter policy; no real
controller name is that long). Uppercase, percent-encoded, and
doubled-separator spellings fail the grammar and are guaranteed 404s,
so charging them would let rotated junk POSTs write a fresh bucket per
request that the cap could never trip; such requests cost zero queries.
A grammatical spelling that routes nowhere is still charged, by design:
before routing runs it is indistinguishable from a real route, so each
distinct grammatical segment holds one row per address within the prune
grace. If you cannot tolerate that churn, skip `'*'` and enumerate the
prefixes that need capping. The hit still records under
the request's own prefix, so two surfaces sharing the
fallback hold independent buckets: spending the review budget never
touches the kudos budget. An explicit entry always wins, `''` included,
so a config can tighten a few prefixes and cap everything else once:
`'auth' => ['max' => 10, 'window' => 60], '*' => ['max' => 30, 'window' => 60]`
limits every writable surface that is not `auth` to 30 non-GET/HEAD
requests per minute per address, without enumerating segments; with no
`''` entry, `POST /` falls back too. Rows are keyed by the canonical
prefix, so dashed and underscored spellings share a fallback bucket
exactly as they share an explicit one. The largest configured window,
the fallback's included, also sets the prune grace (how long expired
counter rows are kept) for EVERY prefix, explicit ones included, so
keep the fallback window in minutes unless the larger table is wanted.

Enforcement sits in the kernel before routing: every non-GET/HEAD
request to a configured prefix counts one hit (with the `'*'` fallback
configured, a prefix without its own entry counts against the
fallback), and a request past the
max is answered `429 Too many requests` with a `Retry-After` header
saying how many seconds remain in the window. The check runs before the
router, before `#[Auth]`, and before CSRF, so an over-limit request
never reaches a controller, a session, or a transaction, and a POST to a
nonexistent path under a configured prefix still spends budget. A
`max` of `0` blocks every counted request for the window: a kill switch
for one prefix while you investigate.

GET and HEAD never count. Page renders pay nothing, so the one-query
page budget is untouched, and an app without a `rate_limit` key issues
zero limiter queries. A counted request pays exactly one UPSERT (the
counter row), plus one DELETE retiring expired windows when the request
happens to open a new one.

The counter lives in the `rate_limits` table, shipped as migration
`009_create_rate_limits` in both bundled apps: one row per prefix,
address, and window start, with `window_start` a unix integer so the
retiring DELETE seeks its index instead of scanning (the login
throttle's `julianday()` lesson, applied). Run `php bin/kip migrate`
before enabling the config, or every counted request is a 500 telling
you the table is missing. The counter needs SQLite 3.35+ or PostgreSQL
9.5+ for the upsert; the boot check names the driver when it cannot.

Addresses are keyed the way the login throttle keys them: the IP the
request arrived from, or the last `X-Forwarded-For` hop when
`KIP_TRUSTED_PROXY=1` (set it only when the origin is reachable solely
through that proxy, or a direct client can pick its own bucket).
Equivalent IPv6 spellings collapse to one bucket; a request with no
address at all shares one bucket per prefix, the safe direction. The
limiter is independent of the login throttle: both apply, each with its
own window and counts.

## Session revocation on password change

A logged-in session carries a *password epoch*: the first 12 characters
of the user's password hash, stored in the session at login
(`Auth::attempt()`). The kernel's `#[Auth]` gate re-checks it against
the users table on every authenticated request via
`Auth::sessionValid()`. The consequence that matters: **changing a
user's password, through password reset, or an admin panel edit,
revokes every session that logged in before the change.** A user who
resets because of a compromise evicts the attacker's stolen cookie; the
attacker's session dies with the old hash. The check fails closed: a
missing users table, a deleted user row, or a legacy session without an
epoch all count as not logged in. (After upgrading an existing app,
logged-in users re-authenticate once. Their pre-upgrade sessions have
no epoch.)

A successful login also rewrites a hash stored at an outdated cost or
algorithm (after a PHP upgrade raises the default bcrypt cost, or for
rows imported from another system), so response timing cannot tell
those accounts apart from unknown emails. That rewrite changes the
epoch too: the user's other sessions end once, at that login.

**Reverse-proxy caveat.** Throttling keys on `Request::ip`, which is
`REMOTE_ADDR` unless `trusted_proxy` is on. Behind a reverse proxy without
`KIP_TRUSTED_PROXY=1`, every request's `ip` is the *proxy's* address,
and throttling degrades from "per attacker" to "everyone behind this proxy
shares one counter," which one aggressive attacker can use to lock out
your legitimate users. Set `KIP_TRUSTED_PROXY=1` when you deploy behind a
proxy, and only then. `Request::fromGlobals()` trusts the *last* hop of
`X-Forwarded-For` (the one value a client can't forge by prepending fake
entries), falling back to `REMOTE_ADDR` if that value isn't a syntactically
valid IP. This supports one trusted proxy hop, not an arbitrary forwarding
chain.

## Named policies

`#[Auth]` answers "is this a logged-in session?". For "is this user
allowed to do this?", register a named policy as a plain PHP closure and
name it on the gate:

```php
use Kip\Routing\Auth;
use Kip\Session;

$app->policy('can-edit', function (Session $session): bool {
    return $session->get('role') === 'editor';
});

#[Auth(policy: 'can-edit')]
public function edit(string $id): string
{
    // ...
}
```

The kernel runs the closure after login validation and after CSRF, and
before the controller. It passes the request's `Session` and `Request`
in that order; a closure may declare only the `Session` (extra inputs
are ignored), and anything else it needs, a `Database` handle, config
values, it captures with `use`. A `false` return is the framework's
plain 403 `Forbidden`, on `#[Json]` routes too: the user is logged in,
just not allowed, so there is no redirect to the login page. A guest
still gets the login redirect, and a tokenless POST on a policy route
still fails CSRF first, so no authorization verdict is revealed to a
request that has not proven its ambient authority.

Register each name once, in your bootstrap (`public/index.php`), before
the first request. Registering the same name twice is an error: a
silent replacement of an authorization rule is exactly the mistake to
surface, not absorb. Under a persistent worker the closure is shared
but its inputs are per request; two visitors on one `App` instance are
judged by their own sessions.

Two failure modes are loud, never silent:

- **Unknown name.** A route naming a policy no `App::policy()`
  registered fails at first hit with an error naming the policy (the
  dev error page shows it; prod logs it and answers 500). The same
  holds for a misspelled attribute argument, which fails at route
  resolution instead of quietly degrading to login-only.
- **Non-bool verdict.** The closure must return `bool`. A truthy
  string, the `?:` shorthand's right operand, say, is an error, not an
  allow.

Policies stay plain closures on purpose: no role table, no expression
language. The name exists so the attribute on the controller stays a
short string while the check itself lives in ordinary PHP, testable
with [chapter 11's](11-testing.md) `TestClient`.

## Signing in with OAuth providers

The skeleton ships "sign in with" Google, GitHub, and Microsoft over one
generic client, `Kip\Auth\OAuthProvider` (`src/Auth/OAuthProvider.php`),
driven entirely by config: set a provider's two environment variables and
its button appears on the login page.

| Provider | Client id | Client secret | PKCE |
|---|---|---|---|
| Google | `KIP_OAUTH_GOOGLE_CLIENT_ID` | `KIP_OAUTH_GOOGLE_CLIENT_SECRET` | yes, S256 |
| GitHub | `KIP_OAUTH_GITHUB_CLIENT_ID` | `KIP_OAUTH_GITHUB_CLIENT_SECRET` | no, its web flow has none |
| Microsoft | `KIP_OAUTH_MICROSOFT_CLIENT_ID` | `KIP_OAUTH_MICROSOFT_CLIENT_SECRET` | yes, S256 (its v2 endpoint requires it) |

Register the redirect URI `{KIP_BASE_URL}/oauth/callback/{provider}` with
the provider, exactly. The client builds it from `base_url` at both ends
of the flow, never from the request, so a tampered `redirect_uri`
parameter cannot aim the token exchange anywhere else. Set `KIP_BASE_URL`
to the site's real URL before going live.

The `oauth_identities` table (skeleton migration 009) links a provider
identity to an account, and that row is the only key an OAuth login
turns:

| At the callback | Outcome |
|---|---|
| the identity row exists | that user is logged in, the email plays no role |
| no row, the visitor is signed in (started signed in, still is) | the identity is linked to that account |
| no row, a guest, the provider email is verified and unused locally | an account is created with a random unusable password, linked, logged in |
| no row, a guest, that email already exists locally | refused |
| no row, a guest, the email is unverified or absent | refused |

The two refusals are the security core. A provider email never resolves
to an existing local account: a local Kip account is never email-verified
at birth, so merging a verified provider email into it would hand whoever
pre-registered that address shared control. And an unverified email never
creates an account, so nobody seeds a login they have not proven control
of. A refused visitor who owns the local account logs in with the
password once, then uses the provider button again while signed in: that
links the identity (the third row above).

Microsoft's user-info response carries no email-verification marker, so a
Microsoft sign-in maps to unverified always: Microsoft can attach to an
existing account but never create one. Google reports `email_verified`;
GitHub reports a verified primary address through its emails endpoint,
which the client consults when `/user` answers with no email. A password
reset severs the account's linked identities, because a reset often
signals compromise and re-linking costs the owner one click
(`Auth::resetPassword()`).

### How the flow is protected

- **State is single-use and session-bound.** `start()` stashes a random
  state (with the PKCE verifier and the redirect pin) in the session;
  the callback consumes it before any network I/O. A wrong state
  consumes nothing, so one tab's callback cannot cancel another's. A
  flow expires after 600 seconds, at most three stay pending, and
  logout forgets them all.
- **The flow is principal-bound.** It records whether the visitor was
  signed in, and as which user; the callback refuses when that changed
  mid-flow (a login or logout in another tab), so a flow can never
  cross an auth transition.
- **Tokens never persist.** The access token lives in a local variable
  for the two requests that need it: never in the session, never in an
  exception message, never in a log line. The state and the
  authorization code travel the callback URL's query string, which the
  audit log does not record (it stores the path only); a reverse proxy
  in front of the app may still log query strings, so check its
  configuration if that matters to you.
- **Neither transport follows redirects.** A 3xx from a token or
  user-info endpoint is an error, so a compromised endpoint can never
  forward the bearer token to another host. TLS peer verification stays
  on; endpoints must be https, except a loopback host for local
  development.
- **Failures land on the login page.** Every failure redirects to
  `/auth/login?oauth=failed` or `?oauth=refused` (an enum value only,
  never provider text or a code), never a rendered page at the callback
  URL, and every OAuth response carries `Referrer-Policy: no-referrer`
  and `Cache-Control: no-store`.

The framework method under the wiring is
`Auth::linkOAuthIdentity(int $userId, string $provider, string $providerUid): bool`;
it never transfers ownership, an identity already linked to another user
answers false. `Auth::loginOrRegisterOAuth()` implements the table above.
An app that calls either without the `oauth_identities` table gets a
`RuntimeException` naming the migration to apply, not a raw database
error.

A provider beyond the three presets is a config entry with explicit
endpoints (`authorize_url`, `token_url`, `user_url`, and for the GitHub
shape `emails_url`), a `shape` (`oidc`, `github`, or `msgraph`), and
optional `scope` and `params`; PKCE defaults on for a custom provider,
set `pkce => false` if the endpoint rejects the challenge.

## Credentials are POST-only

`Request::postStr()` reads only `$_POST`, never falling back to
`$_GET` the way `input()`/`str()` do. `AuthController` uses it for both
the login password and the CSRF `_token`, a credential or a token
supplied only in the query string (`?password=...`, `?_token=...`) is
silently ignored, not accepted. Use `postStr()` for anything similarly
sensitive in your own controllers.

## Verifying signed webhooks

A webhook from a payment provider is a server-to-server POST: there is no
browser, no session, no cookies, so session CSRF does not apply. Such a
request carries none of `Sec-Fetch-Site`, `Origin` or `Referer`, which is
the non-browser signature the guest lane already accepts. What the route
needs is proof of origin, and providers supply it as an HMAC over the
body. `Kip\Webhook::verify()` (`src/Webhook.php`) does that comparison in
constant time over the raw bytes that arrived:

```php
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Post;
use Kip\Webhook;

#[Post]
public function receive(Request $request): Response
{
    $secret = (string) ($_ENV['WEBHOOK_SECRET'] ?? '');
    if (!Webhook::verify($request, $secret)) {
        return new Response('invalid signature', 403);
    }
    $event = $request->json();
    /* record the event, return 200 fast */
}
```

`verify()` accepts the two generic header shapes providers use, a bare
hex digest and the common `sha256=<hex>` prefixed form, in the
`X-Webhook-Signature` header unless you pass another name. It returns
false on a missing header, an empty secret (a config bug fails closed),
or any mismatch. The HMAC is over the raw body, never a re-encoded
parse, so the bytes you verify are the bytes the provider signed.

Replay protection stays the app's concern: providers that sign a
timestamp put it inside the signed payload or in its own header, and
your handler reads it and rejects stale deliveries. Kip does not guess a
scheme; read your provider's signing documentation for what is covered.

Keep the handler small and fast. Slow work (receipt emails, outgoing API
calls) belongs in `App::defer()` so the provider's retry timer never
fires against a slow 200.

## Security headers

Every `Response` carries these by default (see [chapter 3](03-controllers.md)),
including redirects and 304s, which is harmless per RFC but worth knowing
when inspecting headers:

| Header | Value | Why |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | no MIME sniffing of uploads or text |
| `X-Frame-Options` | `SAMEORIGIN` | no framing by other sites (clickjacking) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | a URL carrying a token, such as `/auth/reset/...`, never reaches another site |
| `Content-Security-Policy` | `base-uri 'self'; object-src 'none'` | no `<base>` hijack, no plugins |

The CSP is deliberately minimal: it sets no `script-src`, so it blocks
nothing a Kip page does, and no `frame-ancestors`, which would override an
app's own `X-Frame-Options: DENY`. Pass your own
`Content-Security-Policy` header to replace it with a stricter one.

Zero JavaScript means the framework: no build step, no bundler, no npm, and
every framework feature works with scripting disabled. Your app is free to
add plain JavaScript where it earns its keep; the default
Content-Security-Policy permits it, and Kip will never require a JS
framework or ship one. The shipped default, `Content-Security-Policy:
base-uri 'self'; object-src 'none'`, sets no `script-src`, so app scripts
run without any CSP change; tighten it when the app does not need them.

`Strict-Transport-Security` is not set by the framework: once a browser
sees it, it refuses plain HTTP for that host until it expires, so enable it
at the proxy or in your app once HTTPS works end to end (see
[chapter 10](10-deployment.md)).

## Error modes

`Kip\App::errorResponse()` branches on `config['env']`:

- **`dev`**: the exception class, message, file:line, and full stack
  trace render directly in the response body (HTML-escaped, 500 status).
- **`prod`** (the default, unset `env` behaves as `prod`), the full
  exception goes to `error_log()`, and the browser gets a generic
  `Something went wrong` with a 500 status. No stack trace, no exception
  message, no file paths ever reach the client.

Set `KIP_ENV=dev` only on your own machine; never in a deployed
environment. `bin/kip serve` sets it for you automatically for local
development.
