# Changelog

## Unreleased

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
