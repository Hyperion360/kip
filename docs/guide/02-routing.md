# 2. Routing

Kip has no route table. Every URL maps to a controller method by
convention. `Kip\Routing\Router::match()` (`src/Routing/Router.php`) is
the entire implementation, about 40 lines.

## The convention

```
/controller/action/arg1/arg2/...
```

- **`controller`** defaults to `home`, **`action`** defaults to `index`.
  `/` maps to `HomeController::index()` just like `/home/index` would.
- The controller segment is converted to StudlyCase and suffixed with
  `Controller`: `posts` → `PostsController`, `blog-posts` →
  `BlogPostsController` (both `-` and `_` split words), resolved against
  `{controller_namespace}{Studly}Controller` (default namespace
  `App\Controllers\`).
- Anything after the second segment becomes positional string arguments to
  the method: `/posts/show/42` calls `PostsController::show('42')`.

## Lowercase-canonical URLs

Route matching is **case-sensitive lowercase-only**. There's no
case-insensitive fallback. `/Posts/Show/1` and `/POSTS/show/1` both 404,
the same as a URL for a controller that doesn't exist. This means every
page has exactly one canonical URL; there's no ambiguity to worry about for
caching or SEO. Consecutive slashes 404 too (`/posts//show/1` is not the
same as `/posts/show/1`). The whole point is one canonical form per page.

`Router::match()` whitelists the path itself before doing anything else:
```
^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$
```
Anything outside `[a-z0-9_-]` in a path segment, including uppercase, is
a 404 before routing logic even runs.

## `"0"` is a valid argument

Segments are filtered to drop *empty* strings only, not falsy ones, so
`/posts/show/0` correctly calls `show('0')`, not `show()` with no
arguments. A naive `array_filter($parts)` would silently eat the literal
string `"0"` (PHP's classic falsy-string gotcha); the router explicitly
filters on `$p !== ''` instead.

## Verb attributes

A controller method with no verb attribute is **GET-only** by convention.
Add `#[Post]`, `#[Put]`, or `#[Delete]` (`Kip\Routing\{Post,Put,Delete}`) to
restrict a method to that verb instead:

```php
use Kip\Routing\Post;

#[Post]
public function store(): Response { /* ... */ }
```

If the route exists (controller + action + arg count all resolve) but the
verb doesn't match any attribute on the method, that's a **405 Method Not
Allowed** with an `Allow` header listing the verbs that would work, not a
404. A route that doesn't exist at all (no such controller, no such
method, or wrong argument count) is a 404.

Verbs follow the class hierarchy. An override in a subclass, or a method
implementing an interface signature or replacing a trait method, keeps the
verbs of the nearest declaration of that action that names any. A parent's
`#[Post] store()` stays POST-only in a subclass that overrides `store()`
without repeating the attribute, rather than falling back to GET. An
override that names its own verb uses that verb instead.

## `#[Auth]`

```php
use Kip\Routing\Auth;

#[Auth]
public function create(): string { /* ... */ }
```

Marks a method as requiring a logged-in session. The kernel checks this
*before* your controller is constructed: an unauthenticated request to an
`#[Auth]` route gets redirected to `/auth/login` without your code running
at all. `#[Auth]` also changes CSRF enforcement for that route, see
[chapter 6](06-security.md) for the two-lane model.

Attributes stack. `#[Auth] #[Post]` on one method is common for
create/update actions that must be both logged-in and POST-only.

To require login for every action in a controller, put `#[Auth]` on the
class instead. It also counts on a parent class, an interface or a trait,
so a base controller, a marker interface or a shared trait carrying
`#[Auth]` gates every controller that extends, implements or uses it:

```php
use Kip\Routing\Auth;

#[Auth]
abstract class AdminBase {}

final class ReportsController extends AdminBase
{
    public function index(): string { /* login required */ }
}
```

A method-level `#[Auth]` counts on any declaration of that action in the
hierarchy: an override in a subclass stays gated even if it leaves the
attribute off, and so does a method whose interface signature or trait
declaration carries it.

The router matches attribute names by their short name, without regard to
letter case or imports, so an `#[Auth]` you forgot to import still gates the
route rather than leaving it public. The flip side: an attribute of your own
named `Auth`, `Get`, `Post`, `Put` or `Delete`, in any namespace, is read as
Kip's. Give app attributes other names.

## HEAD requests

`HEAD /posts` is served by `PostsController::index()`, the same method
that answers `GET /posts`. With the response body stripped afterward
(`Kip\App::handle()`, per RFC 9110 §9.3.2). You don't write a separate HEAD
handler, and a `HEAD` request to a GET-only route doesn't 404 or fall
through to a POST-only route by mistake. Headers and status code are
preserved; only the body is emptied.

## Argument-count matching

The router checks the target method's parameter count via reflection
before matching: too many URL segments (more than the method accepts) or
too few (fewer than the method's *required* parameters) both 404. A method
with an optional parameter (`function show(string $id, string $tab = 'main')`)
accepts either one or two trailing segments.

## How it works

`Router::match(Request $request): ?RouteMatch` returns `null` on a 404
(handled by `App::process()` as `new Response('Page not found', 404)`),
throws `MethodNotAllowedException` on a verb mismatch (caught by
`App::process()` and turned into the 405), or returns a `RouteMatch`, the
resolved class, method, args, and whether `#[Auth]` was present. `App`
autowires and invokes the controller through `RouteMatch::invoke()`, which
just calls `$scope->make($class)->{$action}(...$args)` against a
per-request `Container` scope. See [chapter 3](03-controllers.md) for what
that means for your constructor.
