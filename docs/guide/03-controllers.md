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

Every `Response` carries three headers by default, merged under whatever
you pass explicitly: `Content-Type: text/html; charset=utf-8`,
`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`. These
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

## How it works

Every request gets a fresh `Container` scope (`$scope = clone
$this->container` in `App::process()`) with that request's `Request` and
`Session` instances bound in. Controllers are constructed inside that
scope, so two concurrent requests, even under a persistent-worker runtime
, never share a `Request` or `Session` object. See
[chapter 7](07-performance.md) for what that guarantees (and what it
doesn't, automatically) under a worker.
