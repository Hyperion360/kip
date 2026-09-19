# 14. Email and password reset

Kip ships transactional email as a battery: `Kip\Mailer`
(`src/Mailer.php`), plain text, three transports, zero dependencies
beyond PHP itself. Its first-party consumer is the password-reset flow
the skeleton's `AuthController` ships with.

## Three transports

Mail lives under the `mail` key. `Kip\App` only constructs `Mailer`
when the key exists (the same zero-config rule as every battery). The
skeleton ships the first configuration:

```php
// log, the default transport. Appends the full message to a file.
'mail' => [
    'transport' => 'log',
    'log_path'  => __DIR__ . '/app/mail.log',
    'from'      => 'noreply@yourdomain.com',
],
```

Use `log` in development and tests. Nothing leaves the machine, and a
test can read the file back to assert on what was "sent". For
production, one of:

```php
// mail, PHP's built-in mail(). The simplest path on a VPS with a
// local MTA (postfix and friends) already delivering.
'mail' => [
    'transport' => 'mail',
    'from'      => 'noreply@yourdomain.com',
],
```

```php
// smtp, a minimal SMTP client: STARTTLS + AUTH LOGIN, no dependencies.
'mail' => [
    'transport' => 'smtp',
    'host'      => 'mail.yourdomain.com',
    'port'      => 587,                       // default
    'username'  => 'noreply@yourdomain.com',  // present → AUTH LOGIN
    'password'  => getenv('KIP_SMTP_PASSWORD') ?: '',
    'tls'       => true,                      // STARTTLS, default
    'from'      => 'noreply@yourdomain.com',
],
```

Omit `transport` and you get `log`. `from` defaults to
`noreply@localhost`, set a real address or your mail lands in spam
filters.

One adjacent key matters as much as the transport: `base_url`. The
reset link must be an absolute URL, and `AuthController::remind()`
builds it as `base_url . "/auth/reset/{$token}"`. The skeleton reads
the key from the `KIP_BASE_URL` environment variable (falling back to
`http://localhost:8080`), set it in production or every reset email
points users at localhost.

## The password-reset flow, as shipped

| URL | Verb | Role |
|---|---|---|
| `/auth/forgot` | GET | the email form (`forgot()`) |
| `/auth/remind` | POST | creates the token, sends the mail (`remind()`) |
| `/auth/reset/<token>` | GET | the new-password form (`reset()`) |
| `/auth/confirm` | POST | sets the password (`confirm()`) |

Token properties, all in `src/Auth.php`:

- **32 random bytes**, hex-encoded to 64 characters
  (`bin2hex(random_bytes(32))` in `Auth::createReset()`).
- **Stored only as a sha256 hash.** A database leak yields no usable
  tokens; the raw token exists in the email and nowhere persistent.
- **Single-use**. `Auth::resetPassword()` deletes the row while
  consuming it; a replayed link fails.
- **30-minute expiry** (`time() + 1800`).
- **One live token per account**. `createReset()` deletes any
  previous token first, so the newest requested link is the only valid
  one.
- **Own throttle bucket**. Every request, hit or miss, records an
  attempt, but resets count separately from logins once the
  `login_attempts.kind` column exists (skeleton migration 006): 3 per
  account and 10 per IP per 15 minutes. Reset spam can't lock login,
  and a login flood can't block recovery ([chapter 6](06-security.md)).
- **Revokes prior sessions**. A successful reset rewrites the password
  hash, and every session logged in before it fails the epoch check
  ([chapter 6](06-security.md)).
- **No account enumeration**, an unknown email returns null and
  `remind()` renders the same "check your email" page either way; a
  mailer failure is logged server-side rather than surfacing as a
  different response.

`AuthController::confirm()` answers 422 with a friendly message for
the two failure shapes a user can hit: a password under 8 characters,
and an invalid or expired token ("request a new one").

Header injection is handled inside `Mailer`, not left to you:
`Mailer::line()` strips CR/LF. And the colon that would form a
`Bcc:` payload: from the recipient, subject, and sender before any
header line is built. A crafted address can smuggle inert words into a
header value, never a new header.

## Guests, CSRF, and the token in the URL

`remind` and `confirm` are guest POSTs (no `#[Auth]`), so they pass
CSRF through the origin lane. The kernel checks `Sec-Fetch-Site` or
`Origin`/`Referer` against the request's own Host, the same protection
the blog's guest comment form gets ([chapter 6](06-security.md)). A
cross-site submission is rejected; a real user's browser passes with
no token field in the form at all.

`/auth/reset/<token>` is a plain GET: nothing to forge, and the token
itself is the bearer secret. Its 64 hex characters fit the router's
lowercase `[a-z0-9_-]` path whitelist ([chapter 2](02-routing.md)),
and `App::handle()` redacts it before audit logging: a path matching
`/auth/reset/` plus exactly 64 hex digits is persisted as
`/auth/reset/<redacted>`. Tokens never reach the log.

## What isn't here

- **Text-only.** Every message is `text/plain; charset=utf-8`, no
  HTML email, no attachments, no multipart.
- **No queue, no retry.** `send()` is synchronous: a slow SMTP server
  stalls the HTTP request that triggered it, bounded by the 5-second
  connect/read timeouts and a 30-second whole-conversation deadline.
  Two consequences of sending inside the request: a stalled relay holds
  a worker for up to that deadline, and, because the reset email only
  sends for existing accounts. The SMTP round-trip is itself a timing
  oracle for account existence. The log transport (the default) has
  neither problem. Send from a CLI command
  ([chapter 8](08-cli.md)) if you can't accept the stall; a mail queue
  is the Phase-2 answer if real usage demands it.
- **No email verification.** Kip has no public registration route to
  trigger one (`Auth::register()` exists as a method; the skeleton
  ships no signup form). That's a design fact, not unfinished work.
  see [design decisions](../design-decisions.md).

## How it works

`Mailer::send()` sanitizes its three header values through `line()`,
then routes on a single `match` over the transport. `log` appends the
headers and body to `log_path`; `mail` wraps PHP's `mail()`; anything
else throws. `smtp` is a hand-rolled client: `fsockopen` the host,
then `say()`/`expect()` pairs. `expect()` reads each reply line,
skips multi-line continuations (a `-` in the fourth column), and
throws a `RuntimeException` naming the expected and actual codes when
they disagree. The sequence is EHLO, STARTTLS
(`stream_socket_enable_crypto`) plus a second EHLO, AUTH LOGIN with
base64 credentials when a username is configured, then MAIL FROM /
RCPT TO / DATA. Inside DATA the body is dot-stuffed.
`preg_replace('/^\./m', '..', $body)`, per RFC 5321 §4.5.2, so a
body line starting with a dot can't terminate the message early.

The tests pin each property: `tests/MailerTest.php` reads back the
log transport's file, including a header-injection attempt that must
arrive as inert words; `tests/AuthResetTest.php` covers the round
trip, the sha256 storage, single-use, expiry, predecessor
invalidation, and the shared throttle.
