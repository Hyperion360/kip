# Kip

Kip is a plain-PHP, batteries-included framework for server-rendered apps:
you write PHP classes and templates, Kip handles routing, sessions, auth,
database migrations, caching, uploads, email, the audit log, and a
schema-driven admin panel. It has **zero runtime dependencies** (Composer is
used only for autoloading), **zero JavaScript**, and one dependency you
already have: PHP 8.3+.

It exists because the PHP world splits into two corners that leave a real gap
between them. Full-stack frameworks ship everything (queues, broadcast
channels, build pipelines, an SPA-era front end) at the cost of a dependency
graph and a set of conventions your app spends its whole life carrying.
Micro-frameworks ship a router and little else, so the first real application
needs auth, migrations, CSRF, throttling, password reset, backups, and an
admin panel, written by hand, badly, one more time. Kip takes the middle: the
batteries are decided, included, and tested, and the ceiling is deliberately
low. One server, one SQLite database, HTML over the wire.

Kip grew out of a 2011 framework called MVC-Lite, written by
[Danilo Stern-Sapad](https://danilosapad.com/); this is its modern
successor, rebuilt around everything that made small-framework apps
painful in the fifteen years between.

## A Simple Example

A controller is a class; the URL is the class name. `app/src/Controllers/PostsController.php`:

```php
<?php

namespace App\Controllers;

use Kip\Request;
use Kip\Session;
use Kip\View;

final class PostsController
{
    public function __construct(private View $view, private Session $session, private Request $request) {}

    public function index(): string
    {
        return $this->view->render('posts/index', ['title' => 'Posts']);
    }
}
```

No route registration, no annotations, no configuration: `/posts/index` now
renders `app/views/posts/index.php`. Start from the included skeleton and the
dev server is three commands away:

```bash
git clone https://github.com/Hyperion360/kip.git && cd kip/skeleton
composer install
php bin/kip serve
```

## What Is in the Box

Every battery is on by default and removable by deleting its `config.php`
key. Absence means off.

- **Convention routing**: `/controller/action/args` from class names; a
  machine-readable routing table comes free.
- **Auth**: login/logout with session-ID regeneration, password epochs
  (changing a password revokes every prior session), login throttling,
  split reset-token rate buckets, timing-equalized responses, and password
  reset over email.
- **CSRF**: enforced on every non-GET request; query-string tokens rejected.
- **Database and migrations**: SQLite in WAL mode by default, code-first
  migrations with atomic batch rollback, PHP or `.sql` migration files.
- **Page cache**: full-page caching with table-level tag invalidation,
  ETag/304 conditional GETs. Cache poisoning and personalization are
  threat-modeled out (sessions, cookies, and writes never cache).
- **Audit log**: every request timed and logged to a separate database, with
  secret redaction.
- **Admin panel**: schema-driven CRUD over your tables with masked secrets,
  two-step deletes, and a strict allowlist. Zero JavaScript.
- **Uploads**: extension denylist *and* allowlist, magic-byte content checks,
  authoritative on-disk size, random names.
- **Email**: SMTP (with STARTTLS peer verification and strict timeouts) or
  `mail()`, CRLF-injection-safe headers, a mail driver that logs to disk for
  development.
- **Backups**: one command. Online-safe `VACUUM INTO` snapshots with
  retention pruning, zip or plain copies.
- **Testing**: an in-process test client (`TestClient`) with CSRF and
  session handling built in; the framework's own suite runs to ~97% line
  coverage with no database server and no network.

## How Kip Is Different

- **Zero runtime dependencies, as a contract.** `composer.json` requires
  exactly `php >= 8.3` and `ext-pdo`. Not "few": zero. The dependency list
  never grows; optional features may use bundled PHP extensions, never a
  package.
- **Zero JavaScript, as a contract.** Every feature, including the admin
  panel and two-step deletes, works with scripting disabled. There is no
  build step, no bundler, no npm.
- **The batteries are decided.** Where micro-frameworks leave auth, caching,
  and migrations as an exercise, and full-stack frameworks make each one a
  configuration surface, Kip ships one opinionated, tested version of each.
  The [design decisions](docs/design-decisions.md) page states in writing
  what will never be added.
- **One server, one database.** SQLite in WAL mode with a file per concern
  (data, logs, cache), vertically scaled. If you need horizontal scale-out,
  you need a different framework. That's fine.
- **Small enough to read.** ~31 source files, every class `final`, no
  inheritance hierarchies to trace. A PHP developer who has never seen Kip
  should understand a controller in one pass.

## Documentation

- **[Tutorial](docs/tutorial.md)**: build a blog with Kip in about an hour,
  starting from `skeleton/` and ending at the complete `examples/blog/` app.
  Start here.
- **[User guide](docs/guide/README.md)**: fourteen chapters, one per
  battery: routing, controllers, views, database and migrations, security,
  performance, the CLI, the audit log, deployment, testing, the admin
  panel, uploads, email.
- **[Architecture](docs/architecture.md)**: how the pieces fit together;
  request lifecycle, cache invalidation, security lanes.
- **[Versioning](docs/versioning.md)**: the versioning and stability
  contract.
- **[Design decisions](docs/design-decisions.md)**: what Kip will never
  include, and why.

This repository holds three units: the framework core (`src/` + `tests/`,
the `kip/framework` package), `skeleton/` (the minimal app template, auth
pre-wired), and `examples/blog/` (the finished tutorial app).

## Framework Development

```bash
composer install
vendor/bin/phpunit   # needs `php` 8.3+ on your PATH
```

The suite is self-contained under `tests/`: fixture controllers and views in
`tests/Fixtures/`, no database server, no network, no dependence on
`skeleton/` or `examples/blog/`.

## Contributing

Bug reports and pull requests are welcome. Kip's scope is deliberately narrow;
read the [design decisions](docs/design-decisions.md) before proposing a
feature. The "no" list is a commitment.

## License

MIT. See [LICENSE](LICENSE). Kip is written and maintained by
[Danilo Stern-Sapad](https://github.com/ariadoss) on behalf of
[Hyperion360 Inc.](https://hyperion360.com/)
