# 7. Performance

Kip's performance batteries are page caching, lazy sessions (which make
caching possible in the first place), and two inert `<head>` declarations
that get the browser to prerender likely next pages. No JavaScript
framework, no build step, no CDN required to get any of it.

## The page cache

Configure it with a `cache_db` key in `config.php`:

```php
'cache_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/cache.sqlite', 'ttl_seconds' => 3600],
```

**Eligibility: guest `GET`/`HEAD` only.** `App::cachedProcess()`
(`src/App.php`) only considers a request cacheable if its method is `GET`
or `HEAD` *and* it carries **no cookies at all**. That's deliberately
conservative, not just the session cookie:

> **Known limitation: any cookie bypasses the cache.** The cache check is
> deliberately conservative, *any* cookie present on the request, not
> just the session cookie, causes a `BYPASS`. This means third-party or
> analytics cookies set on your own domain (a tracking pixel, an A/B test
> cookie, etc.) will silently defeat caching for that visitor until a
> cookie-allowlist ships in a later version. If you rely on the page cache
> for real traffic, audit what else sets cookies on your domain.

This is where lazy sessions earn their keep: a guest browsing your blog who
never triggers a session write gets no `Set-Cookie`, stays cookieless, and
stays cacheable. The moment *anything* sets a cookie on that visitor, your
own session, or a third-party script. Every page they view becomes a
`BYPASS`.

**`X-Kip-Cache` header** on every response (when `cache_db` is configured):

- `MISS`: rendered fresh, and stored for next time unless the render
  turned out personal (touched the session or set a cookie). Those are
  served as `MISS` but not kept.
- `HIT`, served from the cache, no render.
- `BYPASS`. Not eligible (a cookie was present, or the request wasn't a
  cacheable GET/HEAD).

## Table-tag invalidation

While rendering a cacheable request, the kernel taps every query the
render makes (`Database::onQuery()`) and classifies each one as a read or
a write against a table (`Kip\Cache\TableTagger`, regex-based, looks for
`FROM`/`JOIN`/`INTO`/`UPDATE` clauses). A cache **miss** gets tagged with
every table it *read*; any subsequent `INSERT`/`UPDATE`/`DELETE` against
one of those tables purges every cached page tagged with it
(`PageCache::purgeByTables()`). No blanket TTL flush, no manual
"remember to invalidate this page". The tag set is derived from what the
page's own queries actually touched.

Concretely: `/posts/show/1` reads both `posts` and `comments`, so it's
tagged with both. `/posts` (the index) only reads `posts`. Post a new
comment, a write to `comments`, and `/posts/show/1`'s cache entry is
purged (next request is a `MISS`), while `/posts`'s entry survives
untouched (still `HIT`) since a comment write was never tagged against it.
The cache invalidates exactly as precisely as the queries a page actually
runs.

A `ttl_seconds` safety net (default 3600) also expires entries
independently, in case invalidation ever misses a path, belt and
suspenders, not the primary mechanism.

**Known limitation, in the framework's own words:**

> **Known limitation: cache flooding.** Every distinct query string creates
> its own cache row (that's what makes `?page=2` cacheable), so an attacker
> can inflate `cache.sqlite` with junk-query requests. Entries expire with
> the TTL and are pruned opportunistically, bounding growth to
> request-rate × `ttl_seconds`. A query-string length cap or total-row cap
> is planned as a follow-up.

## ETag / conditional GET

A cached page also carries a strong `ETag` (`sha256` of the body). A
request with a matching `If-None-Match` (weak `W/` prefix accepted and
stripped for comparison) gets a bare **304** back instead of the full
page: no body, with `ETag` and `X-Kip-Cache` headers (plus `Response`'s
usual default headers, which merge into every response). This applies to
both `HIT` and freshly-rendered `MISS` responses; `BYPASS` responses skip
conditional handling entirely (no validators are computed for a response
that was never eligible for caching in the first place), and non-200
responses never get validators either.

## Pagination

`PostsController::index()` fetches `PER_PAGE + 1` rows (`LIMIT 21`) and
checks whether more than 20 came back to decide whether there's a next
page. No separate `COUNT(*)`, so the cost stays flat regardless of table
size:

```php
$posts = $this->db->all(
    'SELECT * FROM posts ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
    [self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE]
);
$hasNext = count($posts) > self::PER_PAGE;
$posts = array_slice($posts, 0, self::PER_PAGE);
```

`page` is clamped to a minimum of 1; non-numeric or negative values fall
back to page 1 rather than producing a negative `OFFSET` or an error. This
pattern is worth copying verbatim for any other paginated listing you
build.

## View Transitions and speculative prerendering

Every page's `<head>` (in the layout) carries two inert browser
declarations, not application JavaScript:

```html
<style>@view-transition { navigation: auto; }</style>
<script type="speculationrules">{"prerender": [{"where": {"href_matches": "/*"}, "eagerness": "conservative"}]}</script>
```

The first is pure CSS (the View Transitions API); the second is a JSON
block. Chromium reads it and prerenders same-origin links on
pointer-down, hiding navigation latency; other browsers ignore the unknown
`<script type>` entirely. `eagerness: "conservative"` means prerendering
fires on pointer-down, not hover or viewport-entry. It hides most
perceived latency without prerendering every link a visitor scrolls past.
Be aware that a prerendered `GET` is a real HTTP request: it hits your app
and appears in the audit log (see [chapter 9](09-audit-log.md)) before the
visitor has actually clicked.

## Worker-mode notes

Kip targets the traditional one-request-per-process PHP model by design,
but nothing in the request-handling path assumes it structurally, `App`
takes a per-request `Session` in `handle()`, and controllers get a
per-request `Container` scope (see [chapter 3](03-controllers.md)). If you
adapt it to a persistent-worker runtime (Swoole, RoadRunner, FrankenPHP
worker mode), two things are on you to get right:

1. **Pass a per-request `Session`** into `App::handle()` explicitly rather
   than relying on the boot-time session, the fallback
   (`$session ?? $this->session`) is only correct when `App` itself is
   constructed fresh per request, which classic PHP guarantees and a
   worker does not.
2. **Query-tap detachment must actually run between requests.**
   `Database::onQuery()` is a single per-request tap slot, the same
   pattern as the lazily-booted `Session`, set at the start of a request
   and detached in a `finally` block at the end
   (`$db?->onQuery(fn () => null)` in `App::cachedProcess()`). This is
   safe as-is under one-request-per-process; under a persistent worker
   you're responsible for verifying the detach genuinely fires between
   every pair of requests. A leaked tap from one request tagging another
   request's cache entries would corrupt invalidation silently, the
   `finally` block is the correct pattern to preserve, not something to
   skip or swallow if you customize the request lifecycle.
