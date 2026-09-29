# 8. CLI

`bin/kip` (`examples/blog/bin/kip`, identical in `skeleton/bin/kip`) is a
single PHP script with a `match` over `$argv[1]`, no framework command
bus, no auto-discovered command classes. `migrate`, `logs`, and
`logs:prune` below were run directly against `examples/blog` and the
output shown is exactly what came back (down to the real client IP,
`::1`); `rollback`, `user:create`, `serve`, `backup`, `queue:work`,
`schedule`, and the `make:` commands weren't run against the live
example, running them would mutate or restart it, so their output is
derived directly from source (or a throwaway copy) instead, using the
tutorial's own migration names as placeholders; the `make:` outputs
below came from a throwaway copy.

Every command also shares one failure contract: an unexpected framework
exception (a database that will not open, an unreadable migrations
directory) prints `kip: <error>` on STDERR and exits **1**, never a stack
trace on stdout. The per-command sections below cover the expected
outcomes and their exit codes.

```
$ php bin/kip
Usage: kip [migrate|rollback|serve|logs|logs:prune|backup|user:create <email> [password] [--admin]|queue:work [--once]|db [\"SELECT ...\"]|openapi [file]|make:feature <Name>|make:controller <Name>|make:migration <label> [--feature=<Name>]|schedule [--due]]
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

## `db ["SELECT ..."|"PRAGMA ..."]`

Runs ONE read-only query against the app database and prints the rows,
or lists the tables when no query is given:

```
$ php bin/kip db
users
login_attempts
password_resets
_migrations

$ php bin/kip db "SELECT email FROM users LIMIT 2"
email
a@example.com
b@example.com
```

Output is tab-separated: a header line of column names, then one line
per row. `NULL` prints as `NULL`, and control bytes inside values
print escaped (`\t`, `\n`, `\r`, `\0`, other controls as `\xHH`), so a
stored value can never break the row shape or inject terminal
controls into a log. Zero rows print `(0 rows)`. The table list keeps
the `_migrations` ledger visible, unlike the admin panel's browser
([chapter 12](12-admin-panel.md)): an operator debugging migrations
wants it in the inventory.

The statement must start with `SELECT` or `PRAGMA`. Anything else
(`CREATE`, `INSERT`, `DELETE`, `ATTACH`, ...) exits **1** with a
one-line refusal. That keyword gate is the contract, not the safety
net: the query runs on a second database handle SQLite itself opens
read-only (`SQLITE_OPEN_READONLY` on a `mode=ro` URI DSN, the same
handle the admin SQL browser reads through), so the engine refuses
writes no matter what slips past the gate. `PRAGMA user_version=5`,
for example, starts with an allowed keyword and is still refused by
the database.

Error cases, all exit **1** under the shared `kip:` contract: a query
error (`kip: RuntimeException: query failed: no such table: nosuch`),
a missing database file (it is never created), and a `db.dsn` that is
not a file-backed SQLite database.

## `openapi [file]`

Emits an OpenAPI 3.1.0 document (`openapi.json` at the app root, or the
path given) describing the routes your app serves, derived from the same
conventions the router uses at runtime: controller segments, lowercased
action paths, required parameters, verb attributes, `#[Auth]` (as
`x-kip-auth`), and `#[Json]` (as an `application/json` response). No new
dependency; the generator (`src/OpenApi.php`) is plain reflection over
your controller files.

```
$ php bin/kip openapi
Wrote /var/www/myapp/openapi.json (18 paths)
```

Sources mirror `App`'s own namespace selection: your
`controller_namespace` (it must be `App\`-rooted, because the apps' PSR-4
autoloading maps `App\` onto `app/src`), the framework admin controllers
when the panel is enabled and a database is configured, and your feature
folders when `app/Features` exists (`features_dir` and
`feature_namespace` override it, and an explicit `feature_namespace =>
null` keeps the feature form off, the same rule `App` applies). The
document title comes from `config.php`'s `openapi_title` key, defaulting
to `Kip API`.

An `index()` action that requires a parameter is omitted: the bare
controller path is its only URL spelling and it 404s there, so listing
it would advertise a route nobody can call.

Exit code **0** on success. **1**, with the shared `kip: <error>`
contract, for a `controller_namespace` that is not `App\`-rooted, a
source directory that cannot be listed, or an output path that cannot be
written.

## `make:feature <Name>`

Emits the feature-folder shape of [chapter 3](03-controllers.md): the
feature controller, its own `views/index.php` template, an empty
`migrations/` directory (a `.gitkeep` inside, which the migration ledger
skips as a dotfile), and the feature's `Tests/` stub:

```
$ php bin/kip make:feature Billing
Created: app/Features/Billing/BillingController.php
Created: app/Features/Billing/views/index.php
Created: app/Features/Billing/migrations/.gitkeep
Created: app/Features/Billing/Tests/BillingControllerTest.php
Route: /billing (BillingController::index)
```

The name may be spelled any way the URL segment allows: `billing-lists`
and `BillingLists` both scaffold `app/Features/BillingLists/`, routable
at `/billing-lists`. The generated controller is working code, not a
placeholder: constructor injection, one `index()` action rendering the
feature's template through the app layout.

Every `make:` command shares one contract: it emits, never edits. If
anything it would create already exists, or would shadow something that
does (a plain controller of the same name, an app-root template folder
of the same URL, the built-in admin namespace when the admin panel is
on), it names the conflict and exits **1** before writing a single byte:

```
$ php bin/kip make:feature Billing
kip: RuntimeException: feature folder .../app/Features/Billing already exists; kip make: never edits, delete it first or pick another name
```

When the app's `composer.json` maps the feature namespace somewhere else
(or nowhere), the command prints a `Note:` with the fix (the psr-4 line
plus `composer dump-autoload`, [chapter 3](03-controllers.md)) after
emitting. The note is advisory; the files exist and lint either way.

## `make:controller <Name>`

The layered twin: a controller in the app's controller namespace plus
its view template, `app/src/Controllers/<Name>Controller.php` and
`app/views/<name>/index.php` in the default layout:

```
$ php bin/kip make:controller Posts
Created: app/src/Controllers/PostsController.php
Created: app/views/posts/index.php
Route: /posts (PostsController::index)
```

The controller directory comes from the app's own `composer.json` psr-4
mapping when one covers the configured namespace, so a relocated
`app/src` is honored; with no `composer.json` to read, the documented
convention ([chapter 3](03-controllers.md)) applies. The refusal
contract includes a feature of the same name: a plain controller takes
over its route, so the generator refuses instead of creating the shadow.

## `make:migration <label> [--feature=<Name>]`

Emits the next migration: `NNN_<label>.php` with empty `up()`/`down()`
bodies ready for your DDL. The label becomes the filename lowercased,
separators as underscores (`create invoices` and `create-invoices` both
give `create_invoices`); anything else is refused, characters are never
dropped silently.

The number is max-plus-one across the WHOLE ledger, `app/migrations/`
and every `app/Features/<Name>/migrations/` together, and the new
filename must sort after every existing one, because the ledger runs in
global filename order ([chapter 5](05-database-and-migrations.md)).
When the existing inventory would break that order (an unpadded
`8_old.php` sorts after `009_new.php`), the command refuses and names
the file in the way.

```
$ php bin/kip make:migration create_plans
Created: app/migrations/009_create_plans.php
```

`--feature=<Name>` targets the feature's own migrations folder, and so
does running the command from inside the feature's directory. The
number still spans both directories:

```
$ php bin/kip make:migration create_plans --feature=Billing
Created: app/Features/Billing/migrations/010_create_plans.php
```

One guard protects the ledger's identity: a name the database has
already recorded (its file was deleted later) is refused, because
`migrate` would silently skip a regenerated file with that name. The
check reads `_migrations` strictly read-only, and only when the
configured database is a SQLite file that exists; no database file, no
connection attempt.

## `schedule [--due]`

Runs the app's periodic work from one file: `app/schedule.php` returns a
map of cron expression => job, and this command either lists the
schedule or runs whatever is due right now. One crontab line replaces
one line per task:

```cron
* * * * * cd /var/www/myapp && php bin/kip schedule --due
```

`--due` evaluates every expression against the current minute and runs
the matching entries. A minute the host was down for is skipped, the
same contract cron itself has: there is no catch-up run, and an entry
that was due at 04:00 while the machine was off next runs at its next
matching time. The scheduler prints nothing itself on a clean run, so a
healthy schedule sends no mail; a job's own output passes straight
through to cron's mail, exactly as if you had scheduled that job
directly.

The file format:

```php
<?php // app/schedule.php
return [
    '*/5 * * * *' => 'logs:prune --days=30',               // a kip command
    '0 4 * * *'   => ['backup', fn () => warm_cache()],    // several jobs, one expression
    '30 6 * * 1'  => fn () => rebuild_search(),            // a callable, no arguments
];
```

Three job shapes, and the list exists because PHP array keys collide
silently: two entries keyed `'0 4 * * *'` would keep only the last one,
with no error at runtime. Two jobs at four in the morning is normal, so
the list form is the honest answer. Each list element is itself a
command or a callable, one level only.

A command string is always a kip command, split on whitespace with no
shell quoting (`'logs:prune --days=30'` is two tokens, not a shell
line). Its first token is checked against bin/kip's own command list at
load time, so a typo like `'backpu'` is a per-entry failure naming the
token instead of a silent success: the default arm prints usage and
exits 0 for an unknown command, which would otherwise count as success
forever. A callable runs in-process with no arguments: a closure,
`['Class', 'method']`, or an invokable object. Callables must not
`exit()` or `die()`: throwing is the failure channel and it isolates to
that entry, but a process exit kills the whole run and its exit code
becomes the run's.

Plain `php bin/kip schedule` lists every entry with its next due time:

```
$ php bin/kip schedule
*/5 * * * *    next: 2026-09-29 21:00              kip logs:prune --days=30
0 4 * * *      next: 2026-09-30 04:00              kip backup; closure (app/schedule.php:4)
30 6 * * 1     next: 2026-10-05 06:30              closure (app/schedule.php:5)
```

An invalid entry lists with its error and the exit code is **1**, so
`php bin/kip schedule` doubles as a deploy-time schedule sanity check:

```
$ php bin/kip schedule
60 * * * *     invalid: cron expression '60 * * * *': minute must be 0-59, got 60
```

No `app/schedule.php` prints `No schedule (<path>/app/schedule.php not
present).` and exits **0**; an empty array prints `No scheduled jobs.`
the same way. Under `--due`, an absent file is completely silent: a
cron line installed before the app defines a schedule must not mail
every minute. A broken schedule file (it throws, or returns something
that is not an array) is a boot error like a broken `config.php`:
`kip: <error>` on STDERR and exit **1**, in both modes. Per-entry
errors (a bad expression, a bad job value, an unknown command token, a
job that throws or exits non-zero) isolate: they report on STDERR, the
remaining entries still run, and the exit code is **1** when any due
entry failed. Each failure prints one line:

```
schedule: * * * * * kip backpu: unknown kip command 'backpu' (check the spelling against bin/kip)
schedule: 0 4 * * * closure (app/schedule.php:4): RuntimeException: job boom
```

Expressions are the standard five fields (minute hour day-of-month
month day-of-week), each field a star, a number, an ascending range
`a-b`, a step on a star or a range (`*/15`, `10-40/15`), or a comma
list of those (`1,15,30-40/5`). A step on a range starts at the range
start: `10-40/15` hits 10, 25, 40. 0 and 7 are both Sunday. The
day-of-month / day-of-week interaction follows the daemon's documented
rule: when BOTH day fields are restricted, the day matches when EITHER
one does, so `0 0 1 * 1` fires on the 1st of the month and on every
Monday; when either field starts with a star, plain or stepped, both
must match, so `0 0 */2 * 1` fires on even-numbered days that are
Mondays, not on every Monday. Matching evaluates the whole minute: an
entry due at 14:05 is due for the entire minute 14:05, so a tick at
14:05:59 still runs it.

Unsupported syntax is rejected with an error naming the field, never
guessed: day and month names (`mon`, `jan`) are not accepted, use
numbers; a bare number with a step (`5/15`) is refused, write the range
out (`5-59/15`); ranges must be ascending (`50-10` is an error); a step
must be a positive integer; values out of the field's range name the
field and the expression. Expressions evaluate in PHP's default
timezone (`date.timezone`), while the cron daemon schedules in the
system timezone; when the two differ, the schedule fires at the wrong
wall time, so set `date.timezone` to the system zone (or prefix the
crontab entry with `TZ=` when the host's cron honors it).

`--due` holds an exclusive flock on `app/schedule.lock` for the whole
run. An overlapping invocation prints one line on STDERR
(`schedule: previous run still active, skipping this run.`) and exits
**0**: cron mails on any output, which is how an overrunning job
surfaces, one mail per overlapped minute. The kernel releases the flock
when the holder dies, so a crashed run leaves an inert file the next
run reacquires and overwrites; there is no stale-lock state and no
PID-liveness heuristic to get wrong (a recycled PID can make a
PID-checking lock look held when it is not; the PID inside the file is
for humans, `cat app/schedule.lock` then `ps -p`). Command jobs inherit
the lock handle: if the scheduler is killed while a command job runs,
the job keeps the lock until it exits, the safe direction, because work
is still running. A lock that cannot be opened or acquired for a reason
other than an active run is a loud failure (`kip: <reason>`, exit
**1**), never a silent unlocked run. There is no job timeout: a hung
job holds the lock until it exits, that is the overlap contract.

A flag other than `--due` is a usage error and exits **1**, the same
contract as `queue:work`: the command refuses to guess.

## How it works

Every command constructs its own `Kip\Database`/`Kip\Migrations\Migrator`/
`Kip\RequestLog`/`Kip\Jobs` directly from `config.php`. `bin/kip` doesn't
go through `Kip\App` or the container at all, since there's no HTTP
request to route. This is why a command's behavior is easy to predict
from source: each `match` arm is a short, self-contained closure with
nothing hidden behind autowiring. `schedule` is the one arm that
delegates wholesale: it hands everything to `Kip\Cron\Scheduler`
(`src/Cron/Scheduler.php`), which owns the expression evaluator
(`src/Cron/Expression.php`), the overlap lock, and the subprocess
runner, so the arm itself stays a short dispatch. `db` is the one exception that proves the rule's reason: it deliberately does NOT construct a `Kip\Database`, because that would set `journal_mode = WAL` and create a missing file; it opens the read-only handle straight from the configured DSN.