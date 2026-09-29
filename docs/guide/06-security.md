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
