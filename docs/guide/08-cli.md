# 8. CLI

`bin/kip` (`examples/blog/bin/kip`, identical in `skeleton/bin/kip`) is a
single PHP script with a `match` over `$argv[1]`, no framework command
bus, no auto-discovered command classes. `migrate`, `logs`, and
`logs:prune` below were run directly against `examples/blog` and the
output shown is exactly what came back (down to the real client IP,
`::1`); `rollback`, `user:create`, `serve`, and `backup` weren't run
against the live example, running them would mutate or restart it, so
their output is derived directly from source (or a throwaway copy)
instead, using the tutorial's own migration names as placeholders.

```
$ php bin/kip
Usage: kip [migrate|rollback|serve|logs|logs:prune|backup|user:create <email> [password] [--admin]]
```

Exit code 0 -- no argument, or an unrecognized one, prints usage and exits
normally.

## `migrate`

Runs pending migrations (see [chapter 5](05-database-and-migrations.md).
both `.php` and `.sql` migration files count) and, as a side effect,
prunes the audit log to its configured retention first.

```
$ php bin/kip migrate
Ran: 003_create_posts, 004_create_comments
```

```
$ php bin/kip migrate
Nothing to migrate.
```

Exit code **0** either way. An empty migration run is not an error, which
is what makes it safe to call on every deploy (see
[chapter 10](10-deployment.md)).

## `rollback`

Reverses the most recent migration **batch** (every migration applied in
one `migrate` run), newest-filename-first.

```
$ php bin/kip rollback
Rolled back: 004_create_comments, 003_create_posts
```

```
$ php bin/kip rollback
Nothing to roll back.
```

Exit code **0** either way. There's no target-specific rollback, it only
ever undoes the latest batch.

## `serve`

```
$ php bin/kip serve
Auto-migrated: 005_add_posts_slug
```

Runs pending migrations first (dev auto-migrate, the safe version of
sync-on-deploy; production stays explicit `bin/kip migrate` in the deploy
script), then `KIP_ENV=dev php -S localhost:8080 -t public` in the
foreground (via `passthru()`). PHP's built-in dev server, pointed at the
app's `public/` docroot, with `dev` error mode forced on regardless of
`config.php`. Not for production (see [chapter 10](10-deployment.md));
stop it with Ctrl+C. When nothing is pending the auto-migrate line is
simply absent.

## `user:create <email> [password] [--admin]`

The form to reach for interactively, omitting the password prompts for it
with the terminal echo disabled (`stty -echo`/`stty echo` on non-Windows
systems; a visible fallback otherwise), keeping the secret out of shell
history and out of `ps`:

```
$ php bin/kip user:create you@example.com
Password (input hidden):
User you@example.com created.
```

Passing the password as the second argument works too, for scripts and
provisioning, where the caveat above is the trade-off you're accepting:

```
$ php bin/kip user:create you@example.com secret
User you@example.com created.
```

`--admin` additionally sets `users.is_admin = 1` on the new account, the
only way an account ever becomes an admin (see
[chapter 12](12-admin-panel.md)):

```
$ php bin/kip user:create you@example.com --admin
Password (input hidden):
User you@example.com created (admin).
```

If the users table has no `is_admin` column yet, the user is created but
the command reports it and exits **1**, run the `add_users_is_admin`
migration first.

Error cases, all exit code **1**:

```
$ php bin/kip user:create
Usage: kip user:create <email> [password] [--admin]
```

```
$ php bin/kip user:create you@example.com ""
Password must not be empty.
```

```
$ php bin/kip user:create you@example.com secret     # email already registered
A user with email you@example.com already exists.
```

## `logs`

Prints the 50 most recent audit-log rows, oldest first, after pruning to
the configured retention. See [chapter 9](09-audit-log.md) for the column
meanings.

```
$ php bin/kip logs
2026-08-16T02:16:51+00:00 500 GET    /posts                                      1.2ms ::1             guest
2026-08-16T02:17:00+00:00 500 GET    /posts                                      0.1ms ::1             guest
2026-08-16T02:17:11+00:00 200 GET    /posts                                      1.1ms ::1             guest
2026-08-16T02:17:11+00:00 200 HEAD   /posts                                      0.0ms ::1             guest
```

Exit code **0**.

## `logs:prune --days=N`

Deletes rows older than `N` days and reports the count. Independent of the
automatic pruning `migrate`/`logs` already do, use this from cron for an
app that might not redeploy or check logs for weeks (see
[chapter 10](10-deployment.md)):

```
$ php bin/kip logs:prune --days=9999
Pruned 0 rows older than 9999 days.
```

```
$ php bin/kip logs:prune --days=abc
Usage: kip logs:prune --days=<positive integer>
```

The malformed-argument case exits **1**; a successful prune (even pruning
zero rows) exits **0**. `--days` must be a positive integer, `0` and
negative values are rejected the same as non-numeric input.

## `backup`

Snapshots every configured SQLite database into
`app/backups/kip-backup-<timestamp>.zip` using `VACUUM INTO`, an
online-safe copy that doesn't block writers (WAL included). Restoring is
unzipping the files back where they came from; see
[chapter 10](10-deployment.md) for the cron line.

```
$ php bin/kip backup
Backup written: /var/www/myapp/app/backups/kip-backup-20260918-120000.zip
```

The `logs` and `cache` databases ride along (labeled `logs.sqlite` /
`cache.sqlite` in the archive). Drop those two when restoring if you'd
rather not resurrect stale cache entries. Exit code **0**; exits **1**
with an error if no SQLite database is configured.

## How it works

Every command constructs its own `Kip\Database`/`Kip\Migrations\Migrator`/
`Kip\RequestLog` directly from `config.php`. `bin/kip` doesn't go through
`Kip\App` or the container at all, since there's no HTTP request to route.
This is why a command's behavior is easy to predict from source: each
`match` arm is a short, self-contained closure with nothing hidden behind
autowiring.
