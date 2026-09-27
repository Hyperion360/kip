# Changelog

## Unreleased

- `Database` transactions nest. A `begin()` inside an already-open
  transaction opens a SAVEPOINT instead of throwing "There is already
  an active transaction"; the matching `commit()` releases it and `rollBack()`
  undoes only that level's work. `Auth::createReset()` and
  `Auth::resetPassword()` therefore work inside a caller's transaction (both
  used to fail there), and the same is now true for the page cache's write
  transaction and every migration. Unmatched `rollBack()` is still a no-op
  and an unmatched `commit()` still errors, as before. The savepoint
  statements do not fire the `onQuery` tap, so query-budget tests and cache
  tagging see no new statements.
- **Behavior change: non-canonical URL spellings now 404 instead of serving
  the page.** A trailing slash (`/posts/`, `/posts/show/1/`) was silently
  trimmed, `/home` served the root page's controller, `/posts/index` served
  `/posts`, and `//browse` served the home page (a `parse_url` quirk that
  read the path as a host). Each is now a plain 404, matching how uppercase
  and double-slash paths already behaved: one canonical URL per page. Link
  to the home page as `/` and to index actions at the bare controller path.
  Redirecting instead was considered and rejected: a 301 on a POST turns the
  redirect into a GET in old clients, and the router's design is strict
  canonicalization, not repair.
- Cached pages no longer fossilize the framework's default response headers.
  `PageCache::put()` stored the full header array, defaults included, and a
  cache HIT replayed the stored values over the current ones, so a tightened
  default (a stricter CSP, say) did not reach already-cached pages until the
  TTL expired them. Defaults are now stripped before storing and re-derived
  from the running framework on every HIT; headers an app set itself are
  still cached and replayed. Rows written by an earlier version keep their
  fossilized headers until the TTL expires them; no schema migration, the
  cache self-heals inside one TTL. `Response::defaultHeaders()` exposes the
  current defaults (new public static method).
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
  The router now matches the short name case-insensitively. `#[Auth]` on a
  controller class, a base class it extends, an interface it implements, or
  a trait it uses requires login for every action in it, and a method-level
  `#[Auth]` on a parent's, interface's or trait's declaration of an action
  still gates an override that leaves it off. An app attribute of its own named `Auth`, `Get`,
  `Post`, `Put` or `Delete` is now read as Kip's. A route that was public only because of one of
  those spellings now redirects guests to `/auth/login`. If you wrote `#[Auth]` in any of those forms, those routes
  were reachable without login before this release; check your request logs
  for them. Verb attributes get the same case-insensitive match: a method
  marked `#[post]` used to be treated as unmarked and served GET, and now
  answers POST only, so a GET to it returns 405.
- **Security: login timing no longer reveals which emails are registered.**
  A failed login for an unknown email now runs a discarded
  `password_hash($password, PASSWORD_DEFAULT)`, the same algorithm and cost
  `register()` uses on whatever PHP is running. It used to verify against a
  fixed cost-10 hash, so on PHP 8.4 (default cost 12) an unknown email
  answered about four times faster than a real account (52ms against
  208ms). An account whose stored hash PHP does not recognize (empty or
  corrupt) now takes the same path; `password_verify()` used to reject it
  in microseconds, exposing it. Measured on 8.4, all three failure paths
  take 211 to 214ms. A bcrypt hash with a malformed salt or cost counts as
  unrecognized too, while valid `$2a$`, `$2b$` and `$2x$` hashes still log
  in. A password containing a NUL byte fails the same way, and just as
  slowly, for known and unknown emails. A valid hash stored at another cost or algorithm (every account
  after a PHP 8.3 to 8.4 upgrade, or rows imported from another system)
  verifies at its own speed, so a successful login now rewrites any hash
  `password_needs_rehash()` flags at `PASSWORD_DEFAULT`. Limit: such an
  account stays distinguishable by timing until its owner next logs in.
  The rewrite changes the session epoch, so that login ends the user's
  other sessions, once. The write only applies if the stored hash is still
  the one just verified, so a password reset that lands in between wins,
  while two simultaneous logins with the same password both stay logged
  in. Two kinds of hash keep their outdated form: an argon2 (or other
  non-bcrypt) hash of a password over 72 bytes, which bcrypt would
  truncate, and any hash of a password containing a NUL byte.
- **Security, behavior change: verb attributes follow the class hierarchy.**
  The router read `#[Get]`, `#[Post]`, `#[Put]` and `#[Delete]` only from
  the method it resolved, so a subclass overriding a parent's
  `#[Post] store()` without repeating the attribute made `store()` GET-only.
  CSRF is enforced only on non-GET requests, so that action became reachable
  by a cross-site GET. An action now takes its verbs from the nearest
  declaration that names any (the method itself, then its class's traits,
  then each parent and its traits, then interfaces). An override that
  declares its own verb still wins. If an override of yours relied on
  dropping the parent's verb to answer GET, add `#[Get]` to it.
- A new password containing a NUL byte is refused as bad input instead of
  failing with a 500: the skeleton's password-reset form and the admin
  panel's user editor answer 422 with a form error, and `kip user:create`
  exits 1 with a message. `password_hash()` throws on a NUL byte, and none
  of the three checked for one first.
- `Container::make()` detects a circular constructor dependency and throws
  a `RuntimeException` naming the loop (`A -> B -> A`). It used to recurse
  until PHP ran out of stack or memory, a fatal error with no hint of which
  classes were involved.
- **Behavior change: one URL per controller.** The router studly-cased the
  controller segment and then looked the class up with `class_exists()`,
  which ignores case, and treated every `-` or `_` as a word break even at
  the edges. `/posts-`, `/_posts`, `/po--sts`, `/po-sts` and `/post-s` all
  reached `PostsController`, and so did
  `/secretform` for `SecretFormController`. A controller segment that
  starts, ends or doubles a separator now 404s, and the studly name must
  equal the declared class name exactly. Link to `SecretFormController` as
  `/secret-form`. Whether the case-insensitive form worked before depended
  on the filesystem (it did on macOS, and on Linux only when the class was
  already loaded), so production links were unlikely to rely on it.
- New migrations, skeleton `007_index_login_attempts_by_time` and blog
  `006_index_login_attempts_by_time`: the login throttle's checks and prunes
  read the whole `login_attempts` table on every login, because the
  existing index could not serve `(email = ? OR ip = ?)` or a comparison on
  `julianday(attempted_at)`. The migration replaces
  `idx_login_attempts_lookup` with expression indexes on
  `(email, julianday(attempted_at))`, `(ip, julianday(attempted_at))` and
  `julianday(attempted_at)`, and every throttle query now plans as an index
  SEARCH. Apps derived from the skeleton should copy it; no framework code
  changed. Needs SQLite 3.20 or later.
- The tutorial blog meets the one-query page budget of guide chapter 15.
  `PostsController::show()` ran two queries, the post and then its
  comments; it now folds the comments into the post's row with
  `json_group_array()`. The post listing sorted every post to return one
  page; blog migration `007_add_posts_created_at_index` lets it read
  through an index instead. The tutorial's Step 6 and Step 7 teach both,
  and a test renders each page against the blog's migrations and fails on
  a second query. It decodes the comment JSON with
  `JSON_INVALID_UTF8_SUBSTITUTE`, so one comment posted with invalid UTF-8
  cannot make the post's page fail. The JSON functions are built into
  SQLite 3.38 and later; older builds need the JSON1 extension.
- **Security: the admin panel no longer exposes password hashes.** The
  browse table masked the `password_hash` cell as `••••` but still wrote the
  full hash into that cell's `title` attribute, so it was in the page source
  and in the hover tooltip. The title is now masked the same way. Present
  since v0.3.0.
- **Security: password-reset timing no longer reveals which emails are
  registered.** The skeleton's `remind()` sent the reset email inside the
  request, and only for existing accounts, so a known email answered
  slower (5.0s against 0.02s against a stalled SMTP relay, and roughly
  100 to 500ms against a healthy one). New `App::defer(callable)` queues
  work that `App::runDeferred()` runs after the response is sent; the
  skeleton's front controller calls `fastcgi_finish_request()` first, so
  under PHP-FPM the client never waits on it. `remind()` now defers the
  send. Apps built from the skeleton should copy both the `remind()`
  change and the three lines added to `public/index.php`; without them
  the queued work still runs, at script shutdown, rather than being lost.
  `runDeferred()` closes the session before running the queue, so a slow
  send never holds the visitor's session lock (which would stall their next
  request only for an existing account). `TestClient` runs deferred work
  after each request, as production does.
- The page cache caps its row count. Every distinct query string got its
  own row with no limit, so requests like `/posts?junk=N` grew
  `cache.sqlite` until the TTL pruned them (60 requests added 60 rows in a
  local probe). New `cache_db.max_pages` (default 10000) evicts the oldest
  pages once the table is full, in two set-based statements, so a cache
  already far over the cap is trimmed in one request instead of a statement
  per row. An index on `created_at` serves both that eviction and the TTL
  prune, a new index on `page_tags(key)` lets every delete by page (prune,
  eviction and purge, which all scanned the tag table before) find its
  rows directly, and each `put()` now runs in one transaction. A `max_pages` below
  1 is refused, since it would evict every page as it is written.
- A guest gets the login redirect on an `#[Auth]` route whatever the
  request: the wrong verb used to answer 405 with an `Allow` header, and a
  POST without a CSRF token answered 403, and both confirmed to a guest
  that the route existed. The login gate now runs before the CSRF check,
  reversing the documented order. Logged-in users still get the 405 and
  the 403. `MethodNotAllowedException` gains a `requiresAuth` property
  (constructor default `false`, so existing code keeps working).
- Two more default response headers: `Referrer-Policy:
  strict-origin-when-cross-origin` (pinned, so a token-carrying URL such as
  `/auth/reset/...` never reaches another site in a `Referer`) and a
  minimal `Content-Security-Policy: base-uri 'self'; object-src 'none'`.
  The CSP sets no `script-src` and no `frame-ancestors`, so it blocks
  nothing Kip pages do; pass your own header to replace it.
  `Strict-Transport-Security` stays a deployment choice (guide chapter 10).
- `SessionStarter` enables `session.use_strict_mode`, so PHP replaces a
  session id it never issued instead of adopting one planted in the
  cookie. PHP's default is off. Login already regenerated the id, so this
  closes the guest-side half.
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
  not exist at all is still a no-op, as before; a dangling symlink in its
  place throws. Dotfiles in the directory
  are skipped. An entry named like a migration that is not a regular file
  (a directory, or a symlink whose target is gone) also throws, naming the
  entry, instead of dropping out of the batch.
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
