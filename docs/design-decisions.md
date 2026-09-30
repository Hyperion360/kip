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

## JSON endpoints: opt-in per route, not a REST framework

Kip renders HTML and ships zero JavaScript, so for a long time it had no
JSON story at all: an API layer exists to serve JS or mobile SDK
clients, and consumers of that kind were deliberately absent. When real
demand arrived (webhooks and simple integrations, both session-less
server-to-server callers), the shape this file predicted is what shipped:
a `#[Json]` route attribute, per action or per controller class, whose
non-`Response` results the kernel wraps as `application/json`, plus
`kip openapi` generating the machine-readable description of those routes
from the same conventions the router serves (guide chapters 2 and 8).
Opt-in per route, no schema layer, no new dependency.

What stays out is the rest of the REST stack: no serializer/resource
layer, no content negotiation, and no bearer-token issuance for native
mobile clients. Sessions are cookie-shaped; token lifecycle, revocation
and storage are the genuinely hard part of that feature, and no app in
this ecosystem has a mobile client. If one appears, it goes through the
same demand gate this section did.

## SQLite by default, one server

WAL mode, a separate file per concern (data, logs, cache), online-safe
`VACUUM INTO` backups. The niche this serves is small-to-mid apps on a single
server, vertically scaled.
Horizontal scale-out is out of scope; if you need it, you need a different
framework, and that's fine.

## The deployment contract: ordinary hosting

Kip runs unchanged on ordinary shared hosting and the cheapest VPS tier.
That is a design constraint, not an accident: the runtime requirement list
(PHP 8.3 and the PDO extension) is the whole server spec, because zero
dependencies and zero JavaScript mean there is nothing to compile, bundle,
or configure before serving. Copy the app tree, point the host at
`public/`, run `php bin/kip migrate`, and the app is live. One server, one
SQLite file, no build step, no config wrangling. The one-server ceiling
above is the same bet read from the operations side: what keeps the
architecture simple is also what keeps the hosting bill flat.

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
with a small inline stylesheet: a narrow set of `kip-`-prefixed classes
over semantic HTML, square corners, system font stacks, light and dark
without JavaScript. Below 800px the same markup restyles: the sidebar
becomes a top bar and tables become cards, all CSS. Users who want a CSS
framework add one themselves; Kip never ships or depends on one.

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

## Built for agent-parallel development

The layout is shaped so many features ship at once, whether the writers are
people or AI agents: a worktree per feature keeps every slice its own
checkout; feature folders (`app/Features/<Name>/` with code, templates,
migrations and tests inside) keep parallel agents on disjoint trees; and
the one-query page budget is enforced by tests, so a change that adds a
second query to a page fails that render at the gate instead of eroding
quietly. The discipline that keeps agents from colliding also keeps
contributors from colliding: disjoint trees, a review between merges, one
gate.

## What we will never build

- A realtime/SSE engine, no JS clients to serve.
- An embedded scripting VM. PHP has no compile step; hooks are just PHP.
- A schema-designer UI, see code-first migrations above.
- Thirty OAuth providers. Three to five cover real usage; breadth is
  maintenance without users.
- A REST framework: serializers, content negotiation, bearer-token auth
  for native mobile clients. JSON endpoints are per-route `#[Json]` (see
  above), nothing more.
