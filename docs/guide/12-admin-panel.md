# 12. The admin panel

Kip ships an auto-CRUD admin panel: point it at your database and
every user table gets a browser, a create form, an edit form, and a
delete button, no code generation, nothing configured per table. The
panel reads the live SQLite schema (`sqlite_master` for the table
list, `PRAGMA table_info` for columns) and derives the rest from
conventions. There is no JavaScript: four plain-PHP templates plus
one inline classless stylesheet (`src/Admin/views/layout.php`), and
no build step.

## Enabling it

**1. The config key.** In `config.php`:

```php
'admin' => ['enabled' => true],   // /admin CRUD panel, gate: users.is_admin = 1
```

Absence of the key (or of `db.dsn`) means the routes are never
registered. `/admin` is a plain 404, not a hidden login screen.

**2. The migration.** `003_add_users_is_admin.php` adds the gate column:

```sql
ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0
```

Run `php bin/kip migrate` first ([chapter 5](05-database-and-migrations.md)).

**3. An admin user.** `user:create` takes an `--admin` flag:

```
$ php bin/kip user:create a@example.com --admin
Password (input hidden):
User a@example.com created (admin).
```

Usage: `kip user:create <email> [password] [--admin]`; omit the
password and the prompt hides input ([chapter 8](08-cli.md)).

## URLs

| URL | Method | What it does |
|---|---|---|
| `/admin` | GET | table list with row counts |
| `/admin/browse/<table>` | GET | rows, 50 per page, newest rowid first |
| `/admin/create/<table>` | GET | blank form |
| `/admin/store/<table>` | POST | insert, redirect to browse |
| `/admin/edit/<table>/<rowid>` | GET | edit form for one row |
| `/admin/update/<table>/<rowid>` | POST | update, redirect to browse |
| `/admin/delete/<table>/<rowid>` | POST | delete, redirect to browse |

Rows are addressed by SQLite **rowid**, never by primary-key value. An
email PK (`a@b.com`) could never survive the router's `[a-z0-9_-]`
URL-segment whitelist anyway. `Router::match()` rejects the `@`
before any controller runs. Consequence: `WITHOUT ROWID` tables are
unsupported, stated plainly.

## Field conventions

- **Primary keys and `*_at` columns are never editable**, not in
  forms, not accepted from input. `created_at` is auto-filled on
  create (`date('c')`) when the column exists; on update, timestamps
  are untouched.
- **`is_*` columns render as checkboxes.** Checked writes `1`;
  unchecked/absent writes `0`. The panel reads an unticked (hence
  unsubmitted) checkbox as zero.
- **`password_hash` is write-only.** Browse shows `••••`, edit renders
  an empty password field ("leave blank to keep current"), the stored
  hash is never sent to the browser. Blank on update keeps it;
  non-blank goes through `password_hash()`. Required on create.
- **`body`, `content`, `description`, `notes`** render as textareas;
  other `INTEGER` columns as number inputs; everything else as text.
- **`NOT NULL` columns without a default are required on create**, an
  empty submission is a 422 naming the field.

## The security model

The heart of the panel: **default-deny, fail-closed**. Every action
carries `#[Auth]` (a guest is redirected to login), then calls the
single guard, `AdminController::deny()`. Actions never inline their
own checks. `deny()` reads `is_admin` from the `users` row matching
the session's `user_id`, and the value must be **explicitly 1**: a
missing `users` table, a missing `is_admin` column (both surface as a
caught `PDOException`), a missing row, or a plain `0` all deny with
403. No error path opens the gate. The flag is re-read from the
database every request, never cached in the session, so de-admining
a user takes effect on their next request.

Table names never reach SQL unchecked. Every URL-supplied table must
pass `Schema::has()`, membership in `sqlite_master`, minus the
framework's `_migrations` ledger and SQLite's `sqlite_*` internals.
*before* any query mentions it; anything else is a 404 (`sqlite_master`
itself included). Column names come only from `PRAGMA table_info` via
`Schema::columns()`; request input supplies values, never field names.

All three write routes (`store`, `update`, `delete`) are `#[Post]` and
`#[Auth]`, so the session-bound CSRF token is mandatory in the POST
body. Missing or wrong `_token` is a flat 403 (the authenticated lane
from [chapter 6](06-security.md)). Password-reset tokens travel in
URLs, so `App::handle()` rewrites `/auth/reset/<token>` paths to
`<redacted>` before the audit log sees them
([chapter 9](09-audit-log.md)). Raw tokens never persist.

## Lockout recovery

If the last admin unchecks their own `is_admin` box (one missed
checkbox on the edit form) or deletes their own row, the panel is
locked out. The CLI is the way back: run
`php bin/kip user:create <email> --admin` from the shell. This
creates a *new* user. On an existing email, `user:create` fails
with a UNIQUE violation and exits 1; it cannot re-promote one.

## Beyond `is_admin`

One boolean is all the panel checks. Finer-grained roles are app land:
add a `role` column and check it explicitly in your controllers. The
planned framework answer is per-model policy closures, plain PHP
closures, not a rules DSL, as a Phase 2; full RBAC only if real apps
demand it (see [`../design-decisions.md`](../design-decisions.md),
including the "what we will never build" context).

## What it deliberately doesn't do

No relations UI, no search, no schema editing, no bulk actions, each
omission is a design decision, not a backlog item. The panel edits
rows; it is not a schema designer. Schema changes stay in versioned
migrations ([chapter 5](05-database-and-migrations.md)).

## How it works

`App::__construct()` builds the router's namespace list: your
`controller_namespace` first, then `Kip\Admin\Controllers\` appended
only when `admin.enabled` is true and `db.dsn` exists. The app
namespace wins, so an app controller *can* shadow `/admin` by defining
its own `AdminController`. `Router::match()` maps `/admin/browse/posts`
to `AdminController::browse('posts')` by convention.

`AdminController` (`src/Admin/Controllers/AdminController.php`) builds
its own `View` rooted at `src/Admin/views`, not the app's container
`View`, whose root is your app's views directory. `Schema`
(`src/Admin/Schema.php`) is the only path schema metadata takes:
`tables()` queries `sqlite_master` once per request (memoized),
`columns()` runs `PRAGMA table_info`, and both assert the table is
known before SQL mentions it. `browse()` selects `rowid AS __rid, *`
ordered by rowid descending, `LIMIT 51`. The extra row is the
"next page" probe. Output goes through `View::e()` escaping.
