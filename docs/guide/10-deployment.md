# 10. Deployment

Kip has no deployment tooling of its own. It's a plain-PHP app, so it
deploys like one. This chapter is a walkthrough shape, not a script to run
verbatim; adapt the specifics to your host.

## The shape of a $5 VPS deploy

1. **PHP 8.3+** with the `pdo_sqlite` extension (and `pdo_mysql`/`pdo_pgsql`
   if you're using one of those instead, see
   [chapter 5](05-database-and-migrations.md)).
2. A webserver pointed at `public/` as the docroot, running your app
   through PHP:
   - **nginx + PHP-FPM**, the conventional production pairing; point
     nginx's `try_files`/`fastcgi_pass` at `public/index.php` the same way
     you would for any front-controller PHP app.
   - **Apache**, including shared hosting; `skeleton/public/` ships an
     `.htaccess` that routes every non-file path through `index.php`.
     See [Apache and shared hosting](#apache-and-shared-hosting-cpanel-etc)
     below.
   - **`php -S` behind a reverse proxy** (Caddy, nginx as a plain
     reverse proxy), viable for a low-traffic app; PHP's built-in server
     is single-threaded per request but that's often fine at $5-VPS scale.
     This is what `bin/kip serve` uses locally, just without `KIP_ENV=dev`
     forced on in production. One caveat for tests: the built-in server
     answers `/style.css/` (trailing slash) leniently and serves the file,
     while Apache and nginx route that path to the front controller, so
     canonical-URL tests must not run against `php -S`.
   Either way, only `public/` is web-reachable: `app/`, `config.php`,
   `bin/`, and `vendor/` should sit outside the docroot or be blocked from
   direct access by the webserver config.
3. **Environment variables**, set however your host does it (systemd
   `Environment=`, a `.env` loaded before PHP-FPM starts, your platform's
   dashboard):
   - `KIP_ENV`, leave unset or set to anything other than `dev` (the
     default is `prod`). Never set `KIP_ENV=dev` in a deployed environment
. See [chapter 6](06-security.md) for what that would expose.
   - `KIP_TRUSTED_PROXY=1`. Set this if (and only if) TLS terminates at a
     reverse proxy or load balancer in front of your app. See
     [chapter 6](06-security.md) for what breaks if you deploy behind a
     proxy without it (login-throttle IP keying) or set it without an
     actual trusted proxy in front (IP spoofing via a forged
     `X-Forwarded-For`).
4. **`php bin/kip migrate` on every deploy**, before traffic reaches the
   new code. It's idempotent. Already-applied migrations are skipped
   (see [chapter 5](05-database-and-migrations.md)), so running it
   unconditionally on every deploy is the right default, not something to
   gate behind "did this deploy actually add a migration."
5. **Log pruning on a schedule, independent of deploys**, a quiet app
   might not deploy for weeks:
   ```
   0 3 * * * php /path/to/app/bin/kip logs:prune --days=30
   ```
(`bin/kip migrate` and `bin/kip logs` also prune automatically on every
run. The cron entry is the backstop for an app that runs quietly.)

### Restart-on-crash is the process manager's job

The framework does not supervise its own process. Under systemd give the
PHP-FPM or server unit `Restart=always` and `RestartSec=2`; the audit log
([chapter 9](09-audit-log.md)) is the record of what happened before any
restart. An app that needs heartbeat alerts reads `kip logs` or ships
logs.sqlite elsewhere.

## Apache and shared hosting (cPanel etc.)

Kip runs on ordinary shared hosting: PHP 8.3 plus `pdo_sqlite`, both
standard on cPanel hosts (Bluehost and peers offer 8.1 through 8.3 under
MultiPHP Manager or PHP Config). SQLite is a bundled PHP extension, so
there is no database to create, no credentials to manage, and no
connection-limit quota to share with neighbors. The walkthrough:

1. **Upload the app** so that only `public/` is served. On cPanel, assign
   the domain as an *addon domain or subdomain* and set its document root
   to the app's `public/` directory; that puts `app/`, `config.php`,
   `bin/`, and `vendor/` outside the docroot, which is the shape Kip
   wants. For a primary domain pinned to `public_html`, upload the app
   one level up and make `public_html` contain only the contents of
   `public/` (or symlinks to them), so the SQLite files never sit in the
   web root.
2. **Select PHP 8.3** in MultiPHP Manager (or the PHP Config tile on
   newer accounts).
3. **The `.htaccess` shipped in `public/`** takes care of routing: real
   files are served directly, every other path goes through
   `index.php`, dotfiles are blocked (ACME's `.well-known/` still works),
   and directory listings are off. It needs `mod_rewrite`, which
   effectively every shared host enables. If your app predates the file,
   copy it from `skeleton/public/.htaccess`.
4. **First run needs a shell**, once: `php bin/kip migrate` then
   `php bin/kip user:create you@example.com`. Use SSH if the plan has
   it, or cPanel's Terminal (most modern cPanel includes one; Bluehost
   does). There is deliberately no web-based signup or installer to
   attack, so a shell is the front door.
5. **Writable paths.** The `app/` directory holds the SQLite files and
   must be writable by the PHP user (mode 755 with the right owner on
   most hosts; 775 where the PHP user differs from the upload user).
   Uploads need `public/uploads/` writable the same way.

Two honest limits. Without any shell access at all you cannot create the
first user, so pick a host that offers Terminal or SSH. And a primary
domain locked to `public_html` needs the indirection in step 1; addon
domains avoid it entirely.

## What to back up

**Back up `app/data.sqlite`.** That's your application's actual data:
posts, users, whatever your app stores.

**Don't bother backing up `app/cache.sqlite`.** It's disposable by design
, the page cache, and will simply repopulate as `MISS`es on first traffic
after a restore.

**`app/logs.sqlite` is a judgment call.** It's the audit trail, not
application data, and retention already bounds its size (see
[chapter 9](09-audit-log.md)). Back it up if you have a compliance or
incident-response reason to keep audit history past a restore; otherwise
it's fine to let it start fresh.

Since all three are SQLite files in WAL mode, a filesystem-level backup
taken mid-write is still consistent as long as you copy the main file
together with its `-wal` and `-shm` companions, or use SQLite's own
[online backup](https://www.sqlite.org/backup.html) / `.backup` command
rather than a plain `cp` of just the `.sqlite` file.

**Or let Kip do it:** `php bin/kip backup` snapshots every configured
database via `VACUUM INTO` (online-safe, writer-friendly) into
`app/backups/kip-backup-<timestamp>.zip`; archives older than 14 days
are pruned automatically. Restoring is unzipping the
files back into place. A nightly cron entry is the whole recipe:

```cron
15 4 * * * cd /var/www/myapp && php bin/kip backup
```

**Off-site copies are the missing half.** `php bin/kip backup` writes to
the app's own disk, which shares fate with the machine. Until backup
upload ships in a tagged release, the supported off-site path is a copy
job in the same crontab, one entry after the backup, copying `app/backups/`
to storage the app does not host; any file-copy tool your platform
provides works. The 14-day local prune is a floor against disk fill, not a
retention policy: whatever the off-site copy keeps is what survives the
machine disappearing.

If the app uses the uploads battery, serve `public/uploads` with
`Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`
at the web-server layer, defense in depth for files the extension
whitelist already screens.

## HTTPS and secure cookies

`public/index.php` decides whether the current request is HTTPS itself,
directly (`$_SERVER['HTTPS']`) or, when `trusted_proxy` is on, via
`X-Forwarded-Proto` from your reverse proxy, and passes that into
`SessionStarter`, which sets the session cookie's `secure` flag
accordingly. This means:

- Serving over HTTP with `trusted_proxy` off (the default) gives you a
  non-`secure` session cookie, correct for local development, wrong for
  anything real.
- Serving behind a TLS-terminating proxy **without** `KIP_TRUSTED_PROXY=1`
  means the app never sees `X-Forwarded-Proto: https` and issues a
  non-`secure` cookie even though the visitor's connection is actually
  HTTPS end-to-end from their side.
- Set `KIP_TRUSTED_PROXY=1` once your proxy is actually terminating TLS
  and forwarding both headers, and the cookie gets `secure` correctly.
  the same flag also fixes the login-throttle IP issue from
  [chapter 6](06-security.md), so it's one setting worth getting right
  early in a deploy, not two separate things to remember.
- Once every page loads over HTTPS, add `Strict-Transport-Security:
  max-age=31536000` at the proxy. Kip does not send it: a browser that
  has seen it refuses plain HTTP for the host until `max-age` runs out,
  so turn it on only when you are sure HTTPS is there to stay.

## A static front for read-heavy sites

A read-heavy site (essays, docs, a catalog that changes daily at most) can
put a static copy of its public pages in front of the app: a build step of
your own, a `pages:build` command you write, renders each public URL to an
HTML file; the files deploy to any static host or to a directory the
webserver serves directly; the origin keeps serving everything dynamic
(login, writes, anything personalized). Kip does not ship this. The
pattern is viable because every Kip page is complete server-rendered HTML
that works with scripting disabled, so a snapshot of the page is the page.

Correctness here is operational discipline, not the headers. A static copy
can outlive the page it was rendered from, and nothing in the headers
prevents it from serving those stale bytes: the origin's cache headers
govern whatever TTL the edge honors, and that only bounds staleness where
the host honors it. The discipline: re-run the export after every deploy;
prune the exported files whenever a page is removed or renamed (build the
`pages:prune` habit next to `pages:build`); treat the origin as
authoritative for anything personalized. A page that embeds per-visitor
state snapshots that state for every visitor, so only genuinely shared
pages belong in the static layer.

## What isn't here

There's no built-in health-check endpoint, no zero-downtime-migration
tooling, and no first-party Docker image (yet). This chapter describes
the shape of a deploy, not a turnkey one. If your host needs a health
check, any cheap `GET` route (even `/`) with a 200 response works, since
`Kip\App` answers every request without any special bootstrapping delay.
