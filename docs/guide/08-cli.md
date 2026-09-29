# 8. CLI

`bin/kip` (`examples/blog/bin/kip`, identical in `skeleton/bin/kip`) is a
single PHP script with a `match` over `$argv[1]`, no framework command
bus, no auto-discovered command classes. `migrate`, `logs`, and
`logs:prune` below were run directly against `examples/blog` and the
output shown is exactly what came back (down to the real client IP,
`::1`); `rollback`, `user:create`, `serve`, `backup`, and `queue:work`
weren't run against the live example, running them would mutate or
restart it, so their output is derived directly from source (or a
throwaway copy) instead, using the tutorial's own migration names as
placeholders.

Every command also shares one failure contract: an unexpected framework
exception (a database that will not open, an unreadable migrations
directory) prints `kip: <error>` on STDERR and exits **1**, never a stack
trace on stdout. The per-command sections below cover the expected
outcomes and their exit codes.

```
$ php bin/kip
Usage: kip [migrate|rollback|serve|logs|logs:prune|backup|user:create <email> [password] [--admin]|queue:work [--once]]
```

Exit code 0 -- no argument, or an unrecognized one, prints usage and exits
normally.

## `migrate`

Runs pending migrations (see [chapter 5](05-database-and-migrations.md).
both `.php` and `.sql` migration files count) and, as a side effect,
prunes the audit log to its configured retention first. Migration files
are read from the app's `migrations/` directory and from every
`app/Features/<Name>/migrations` directory ([chapter 3](03-controllers.md)),
one global ledger across them all.

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

### Off-site copies: `KIP_BACKUP_S3_*`

`backup` can push the archive to an S3-compatible object storage bucket
in the same run, with no new dependency: the uploader is plain PHP
speaking Signature Version 4 over path-style requests, using curl when
the extension is loaded and PHP's own stream wrappers otherwise. Any
provider endpoint that accepts those requests works. Five environment
variables turn it on:

```
KIP_BACKUP_S3_ENDPOINT=https://s3.example.com
KIP_BACKUP_S3_REGION=us-east-1
KIP_BACKUP_S3_BUCKET=my-app-backups
KIP_BACKUP_S3_KEY=your-access-key-id
KIP_BACKUP_S3_SECRET=your-secret-access-key
```

`KIP_BACKUP_S3_PREFIX` (optional) prepends a folder to every object key,
`myapp/prod` for example, so one bucket can hold several apps or
environments.

All-or-nothing: if any of the five is set but the others are missing,
`backup` exits **1** before writing anything, naming the missing
variables. A half-configured cron job fails loudly instead of quietly
reverting to local-only backups. With none set, the command behaves
exactly as documented above. With all five set, exit **0** means the
archive is on disk AND in the bucket; an upload failure exits **1** with
the HTTP status, so cron's mail tells you about it.

Before the cron entry goes live, run the launch check once against the
real provider: set the five variables in your shell, run
`php bin/kip backup`, confirm the new object appears in the provider's
console, then delete it. That single run proves the credentials, the
endpoint spelling, and the bucket name against the one authority that
matters, and everything after it is routine.

## `queue:work [--once]`

Runs the durable-jobs worker (`Kip\Jobs`; [chapter 14](14-email.md)
shows the mail pattern end to end). `--once` claims and executes the
oldest pending job, then exits: exit code **0** for done or an empty
queue, **1** when the job failed, with the error both in the row and on
STDERR so cron's mail tells you. That is the cron shape:

```
* * * * * cd /var/www/myapp && php bin/kip queue:work --once
```

One `--once` run processes at most one job, so a once-per-minute cron
drains at most one job per minute. When the volume needs more, run the
command without `--once` under systemd or a supervisor: it loops, claims
one job at a time, sleeps 1 second when the queue is empty, and keeps
living through job failures (each failure prints
`kip: job #<id> (<class>) failed: <error>` on STDERR, then the worker
moves on to the next job).

```
$ php bin/kip queue:work --once
Job #14 App\Jobs\SendMail: done
```

```
$ php bin/kip queue:work --once
No pending jobs.
```

A flag other than `--once` (a typo like `--onces`) is a usage error and
exits **1**; the command refuses to guess and will not fall into the
looping worker by accident.

Queuing a job, and the job-class contract (a public
`handle(array $payload): void` method on a class the runner can build
with no constructor arguments), is [chapter 14](14-email.md)'s
territory. Three properties belong here, on the operations side:

- **Transactional enqueue.** `Kip\Jobs::enqueue()` is a plain INSERT
  through your `Kip\Database`, so a job queued inside a controller's
  transaction rolls back with it: a queued mail dies with the request
  that failed, it does not outlive it. Workers see the row only once the
  outermost transaction commits.
- **Failed rows are the report.** Nothing retries a failed job in this
  version; the row keeps the error text and stays visible
  (`SELECT * FROM jobs WHERE status = 'failed'`). Read it, fix the
  cause, then delete the row or re-enqueue deliberately. A later version
  may add retry with backoff.
- **Two workers cannot take one job.** The claim is an UPDATE that
  rechecks `status = 'pending'`, so when two workers race, the loser's
  UPDATE matches nothing and it re-reads the queue. Pointing several
  cron `--once` entries at one app is safe.

Honest limits, stated as limits for this version:

- A worker killed mid-job (SIGTERM during a deploy, a crashed process)
  leaves the row `running`, and nothing reaps it automatically. This is
  durable pending storage, not exactly-once execution: inspect the row,
  then delete it or re-enqueue by hand. Write idempotent handlers, also
  because restoring a backup can resurrect pending rows whose effects
  already happened.
- A done job's payload is cleared; the row keeps the class, status, and
  timestamps. That is logical removal, not erasure: the text lives on in
  WAL pages and in your backups until ordinary churn overwrites them.
  Treat payloads as plaintext database content and never queue bearer
  secrets. Failed rows keep their payload for diagnosis (and the error
  text can carry mail addresses from SMTP replies), so prune terminal
  rows on the schedule your data-retention rules need:
  `DELETE FROM jobs WHERE status IN ('done','failed') AND created_at < ...`.
- The worker holds one SQLite connection. Restoring `data.sqlite` under
  a live worker, replacing the file beneath its open handle, is
  unsupported: stop the worker, restore, start it again.

## How it works

Every command constructs its own `Kip\Database`/`Kip\Migrations\Migrator`/
`Kip\RequestLog`/`Kip\Jobs` directly from `config.php`. `bin/kip` doesn't
go through `Kip\App` or the container at all, since there's no HTTP
request to route. This is why a command's behavior is easy to predict
from source: each `match` arm is a short, self-contained closure with
nothing hidden behind autowiring.
