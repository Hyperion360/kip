# 1. Getting started

## Two ways to install

**Starting a new app (most people want this).** Use
[`kip/skeleton`](../../skeleton): routing, sessions, and a working auth
battery (login, logout, throttling, CSRF) are already wired up; you add
your own controllers and views on top.

```bash
git clone <this repo> kip && cd kip/skeleton
composer install
php bin/kip migrate
```

Then, each on its own (`user:create` prompts for the password with the
terminal echo off, keeping it out of shell history, so don't paste it as
part of a larger block):

```bash
php bin/kip user:create you@example.com
```

```bash
php bin/kip serve
```

`kip/framework` isn't on Packagist yet, so `skeleton/composer.json`
resolves it via a local path repository (`{"type":"path","url":"../"}`).
that only works with the skeleton sitting next to a checkout of this repo.
Once the framework is published, the same setup becomes
`composer create-project kip/skeleton myapp` from anywhere, no clone
required; nothing else about this chapter changes when that happens.

**Working on the framework itself.** Clone this repo and run its own test
suite against `src/` + `tests/`, no app, no skeleton:

```bash
composer install
vendor/bin/phpunit   # needs `php` 8.3+ on your PATH
```

The framework's tests are self-contained under `tests/Fixtures/` and don't
depend on `skeleton/` or `examples/blog/`.

## Directory layout of a Kip app

Every app built on Kip. The skeleton, `examples/blog`, or one you start
from scratch, has this shape:

```
myapp/
├── app/
│   ├── src/Controllers/     # App\Controllers\*Controller classes
│   ├── views/                # plain-PHP templates
│   ├── migrations/           # NNN_description.php files
│   ├── data.sqlite           # your app's data (gitignored)
│   ├── logs.sqlite           # audit log (gitignored)
│   └── cache.sqlite          # page cache (gitignored)
├── public/
│   ├── index.php             # front controller, the only web-reachable PHP file
│   └── style.css
├── bin/kip                   # CLI: migrate, rollback, serve, logs, user:create...
├── config.php                # the one file that wires everything together
├── composer.json
└── vendor/
```

Only `public/` is served by the webserver, everything else, including the
three SQLite files, lives outside the docroot. `app/src/` is autoloaded via
Composer's PSR-4 (`"App\\": "app/src/"` in `composer.json`); the class names
under it drive routing (see [chapter 2](02-routing.md)).

## `config.php` keys

`config.php` returns a plain array, consumed by `public/index.php` and
`bin/kip` alike:

```php
<?php
return [
    'env'      => getenv('KIP_ENV') ?: 'prod',
    'db'       => ['dsn' => 'sqlite:' . __DIR__ . '/app/data.sqlite'],
    'log_db'   => ['dsn' => 'sqlite:' . __DIR__ . '/app/logs.sqlite', 'retention_days' => 30],
    'cache_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/cache.sqlite', 'ttl_seconds' => 3600],
    'app_dir'  => __DIR__ . '/app',
    'trusted_proxy' => (bool) getenv('KIP_TRUSTED_PROXY'),
];
```

| Key | Meaning | Default if omitted |
|---|---|---|
| `env` | `'dev'` enables dev behavior; any other value (including `'production'`) behaves as `'prod'`. Controls error output, see [chapter 6](06-security.md). | `'prod'` |
| `db.dsn` | PDO DSN for the app's main database. Omit the whole `db` key and `Database::class` is never bound in the container. Controllers that type-hint it will fail to autowire. | none. Required if any controller uses the database |
| `log_db.dsn` / `log_db.retention_days` | Audit log database and how many days of rows to keep. Omit `log_db` entirely to disable request logging. | logging off; `retention_days` defaults to 30 if `log_db` is set but `retention_days` isn't |
| `cache_db.dsn` / `cache_db.ttl_seconds` | Page cache database and its TTL safety net. Omit `cache_db` to disable the page cache entirely. | cache off; `ttl_seconds` defaults to 3600 if `cache_db` is set but `ttl_seconds` isn't |
| `app_dir` | Base directory for migrations (`{app_dir}/migrations`) and the default views path (`{app_dir}/views`). | `.` (current directory) |
| `views` | Override the views directory independently of `app_dir`. | `{app_dir}/views` |
| `controller_namespace` | Override the namespace prefix the router maps URLs into. | `'App\\Controllers\\'` |
| `trusted_proxy` | Whether to trust `X-Forwarded-For`/`X-Forwarded-Proto` from a single reverse-proxy hop. See [chapter 6](06-security.md). | `false` |

`public/index.php` also sets `$config['views']` from `app_dir` if
the app itself doesn't set it, and constructs the HTTPS flag used for the
session cookie's `secure` attribute, see [chapter 6](06-security.md) for
that logic.

## `KIP_*` environment variables

- **`KIP_ENV`**, set to `dev` to get full stack traces in the browser on
  error; anything else (including unset) is treated as `prod`, which logs
  the exception server-side and shows a generic "Something went wrong."
  `bin/kip serve` sets this to `dev` for you automatically.
- **`KIP_TRUSTED_PROXY`**, set to a truthy value (`1`, `true`) when your
  app sits behind a reverse proxy that terminates TLS and sets
  `X-Forwarded-For`/`X-Forwarded-Proto`. Read [chapter 6](06-security.md)
  before flipping this on. The wrong hop being trusted breaks the login
  throttle's IP-based key.

## How it works

`Kip\App::__construct()` (`src/App.php`) reads this array once per request:
it binds `Database`, `RequestLog`, and `Kip\Cache\PageCache` into the
container only when their respective config keys are present, and builds
the `Router` with `controller_namespace`. There's no separate "boot"
step or service-provider system to learn. The constructor *is* the
bootstrap.
