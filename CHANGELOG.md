# Changelog

## Unreleased

- Route attributes are autoloadable: `Get`, `Post`, `Put`, `Delete` and
  `Auth` each live in their own file under `src/Routing/`. They were
  declared together in `Attributes.php`, which PSR-4 could not resolve,
  so `class_exists()` was false for all five. The router never noticed
  because it compares attribute names as strings, but apps and tooling
  analyzing their own controllers saw every `#[Auth]` as an unknown
  attribute. `Attributes.php` is gone; it was never public API, and code
  that names `Kip\Routing\Auth` and friends keeps working unchanged.
- **Security, behavior change: `#[Auth]` now gates every spelling of it.**
  The router used to require the exact name `Kip\Routing\Auth`, so an
  `#[Auth]` the controller never imported (which PHP resolves to the
  controller's own namespace), a global `#[\Auth]`, or a different letter
  case such as `#[auth]` was silently ignored and the route served guests.
  The router now matches the short name case-insensitively, and `#[Auth]`
  on a controller class requires login for every action in it. A route that
  was public only because of one of those spellings now redirects guests to
  `/auth/login`. If you wrote `#[Auth]` in any of those forms, those routes
  were reachable without login before this release; check your request logs
  for them.
- Login timing: the dummy hash that equalizes an unknown-email login now
  matches the bcrypt cost of `PASSWORD_DEFAULT` on the running PHP (10 up
  to 8.3, 12 from 8.4). It was fixed at cost 12, so on PHP 8.3 an unknown
  email took about four times longer than a real account, which revealed
  which emails are registered. Limit: an account whose hash was stored
  before a PHP 8.3 to 8.4 upgrade keeps cost 10 until its password changes,
  so after that upgrade those accounts remain distinguishable by timing.
- Two declared return types made true: `Database::lastInsertId()` casts
  PDO's `string|false`, and `View::render()` casts `ob_get_clean()`'s
  `string|false`. Both previously relied on coercive mode to turn a
  `false` into `""`.
- `Migrator` lists migrations with `scandir()` instead of `glob()`, and
  refuses a directory it cannot read. `glob()` returned an empty list for an
  unreadable directory, a path that is a file, and a path containing a glob
  character such as `[`, so `migrate` reported success without applying
  anything. A migrations path that exists but is not a readable directory now throws a
  `RuntimeException` naming the path and what to check. A path that does
  not exist at all is still a no-op, as before. Dotfiles in the directory
  are skipped.
- `Container::make()` is generic over the class it is given, so callers
  and downstream apps get the requested class back instead of `object`.
  The public signature is unchanged.
- Array value types on every framework array surface, so apps running a
  static analyzer see real shapes rather than bare `array`. Key types follow
  what PHP and PDO actually produce: `array<array-key, mixed>` for
  superglobal-shaped maps and database rows, because `?0=x` yields an
  integer key and `SELECT 1 AS "0"` yields one in an associative row.
  Your app's runtime behavior does not change: these are docblocks, and PHP
  does not enforce them. If you run a static analyzer against your own app,
  expect new findings where you were passing something looser than the
  documented shape, because the shape is now written down. The three most
  likely: a non-string value in the `$headers` array you hand
  `new Response(...)` (now `array<string, string>`), a non-sequential array
  of table names passed to `PageCache::put()` (now `list<string>`), and a
  widened check such as `instanceof` on the result of
  `$container->make(Foo::class)`, which the analyzer now knows is a `Foo`.
  Each is a one-line fix in your code or a local `ignoreErrors` entry; none
  of them is a runtime break.
- Dev-only static analysis: `composer lint` runs PHPStan at level 6 over
  `src/`, and `composer check` runs lint, the docs check, and the test
  suite in order. The scripts call PHP through Composer's `@php`, so they
  use the same PHP binary as Composer regardless of `PATH`. Nothing is
  added to the runtime `require`, which stays `php >= 8.3` and `ext-pdo`.
- The distributed package no longer ships `scripts/` or
  `phpstan.neon.dist`; both are repository tooling.
- Session revocation on password change: sessions carry a password epoch
  (hash prefix) checked by the kernel's `#[Auth]` gate, a password
  reset or admin password edit revokes every previously logged-in
  session. Fail-closed for missing tables/rows/legacy sessions.
- Split throttle buckets: with the `login_attempts.kind` column
  (skeleton migration 006), password-reset requests count against their
  own limits (3/account, 10/IP per 15 min) instead of the login counter;
  apps without the column keep the shared-counter behavior.
- Review hardening batch: atomic
  batch rollback in the Migrator, backup zip finalization checks +
  14-day archive retention, upload hard-denylist + authoritative
  on-disk size check, mailer log-write check + STARTTLS peer pinning +
  5s timeouts, enumeration-safe `remind()` under mailer failure,
  transactional+timing-equalized `createReset()`, shared constants for
  the ledger table / max-bytes default / redaction regex, migration 005
  (unique token index), pre-marker SQL rejection in `.sql` migrations.
- Test-gap closure: the SMTP transport is now covered by a scripted fake
  server (full conversation, AUTH LOGIN, dot-stuffing, STARTTLS/connection
  failures), `Response::send()` runs under process isolation, and the
  `bin/kip` DB-touching arms (migrate/rollback/user:create/backup/logs)
  are exercised end to end against a throwaway app with a hand-rolled
  autoloader. Line coverage 91.4% → 96.7%; the only uncovered lines left
  need a real SAPI, sendmail, or a missing ZipArchive extension.
- Packaging split: the repository now holds three separate units.
  `kip/framework` (root: `src/` + `tests/`, tests self-contained via
  `tests/Fixtures/`), `kip/skeleton` (`skeleton/`, a minimal app template
  with the auth battery pre-wired and no demo content), and
  `kip/example-blog` (`examples/blog/`, the complete blog app, the
  tutorial's end state). Root `composer.json` no longer autoloads `App\`.
  that mapping now lives in `skeleton/composer.json` and
  `examples/blog/composer.json`, each resolving `kip/framework` via a local
  path repository until it's published to Packagist.

## 0.3.0

The MVP batteries. Zero new
runtime Composer dependencies; every battery activates only when its
config key is present.

- SQL-file migrations with `-- up`/`-- down` sections, interleaved with
  `.php` migrations in the same ledger; every migration now runs in a
  transaction (SQLite transactional DDL), a failed migration leaves
  nothing applied and nothing recorded. `bin/kip serve` auto-migrates in
  dev.
- `Kip\Testing\TestClient`, an in-process test client for app authors
  (`get`/`post`/`postWithToken`/`postWithFile`, `actingAs`, cookie
  semantics that keep the page cache honest).
- Admin panel, server-rendered, zero-JS auto-CRUD over the live SQLite
  schema, default-deny `users.is_admin` gate, identifier whitelisting,
  rowid-based row URLs, mandatory CSRF. Enabled via the `admin` config
  key; `bin/kip user:create --admin` is the only grant path.
- File uploads, `Kip\Storage` with extension whitelist, finfo MIME
  matching, random server-side filenames, size caps; `Request::file()`.
- Mail battery, `Kip\Mailer` with log/mail/smtp transports and a
  header-injection guard; password reset (hashed single-use 30-minute
  tokens, throttle-shared, no account enumeration) wired into the
  skeleton; reset tokens redacted from the audit log.
- `bin/kip backup`, `VACUUM INTO` snapshots of every configured SQLite
  database, zipped into `app/backups/`.
- Documentation: design-decisions page, architecture and versioning
  pages, guide chapters 11–14 (testing, admin, uploads, email).
- Test backfill: direct coverage for `SessionStarter` (cookie flags,
  reference semantics, idempotent start) and the `bin/kip` CLI contract
  (usage/exit codes for the argument-validation arms). Suite: 109 → 188
  tests.

## 0.2.0

- Lazy sessions. `session_start()` only fires the first time a request
  actually touches session data, keeping guest `GET`s that never touch the
  session cacheable.
- Origin-lane CSRF. Guest-facing forms (comments) are protected by
  same-origin verification (`Sec-Fetch-Site`, falling back to
  `Origin`/`Referer`) instead of a session-bound token; authenticated routes
  still require the CSRF token.
- Page cache with table-tag purge. Guest `GET` responses are cached and
  tagged with the database tables they read; writes to a table invalidate
  only the pages tagged with it, with a TTL safety net.
- ETag / conditional GET. Cached pages carry a strong `ETag`; a matching
  `If-None-Match` (weak `W/` prefix accepted) gets a 304 with an empty body.
- Pagination. `/posts` serves 20 per page via a cheap has-next probe
  (`LIMIT 21`, no `COUNT(*)`).
- Renamed the project to Kip (namespace, CLI, env vars, response headers).

## 0.1.1

- Hardening batch: 11 fixes from a security/correctness review pass.
- Removed legacy MVC-Lite code that the 2011 framework carried forward.
- Added the MIT license.

## 0.1.0

- Initial framework core and blog demo app: convention-based routing,
  SQLite by default, built-in auth with login throttling, CSRF protection
  on all non-GET requests, a per-request audit log, zero runtime
  dependencies, zero JavaScript.
- 61 tests.
