# Architecture

Kip is a batteries-included, server-rendered framework for PHP 8.3+ with
zero JavaScript and zero runtime dependencies. `composer.json` requires
exactly `php >= 8.3` and `ext-pdo`. The whole core is 35 PHP files under
`src/`, roughly 2,050 lines, and every class in it is `final` except
`Kip\Migrations\Migration`, the abstract base your own migrations extend.
The bet: a kernel this small can be read end-to-end in an afternoon.
This page is the map. What happens to a request, where security is
enforced, how the page cache invalidates itself, and what is
deliberately absent.

## The request lifecycle

`skeleton/public/index.php` is the entire entrypoint:

```php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
$config['views'] = $config['app_dir'] . '/views';

ob_start(); // lazy session may start mid-render; nothing may flush before headers

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($config['trusted_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$app->handle(Kip\Http\Request::fromGlobals(trustedProxy: $config['trusted_proxy']))->send();
ob_end_flush();
```

1. **`Request::fromGlobals()`** builds an immutable snapshot: method,
   path, `$_GET`/`$_POST`/`$_COOKIE`, headers (lowercased), and the
   client IP. `REMOTE_ADDR`, or when `trusted_proxy` is on the *last*
   `X-Forwarded-For` hop, the one value the client cannot forge.
2. **`App::handle()`** is the kernel boundary: it times the request,
   delegates through `cachedProcess()` to `process()`, strips the body
   for `HEAD` (status and headers stay), and writes the audit-log row.
3. **`Router::match()`** resolves the path by convention
   (`/posts/show/42` → `App\Controllers\PostsController::show('42')`)
   after a lowercase-only regex whitelist. It returns a `RouteMatch`
   (class, action, args, `$requiresAuth`), `null` for a 404, or throws
   `MethodNotAllowedException` for a 405, details in
   [guide chapter 2](guide/02-routing.md).
4. **`RouteMatch::invoke()`** runs
   `$scope->make($class)->{$action}(...$args)` against a per-request
   `Container` scope: the controller is autowired, then called with the
   URL segments as string arguments.
5. **The controller works**, reads through `Database`, renders with
   `View::render()` (plain-PHP template, optional layout, `e()`
   escaping), and returns a `string` or a `Response`; a plain string
   is wrapped as `new Response((string) $result)`.
6. **`Response::send()`** emits status, headers, body. The defaults
   (`Content-Type`, `nosniff`, `X-Frame-Options: SAMEORIGIN`) merge
   into every response, redirects and 304s included.

```
skeleton/public/index.php
   │  new App($config, Session::lazy(new SessionStarter($https)))
   ▼
Request::fromGlobals(trustedProxy: …)
   ▼
App::handle($request) ───────────────────────────── kernel boundary
   ├─ cachedProcess() ── page-cache lookup / tap / purge / store
   │      └─ process() ── routing + security + invocation
   │            ├─ Router::match()  → RouteMatch | null (404) | 405 throw
   │            ├─ CSRF lanes, then #[Auth] login gate
   │            ├─ $scope = clone $container; bind Request + Session
   │            ├─ RouteMatch::invoke($scope)
   │            │      └─ Container::make() autowires the controller
   │            │             └─ View::render() → string|Response
   │            └─ not a Response? wrap it
   ├─ HEAD? strip body (status/headers kept)
   └─ RequestLog::log() → app/logs.sqlite (Session::peek, never starts it)
   ▼
Response::send()
```

(`ob_start()`/`ob_end_flush()` exists because the lazy session may
start mid-render; buffering keeps anything from flushing before the
headers go out.)

## The security lanes

Both lanes live in `App::process()`, before the controller is
constructed:

```php
if (!in_array($request->method, ['GET', 'HEAD'], true)) {
    $tokenOk = $active->validateCsrf($request->postStr('_token') ?: null);
    if ($match->requiresAuth) {
        if (!$tokenOk) return new Response('Invalid or missing CSRF token', 403);
    } elseif (!$tokenOk && !$this->sameOriginProof($request)) {
        return new Response('Cross-site request rejected', 403);
    }
}
if ($match->requiresAuth && $active->get('user_id') === null) {
    return Response::redirect('/auth/login');
}
```

- **Lane 1, session token, `#[Auth]` routes.** A non-GET/HEAD request
  to an `#[Auth]` method must carry the session's CSRF token as
  `_token` in the POST body (`postStr()` reads the body only, a
  query-string token is rejected). `Session::csrfToken()` mints
  `bin2hex(random_bytes(32))`, `validateCsrf()` compares with
  `hash_equals()`, `Auth::logout()` rotates it.
- **Lane 2. Origin check, guest routes.** Guest forms have no session
  to hold a token, so a guest POST without one passes
  `sameOriginProof()`: `Sec-Fetch-Site` must be `same-origin` or
  `none`; otherwise `Origin` (falling back to `Referer`) is compared
  against the request's own `Host` header. A request carrying none of
  those headers is accepted, a documented accepted risk (legacy and
  privacy-hardened clients share that exact signature).
- **The `#[Auth]` login gate.** After the lanes: an `#[Auth]` route
  with no `user_id` in the session redirects to `/auth/login`. Note
  the order. CSRF runs first, so a tokenless unauthenticated POST to
  an `#[Auth]` route gets the 403, not the redirect.

`#[Auth]` is detected by `Router::match()` and carried on
`RouteMatch::$requiresAuth`.

## Page-cache orchestration

With `cache_db.dsn` configured, `App::__construct()` builds `PageCache`
on **its own** `Database` connection, never the container-shared one,
or the cache's own writes would tag themselves as invalidation targets.
Then `App::cachedProcess()`:

1. **Eligibility.** `GET`/`HEAD` *and* zero cookies, any cookie at
   all makes the request personal: still rendered, but returned with
   `X-Kip-Cache: BYPASS`.
2. **Lookup.** `PageCache::get(path, query)` (key = sha256 of
   `path?query`). A hit skips rendering and goes straight to
`conditional()`, possibly a 304, with `X-Kip-Cache: HIT`.
3. **Render under a tap.** Before `process()` runs, the kernel sets
   `Database::onQuery()`, a single tap slot on the shared connection.
   Each query is parsed by `TableTagger::tables()` (regex over
   `FROM`/`JOIN`/`INTO`/`UPDATE`; DDL, `sqlite_master`, `_migrations`
   ignored) and sorted by `TableTagger::isWrite()`
   (`INSERT`/`UPDATE`/`DELETE`/`REPLACE`) into reads and writes. A
   `finally` block detaches the tap before the response returns.
4. **Invalidation.** If the request wrote to a table,
   `PageCache::purgeByTables()` deletes every cached page tagged with
   it. Tags were recorded, at store time, as the tables the render
   *read*.
5. **Store gate.** Stored only when status is 200, the request wrote
   nothing, the session was untouched (`Session::touchCount()`
   unchanged. A delta, not a flag, so one `App` stays correct across
   many requests), and no `Set-Cookie` header. Stored →
   `X-Kip-Cache: MISS`.
6. **TTL safety net.** `PageCache::get()` treats an entry older than
   `ttl_seconds` (default 3600) as a miss, and every `put()` prunes
   expired rows. A path that escapes the tags self-heals within the
   TTL.

`App::conditional()` adds a strong `ETag` (sha256 of the body) and
answers a matching `If-None-Match` (weak `W/` accepted) with a 304.
User-facing detail: [guide chapter 7](guide/07-performance.md).

## The audit-log seam

- `App::handle()` wraps the whole pipeline, so 404s, 405s, and 500s
  are logged like any other request.
- `RequestLog` writes to its own SQLite database (`log_db.dsn`.
  `app/logs.sqlite`) on its own `Database` instance: audit traffic
  never interleaves with the app's queries or the cache's tags.
- The user id is read with `Session::peek()`, a non-starting read, a
  plain `get()` would start a session (and set a cookie) for every
  guest, defeating lazy sessions.
- `RequestLog::log()` is best-effort: a failure goes to `error_log()`
  and the response is unaffected; the stored path is stripped of
  terminal escape sequences and control bytes.
- Retention is `log_db.retention_days` (default 30), pruned by
  `kip migrate`/`kip logs` or on a schedule via `kip logs:prune`.

## Configuration

One plain array returned from your app's `config.php`, the
skeleton's, verbatim:

```php
return [
    'env'     => getenv('KIP_ENV') ?: 'prod', // prod default, D4 info-disclosure rule
    'db'      => ['dsn' => 'sqlite:' . __DIR__ . '/app/data.sqlite'],
    'log_db'  => ['dsn' => 'sqlite:' . __DIR__ . '/app/logs.sqlite', 'retention_days' => 30],
    'cache_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/cache.sqlite', 'ttl_seconds' => 3600],
    'app_dir' => __DIR__ . '/app',
    'trusted_proxy' => (bool) getenv('KIP_TRUSTED_PROXY'),
];
```

- No YAML, no env-only config, no service definitions, the array
  *is* the configuration model.
- **Features are off by omission.** `App::__construct()` checks
  `isset($config['db']['dsn'])` before binding `Database`, and
  likewise `log_db.dsn` for the audit log and `cache_db.dsn` for the
  page cache. Omit a key and the feature is simply absent, with no
  cache configured, `cachedProcess()` is just `process()`.
- Two environment variables: `KIP_ENV` (`dev` turns on detailed
  error output; the default is `prod`) and `KIP_TRUSTED_PROXY`
  (honor `X-Forwarded-For`/`X-Forwarded-Proto` from one trusted
  proxy hop).
- `controller_namespace` is optional (default `App\Controllers\`);
  `views` is derived from `app_dir` in `index.php`.

## The container

`Container` has two methods. `instance($class, $obj)` binds explicitly
. `App::__construct()` binds `App` itself, `View`, and (when
configured) `Database` and `RequestLog`. `make($class)` returns a
binding if one exists, otherwise reflectively autowires the
constructor: class-typed parameters are made recursively, builtins
fall back to their defaults, anything else throws a
`RuntimeException` ("Cannot autowire …").

Request state never enters the long-lived container: `App::process()`
does `$scope = clone $this->container;` and binds `Request` and
`Session` into the *clone*, so each controller invocation gets a
fresh scope. What keeps a single `App` correct should it ever serve
more than one request (the worker notes in
[guide chapter 7](guide/07-performance.md)).

## Errors

Inside the kernel, failure is exceptions: `Router::match()` throws
`MethodNotAllowedException` on a verb mismatch, `View::render()`
throws `TemplateNotFoundException` for a missing template, `Database`
lets PDO throw. At the `App::process()` boundary they all become
`Response`s:

- a `null` match → `new Response('Page not found', 404)`, a plain
  return, not an exception;
- `MethodNotAllowedException` → 405 with an `Allow` header listing
  the verbs that would work;
- any other `Throwable` → `errorResponse()`: `env === 'dev'` renders
  a minimal HTML page with the class, message, `file:line`, and
  trace; the default `prod` mode sends the full detail to
  `error_log()` and the browser gets `'Something went wrong'`, 500.
  traces never reach a production browser.

## What is deliberately NOT here

- **No interfaces or PSR integration.** Nothing in `src/` asks you
  to implement a contract or compose third-party packages into the
  pipeline.
- **No middleware.** Cross-cutting concerns: CSRF, caching,
  logging. Are kernel code in `App` that you read, not layers you
  order.
- **No route table, no DSL.** Routing is a filesystem convention
  plus verb/auth attributes.
- **No event system**, beyond the single purpose-built
  `Database::onQuery()` tap the page cache uses.
- **No inheritance seams.** Every class is `final` (the abstract
  `Migration` base excepted), so internals can be refactored freely
. Nothing outside can grow a dependency on them.
- **No runtime dependencies, no JavaScript, no build step.**

Each of these was chosen, not overlooked. The reasoning, and the
alternatives that were rejected, are written up in
[`docs/design-decisions.md`](design-decisions.md).
