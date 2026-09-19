# 5. Database and migrations

## The `Database` API

`Kip\Database` (`src/Database.php`) wraps a single PDO connection:
prepared statements only, no query builder, no ORM:

```php
$db->query(string $sql, array $params = []): \PDOStatement;   // any statement
$db->all(string $sql, array $params = []): array;              // fetchAll(), assoc rows
$db->one(string $sql, array $params = []): ?array;              // first row, or null
$db->lastInsertId(): string;
```

```php
$posts = $db->all('SELECT * FROM posts WHERE published = ? ORDER BY created_at DESC', [1]);
$post  = $db->one('SELECT * FROM posts WHERE id = ?', [$id]);
$db->query('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)', [$title, $body, date('c')]);
$id = $db->lastInsertId();
```

Every call goes through `query()`, which always prepares before executing
. **there is no raw-execute path**, so string-concatenated SQL is not just
discouraged, it isn't the API surface at all. Bind values as `?`
placeholders; PDO handles quoting.

`PDO::ATTR_ERRMODE` is set to `PDO::ERRMODE_EXCEPTION` and the default
fetch mode to `PDO::FETCH_ASSOC`. A failed query throws `PDOException`
rather than returning `false` silently, and every row comes back as an
associative array (`$row['title']`, not `$row[0]`).

## SQLite by default, MySQL/Postgres if you want them

`Database`'s constructor takes a PDO DSN string directly:

```php
new Kip\Database('sqlite:' . __DIR__ . '/app/data.sqlite');
new Kip\Database('mysql:host=localhost;dbname=myapp');
new Kip\Database('pgsql:host=localhost;dbname=myapp');
```

When the DSN starts with `sqlite:`, the constructor also runs `PRAGMA
journal_mode = WAL` (concurrent readers alongside a writer, the proven setup
for small-to-medium apps) and `PRAGMA
foreign_keys = ON` (SQLite doesn't enforce foreign keys unless told to.
`ON DELETE CASCADE` in a migration is inert without this).

**Separate credentials.** A MySQL DSN cannot embed the username and password
the way SQLite and Postgres DSNs can, so `config.php` accepts `user` and
`pass` keys next to `dsn`, for every database slot (`db`, `log_db`,
`cache_db`). `Kip\App` forwards them to the PDO constructor:

```php
'db' => [
    'dsn'  => 'mysql:host=localhost;dbname=myapp',
    'user' => 'myapp_user',
    'pass' => '...',
],
```

Connecting is the easy part. SQLite remains the default Kip is built and
tested against, and several batteries assume it: the auth throttle and log
pruning compare instants with SQLite's `julianday()`, backups use
`VACUUM INTO`, the admin panel introspects schema through `PRAGMA
table_info` and `sqlite_master`, and the atomic-migration guarantee leans on
SQLite's transactional DDL (MySQL DDL commits implicitly, so a failed
migration there can leave partial state). If you switch the app database to
MySQL, plan for those four; the logs and cache databases can stay SQLite.

## Migrations

A migration is a file under `app/migrations/` returning an anonymous class
extending `Kip\Migrations\Migration` (`src/Migrations/Migration.php`):

```php
<?php // app/migrations/003_create_posts.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE posts'); }
};
```

`up()` and `down()` both just run SQL through `Database`, same API as
everywhere else in the app.

### Numbering

Files are matched with `glob('{app_dir}/migrations/*.php')` and **run in
`sort()` order**. That's why every migration is named `NNN_description.php`
with a zero-padded, monotonically increasing number: lexical sort and
chronological order agree. There's no separate timestamp or dependency
graph; the filename *is* the ordering.

### Running them

```bash
php bin/kip migrate
```

`Kip\Migrations\Migrator::migrate()` (`src/Migrations/Migrator.php`)
creates a `_migrations` tracking table if it doesn't exist
(`name TEXT PRIMARY KEY, batch INTEGER, run_at TEXT`), finds every
migration file whose `name` (basename without extension) isn't already in
that table, runs it, and records it. All migrations run in a single
`migrate` invocation share one incrementing **batch** number.
Already-applied migrations are silently skipped, which is what makes
`bin/kip migrate` safe to run on every deploy regardless of whether that
deploy actually added a migration (see [chapter 10](10-deployment.md)).

### SQL-file migrations

Pure-DDL migrations can be plain SQL, a `NNN_name.sql` file with `-- up`
and (optionally) `-- down` marker lines:

```sql
-- app/migrations/005_create_tags.sql
-- up
CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE INDEX idx_tags_name ON tags (name);
-- down
DROP TABLE tags;
```

`.php` and `.sql` files interleave by name and share the same ledger
(a `006_b.php` runs after a `005_a.sql`); a name may not exist as both.
The PHP class remains the escape hatch for data migrations that need
logic. `-- up` is mandatory, `-- down` is optional, but a `.sql`
migration without one cannot be rolled back (the run fails with an
error rather than guessing).

### Every migration runs in a transaction

SQLite's DDL is transactional, and `Migrator` exploits it: each migration
(and its ledger row) commits atomically or rolls back entirely. A
migration that fails partway through leaves **nothing** applied and
**nothing** recorded, no half-created tables, no phantom ledger entries,
and re-running `migrate` starts it again from scratch. The error message
names the migration and the underlying SQLite error.

```bash
php bin/kip rollback
```

`Migrator::rollback()` finds the highest batch number in `_migrations`,
runs `down()` for every migration in that batch **in reverse filename
order**, and deletes their `_migrations` rows. It only ever undoes the most
recent batch, there's no "rollback to migration N", so if you want a
narrower rollback, migrate in smaller batches.

### What isn't here (yet)

There's no schema-diffing. You write the `up`/`down` yourself, which is
the deliberate code-first stance ([design
decisions](../design-decisions.md)). Write idempotent-safe DDL where you
can (`CREATE TABLE IF NOT EXISTS` is a defensive habit worth keeping even
though the transaction + tracking table normally prevent bad states), and
test a migration against a copy of your data before running it in
production.

## How it works

`bin/kip migrate` also prunes the audit log to its configured retention
before running migrations (see [chapter 9](09-audit-log.md)). That's a
CLI convenience, not something `Migrator` itself does. `Migrator` and
`Database` know nothing about each other beyond the constructor injection;
you could point a `Migrator` at any `Database` instance, including one
that isn't your app's main database, if you ever needed a second migrated
store.
