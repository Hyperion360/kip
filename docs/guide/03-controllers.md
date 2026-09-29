# 3. Controllers

A controller is a plain class with public methods, no base class to
extend, no interface to implement. `Kip\Routing\RouteMatch::invoke()`
constructs it through the container and calls the matched method:

```php
namespace App\Controllers;
use Kip\Database;
use Kip\View;

final class PostsController
{
    public function __construct(private Database $db, private View $view) {}

    public function index(): string
    {
        $posts = $this->db->all('SELECT * FROM posts ORDER BY created_at DESC');
        return $this->view->render('posts/index', ['posts' => $posts]);
    }
}
```

## Constructor autowiring

`Kip\Container::make()` (`src/Container.php`) reflects on the constructor
and resolves each typed parameter recursively, bind an instance once
(`App`'s constructor does this for the framework's own services) and every
class that type-hints it, at any depth, gets it for free. There's no
container configuration to write in your app: type-hint what you need and
it appears.

**What's injectable out of the box:**

| Type | Available when | Notes |
|---|---|---|
| `Kip\Database` | `config.php` has a `db.dsn` key | your app's main database |
| `Kip\View` | always | bound to `views` (or `app_dir/views`) |
| `Kip\Session` | always, per-request | the *current request's* session, never the boot-time one under a persistent worker (see [ch. 7](07-performance.md)) |
| `Kip\Http\Request` | always, per-request | the current request |
| `Kip\App` | always | rarely needed directly |
| `Kip\RequestLog` | `config.php` has a `log_db.dsn` key | the audit log writer |

Anything else (`Kip\Auth`, your own service classes) is autowired
reflectively as long as every constructor parameter is itself injectable or
has a default value. `Kip\Auth`'s constructor takes `Database $db, Session
$session, ?callable $regenerator = null`: the first two resolve from the
container, the third falls back to its default. That's how
`AuthController` gets a working `Auth` instance without any registration
step:

```php
final class AuthController
{
    public function __construct(private Auth $auth, private View $view, private Session $session, private Request $request) {}
}
```

If a parameter can't be resolved this way, a scalar or union type with no
default. `Container::make()` throws
`RuntimeException("Cannot autowire {Class}::\${param}")` immediately, at
construction time, not deep inside a method call.

## Return types

A controller method returns either a `string` (rendered HTML, wrapped in
a `Response` automatically by `App::process()`) or a
`Kip\Http\Response` directly, when you need a non-200 status, a redirect,
or a custom header:

```php
public function show(string $id): Response|string
{
    $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
    if ($post === null) return new Response('Post not found', 404);
    return $this->view->render('posts/show', ['post' => $post]);
}
```

## The `Response` API

```php
new Response(string $body = '', int $status = 200, array $headers = []);
Response::redirect(string $to, int $status = 302): self;
$response->withHeader(string $name, string $value): self;   // returns a NEW instance
```

Every `Response` carries five headers by default, merged under whatever
you pass explicitly: `Content-Type: text/html; charset=utf-8`,
`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: strict-origin-when-cross-origin`, and
`Content-Security-Policy: base-uri 'self'; object-src 'none'`
([chapter 6](06-security.md) explains each). These
merge into *every* response the framework sends, including 302 redirects
and 304s. Which is harmless (clients that don't apply a header to a
bodyless response just ignore it) but worth knowing when you're inspecting
headers with `curl -I`.

`Response` is immutable: `withHeader()` returns a new instance rather than
mutating in place, so `$response = $response->withHeader('X-Foo', 'bar')`
is the pattern, not a fire-and-forget call.

## The `Request` API

```php
$request->method;   // 'GET', 'POST', ...
$request->path;     // '/posts/show/1'. Trailing slash stripped, '/' stays '/'
$request->get;      // $_GET as an array
$request->post;     // $_POST as an array
$request->cookies;  // $_COOKIE as an array
$request->ip;       // proxy-resolved if trusted_proxy is on, see ch. 6
$request->input(string $key, mixed $default = null): mixed;   // post[key] ?? get[key] ?? default
$request->str(string $key): string;                            // input(), cast to string, trimmed
$request->postStr(string $key): string;                        // POST-ONLY, cast + trimmed
$request->header(string $name): ?string;                       // case-insensitive
```

Use `str()` for ordinary form fields (title, body, page number) where
falling back to a query-string value is harmless. Use `postStr()` for
anything sensitive (credentials, the CSRF `_token`) so a value can never
be smuggled in via the URL. `PostsController::store()` uses `str()` for the
post title; `AuthController::attempt()` uses `postStr()` for the password.

## Feature folders

The layered layout you have seen so far (controllers in `app/src/Controllers`,
templates in `app/views`, migrations in `app/migrations`) is the default and
the tutorial stays layered. An app can also group a feature's code in one
place: create `app/Features/Billing/` with the convention layout below and
its routes, templates and migrations all resolve with zero configuration:

```
app/Features/Billing/
    BillingController.php
    views/invoice.php
    migrations/008_billing.php
```

The URL segment studly-cases into both the folder and the class name:
`/billing/invoice/42` resolves to
`App\Features\Billing\BillingController::invoice('42')`, and
`/reading-lists` to `App\Features\ReadingLists\ReadingListsController`.
`$view->render('billing/invoice')` looks in `app/views/billing/invoice.php`
first, then `app/Features/Billing/views/invoice.php`. Layouts always resolve
from the app root: the shared kernel owns them, a feature template wraps
itself in the app's layout like any other template. The template name's first
segment follows the same studly convention as the folder itself
(`render('billing/invoice')` finds `app/Features/Billing/views/invoice.php`),
and template names are app-authored, so the router's URL validation rules do
not apply to them.

`bin/kip migrate` reads `app/migrations/` plus every
`app/Features/*/migrations/` directory as ONE ledger. A migration name may
exist in only one directory (`008_billing.php` cannot sit in two features),
and the run order is the sorted full filename across all directories, so keep
`NNN` prefixes unique per app the way a single directory always implied: two
migrations sharing a prefix order by their full names, deterministic but
probably not what you meant. A `migrations` entry inside a feature that is
not a readable directory fails the command loudly; discovery happens only in
the migration commands, so a broken Features layout never breaks `logs` or
`backup`. See [chapter 5](05-database-and-migrations.md) and
[chapter 8](08-cli.md).

**Tests can live in the feature too.** Nothing about tests is wired into
the framework; the layout is yours. The pattern that keeps a slice whole:
a capitalized `Tests/` directory inside the feature
(`app/Features/Billing/Tests/`) with test classes namespaced
`App\Features\Billing\Tests`, which the PSR-4 prefix below already
resolves with no composer change (the capitalization matters on
case-sensitive filesystems). Add the features root as a second directory
in your PHPUnit testsuite alongside `tests/`, and keep tests whose subject
spans features or belongs to the kernel (schema contracts, CLI, budget
guards) in the plain `tests/` tree beside the support helpers they share.
An agent picking up one feature folder then edits code, templates,
migrations and tests without touching any other feature's files.

**Resolution order and precedence.** Plain controller namespaces win over
feature folders: the router tries `controller_namespace` (and the built-in
admin namespace when the admin panel is on) first, the feature form only when
none of them matched. Code and templates resolve in the same order, app root
first, so a layered app adopting folders gradually can never end up with the
controller coming from one place and the template from the other. Do not keep
a same-named plain controller and feature controller; if you do, the plain
one wins and the feature one is dead code. A feature folder cannot override
the built-in admin panel either: it resolves after `Kip\Admin\Controllers\`,
so only a plain `App\Controllers\AdminController` can shadow `/admin` (the
existing rule, [chapter 12](12-admin-panel.md)).

**The PSR-4 line.** The apps map `App\` to `app/src/`, so without help
`App\Features\` classes would autoload from `app/src/Features/`. New apps
ship with the longer prefix; an existing app adds it to `composer.json`:

```json
"autoload": { "psr-4": { "App\\Features\\": "app/Features/", "App\\": "app/src/" } }
```

then run `composer dump-autoload`; editing `composer.json` alone does not
update the generated autoloader, and feature controllers stay unresolved
until it runs.

**Deleting a feature.** Remove the directory and the feature is gone: its
routes 404, its templates stop resolving, its migrations stop being listed.
The ledger keys on migration names, not paths, so already-recorded migrations
stay recorded (they simply never run again). Roll the feature's batch back
before deleting the folder if you also want its tables dropped.

**Layered stays the default.** An app without an `app/Features` directory
resolves nothing from `App\Features`: no route, template or migration
behavior changes. Two `config.php` keys adjust the wiring once the folder
exists: `features_dir` points the template fallback at a different features
root, and `feature_namespace => null` turns the feature routing form off
(keep it null to serve a folder's templates without making its controller
routable).

## How it works

Every request gets a fresh `Container` scope (`$scope = clone
$this->container` in `App::process()`) with that request's `Request` and
`Session` instances bound in. Controllers are constructed inside that
scope, so two concurrent requests, even under a persistent-worker runtime
, never share a `Request` or `Session` object. See
[chapter 7](07-performance.md) for what that guarantees (and what it
doesn't, automatically) under a worker.
