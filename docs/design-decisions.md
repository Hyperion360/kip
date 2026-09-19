# Design decisions

This page states what Kip is, what it will never be, and why, the boundary
as a feature, not a footnote. It exists because a framework's durability
rests partly on saying no in writing: every "we won't build that" below is a
commitment, and every commitment frees you to depend on the things we did
build.

The short version: **Kip is server-rendered HTML in plain PHP, with the
batteries decided, for one-server apps.** Everything below follows from
that sentence.

## Plain PHP is the interface

No DSLs, no YAML route files, no annotations beyond PHP's own attributes,
no configuration format that isn't a PHP array returning from `config.php`.
A PHP developer who has never seen Kip should be able to read a controller
and understand it in one pass. The cost of a DSL is never the syntax.
It's the second language your users now have to hold in their heads, and
the abstraction seam that leaks the moment they need something its author
didn't model.

## Zero runtime dependencies

`composer.json` requires exactly `php >= 8.3` and `ext-pdo`. Not "few".
zero. Composer itself serves two roles only: PSR-4 autoloading and
distribution via Packagist. The guard that keeps this true: **the runtime
dependency list never grows.** Optional features may require bundled PHP
extensions (uploads uses `finfo`; backups prefer `ZipArchive` but degrade
without it), never a package.

## No JSON API, by design

Kip renders HTML and ships zero JavaScript. A REST/JSON API exists to
serve JS or mobile SDK clients. Consumers this model deliberately doesn't
have. If a real app one day needs JSON endpoints, the natural shape is a
`#[Json]` route attribute generating OpenAPI from the routing table (the
machine-readable router makes API docs free); that is a demand-gated
decision, not a roadmap item.

## SQLite by default, one server

WAL mode, a separate file per concern (data, logs, cache), online-safe
`VACUUM INTO` backups. The niche this serves is small-to-mid apps on a single
server, vertically scaled.
Horizontal scale-out is out of scope; if you need it, you need a different
framework, and that's fine.

## Code-first migrations, never a schema designer

The migration system is files you write, plain `.sql` with `-- up` /
`-- down` sections, or a PHP class when a migration needs logic, run in
transactions, tracked in a ledger, reviewable in a pull request. A live
schema-designer UI is the single most expensive thing it could become and
the most philosophically discordant: schema changes should be explicit,
versioned artifacts, not clicks in a browser. Position code-first
migrations as the feature, not the gap.

## No bundled CSS or JS frameworks, no build step

No Tailwind, no npm, nothing to compile. The admin panel styles itself
with a small inline classless stylesheet. Semantic HTML that looks
acceptable with zero classes to learn. Users who want a CSS framework add
one themselves; Kip never ships or depends on one.

## Batteries where the design space agrees, delegation where it doesn't

Included, because competent implementations largely agree on the shape:
auth with login throttling, CSRF on both lanes, the page cache with
table-tag invalidation, the audit log, migrations, the admin panel,
uploads, transactional email, backups, an in-process test client.
Not included, because the design space is genuinely diverse and app-shaped:
payments, search, queues-as-a-service. Those belong to your application.

## Authorization is default-deny

No permission exists until someone explicitly granted it: the admin gate
denies unless `users.is_admin` is exactly 1, and every check in the panel
fails closed. A missing table, column, row, or flag is a denial, never an
error that opens. Finer-grained needs start app-side (a `role` column
checked in your controllers); per-model policy closures, plain PHP
closures, not a rules DSL. Are the framework answer if real apps demand
one. Full RBAC arrives only with that demand.

## Boring upgrades, as a public commitment

The stability promise lives in [`docs/versioning.md`](versioning.md); the
design-side corollary: no rewrites, no "v2" that discards the old world,
no breakage for cosmetic gain. Deprecations are announced one minor
release before removal, and every removal is listed in the CHANGELOG.
The model is the small framework that stays dependable for a decade;
frameworks that chase every paradigm don't get to be infrastructure.

## What we will never build

- A realtime/SSE engine, no JS clients to serve.
- An embedded scripting VM. PHP has no compile step; hooks are just PHP.
- A schema-designer UI, see code-first migrations above.
- Thirty OAuth providers. Three to five cover real usage; breadth is
  maintenance without users.
- A JSON API surface as a core feature, see above.
