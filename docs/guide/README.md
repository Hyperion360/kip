# The Kip user guide

This is the reference manual. If you haven't yet, read
[`docs/tutorial.md`](../tutorial.md) first. It builds a working blog in
about an hour and introduces every concept below in context. Come back here
when you want the full picture of a chapter you skimmed, or when you're
building something the tutorial didn't cover.

Each chapter is self-contained and example-first. Read them in order the
first time; after that, treat this as a reference you dip into.

1. [Getting started](01-getting-started.md), install paths, directory
   layout, `config.php` keys, environment variables.
2. [Routing](02-routing.md): the convention router, verb attributes,
   `#[Auth]`, HEAD, 404 vs. 405.
3. [Controllers](03-controllers.md). Constructor autowiring, what's
   injectable, the `Request`/`Response` API.
4. [Views](04-views.md): plain-PHP templates, `e()` escaping, layouts,
   partials.
5. [Database and migrations](05-database-and-migrations.md), the
   `Database` API, SQLite/MySQL/Postgres, writing and running migrations.
6. [Security](06-security.md): the two CSRF lanes, sessions, login
   throttling, security headers, error modes.
7. [Performance](07-performance.md): the page cache, `X-Kip-Cache`,
   ETag/304, pagination, worker-mode notes.
8. [CLI](08-cli.md): every `bin/kip` command, with examples and exit codes.
9. [Audit log](09-audit-log.md). What's recorded, retention, privacy.
10. [Deployment](10-deployment.md), a $5-VPS walkthrough shape.
11. [Testing your app](11-testing.md), the `Kip\Testing\TestClient`
    in-process client: GET/POST with CSRF, `actingAs`, file uploads.
12. [Admin panel](12-admin-panel.md), the schema-driven auto-CRUD panel,
    its fail-closed security model, lockout recovery.
13. [File uploads](13-uploads.md), `Request::file()` + `Kip\Storage`,
    the upload threat model.
14. [Email](14-email.md), the mail battery's three transports and the
    password-reset flow.
15. [Performance contract](15-performance-contract.md): the
    one-query-per-page budget, the SQL patterns that satisfy it, and test
    enforcement.
16. [Building with AI agents](16-building-with-ai.md): a tool-agnostic
    workflow for building Kip apps with AI coding agents: plan, review,
    execute, gate.

## Companion pages

Outside the numbered chapters there are three more pages:
[`../architecture.md`](../architecture.md) explains how the pieces fit
together (request lifecycle, cache invalidation, security lanes),
[`../versioning.md`](../versioning.md) is the versioning and stability
contract, and [`../design-decisions.md`](../design-decisions.md) states
what Kip will never include and why.

## Conventions used throughout

- Code blocks are copy-paste-runnable and match the real code in
  [`examples/blog`](../../examples/blog) unless a chapter says otherwise.
- "How it works" sections at the end of most chapters cite the actual
  class and method names (`src/`). When in doubt, that source is smaller
  and more readable than most frameworks' docs. 35 files, all of
  them short.
- A limitation is documented as a limitation, not glossed over. If a
  chapter says something is an accepted risk or a known gap, that's the
  framework's own position on it, not a bug you found.
