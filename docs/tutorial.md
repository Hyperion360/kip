# Build a blog with Kip in an hour

This tutorial takes the [`kip/skeleton`](../skeleton) app template, routing,
sessions, and auth already wired up, nothing else opinionated, and turns it
into a working blog: post index and detail pages, an author-only editor,
guest comments, pagination, and the audit log and page cache running
underneath the whole time. By the end you'll have rebuilt
[`examples/blog`](../examples/blog) yourself, one small step at a time.

Every code block below is copy-paste-runnable, and by the end every file
matches what's actually in `examples/blog` byte for byte (two blocks along
the way are explicitly labeled interim versions that a later step
replaces, and the migration files differ only in their numbered
filenames, as Step 1 explains). If you'd rather read the finished app
than type it, it's sitting right there.

## What you'll build

- `/posts`, a paginated list of posts
- `/posts/show/<id>`, a post with its comments
- `/posts/create` and `/posts/edit/<id>`, an author-only editor, gated by
  the login you already have from the skeleton
- `/comments/store/<postId>`. A guest comment form, no login required
- Along the way: the request-log audit trail, the page cache with its
  `X-Kip-Cache` header, and the login throttle that already came with the
  skeleton's auth battery

## What you need

- PHP 8.3 or newer
- [Composer](https://getcomposer.org)
- About an hour, and a terminal

No database server to install: Kip defaults to SQLite, and the files just
appear the first time you run a migration.

## Setup (5 minutes)

```bash
git clone <this repo> kip && cd kip/skeleton
composer install
php bin/kip migrate
```

Then create your account. The password is asked for interactively with the
terminal echo off, so it never lands in your shell history (run this on its
own, not pasted as part of a bigger block, or the next pasted line becomes
your password):

```bash
php bin/kip user:create you@example.com
```

And start the dev server:

```bash
php bin/kip serve
```

Open <http://localhost:8080>. You should see the skeleton's welcome page,
with a working "Log in" link. Try it with the account you just created
(once you're in, the home page grows a "Log out" button).

Honest note on that first line: `kip/framework` isn't on Packagist yet, so
`skeleton/composer.json` resolves it via a local path repository, which only
works from inside a checkout of this repo. Once the framework is published,
starting a new app becomes the one-liner `composer create-project
kip/skeleton blog` from anywhere, no clone required. Nothing else about
this tutorial changes when that happens.

Keep `bin/kip serve` running in that terminal and open a second terminal in
the same `skeleton/` directory for the rest of this tutorial, you'll edit
files and reload the browser as you go, no restart needed.

## Step 1: the posts table

Kip migrations are plain PHP classes with an `up()` and a `down()`, tracked
by name in a `_migrations` table so `bin/kip migrate` only ever runs each one
once. The skeleton already has two, `001_create_users.php` and
`002_create_login_attempts.php`, the auth battery's tables. So posts is
`003`.

Create `app/migrations/003_create_posts.php`:

```php
<?php // app/migrations/003_create_posts.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE posts'); }
};
```

Run it:

```bash
php bin/kip migrate
```

```
Ran: 003_create_posts
```

(`examples/blog`'s own posts migration is numbered `002`. That app was
built before the framework/skeleton/example split existed, so its migrations
never had to share a directory with the auth battery's `001`/`002`. Same SQL
either way; only the number and the reader's own migration history differ.)

## Step 2: PostsController, index and show

Create `app/src/Controllers/PostsController.php`:

```php
<?php // app/src/Controllers/PostsController.php
namespace App\Controllers;
use Kip\Database;
use Kip\Http\Response;
use Kip\View;
use Kip\Routing\{Auth, Post};
use Kip\{Session, Http\Request};

final class PostsController
{
    private const PER_PAGE = 20;

    public function __construct(private Database $db, private View $view, private Session $session, private Request $request) {}

    public function index(): string
    {
        $page = max(1, (int) $this->request->str('page'));
        $posts = $this->db->all(
            'SELECT * FROM posts ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE]     // fetch one extra: cheap has-next probe, no COUNT(*)
        );
        $hasNext = count($posts) > self::PER_PAGE;
        $posts = array_slice($posts, 0, self::PER_PAGE);
        return $this->view->render('posts/index', ['title' => 'Posts', 'posts' => $posts, 'page' => $page, 'hasNext' => $hasNext]);
    }

    public function show(string $id): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post === null) return new Response('Post not found', 404);
        return $this->view->render('posts/show', ['title' => $post['title'], 'post' => $post]);
    }
}
```

Ignore the pagination fields for a moment, we'll get there in Step 7. The
constructor and the `#[Auth]`/`#[Post]` imports that aren't used yet are
the whole controller's final shape; there's no harm in typing them once
now. `show()` above is an **interim version**, it'll grow a `comments`
query in Step 6, once the `comments` table actually exists. Querying it
now would throw, since the table isn't there yet.

**How it works:** `Router::match()` (`src/Routing/Router.php`) turns a URL
into `Class::method(args)` by convention, no route table to maintain.
`/posts/show/3` splits into `posts` (StudlyCase → `PostsController`),
`show` (the method name), and `3` (the first positional argument). The
constructor's typed parameters (`Database $db`, `View $view`, `Session
$session`, `Request $request`) are autowired by `Kip\Container`, you never
construct a controller yourself.

Visit <http://localhost:8080/posts> now, you'll get an empty list (no error;
there's just nothing to show yet). `/posts/show/1` will 404 until Step 5
gives you a way to create a post. That's expected, and once you do create
one you'll land straight on its `show` page, still running this interim
`show()`.

## Step 3: views

Views are plain PHP with one convention worth internalizing immediately:
**always escape output with `$this->e()`**, which is `htmlspecialchars()`
under the hood (`Kip\View::e()`). It's not automatic. Kip doesn't compile
templates or auto-escape. So the discipline is yours to keep.

Create `app/views/posts/index.php`:

```php
<?php // app/views/posts/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Posts</h1>
<ul>
<?php foreach ($posts as $p): ?>
  <li><a href="/posts/show/<?= $this->e($p['id']) ?>"><?= $this->e($p['title']) ?></a></li>
<?php endforeach; ?>
</ul>
<nav>
  <?php if ($page > 1): ?><a href="/posts?page=<?= $this->e($page - 1) ?>">&larr; Newer</a><?php endif; ?>
  <?php if ($hasNext): ?><a href="/posts?page=<?= $this->e($page + 1) ?>">Older &rarr;</a><?php endif; ?>
</nav>
```

And `app/views/posts/show.php`, an **interim version**, matching the
interim `show()` above (no comments yet):

```php
<?php // app/views/posts/show.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($post['title']) ?></h1>
  <p><?= nl2br($this->e($post['body'])) ?></p>
</article>
```

Step 6 replaces both this file and `show()` with their final versions, once
there's a `comments` table and a partial to include. Until then this is a
complete, working post page on its own. Nothing here is broken or
incomplete to look at.

**How it works:** `$this->layout('layout')` (called from inside the
template. `$this` is the `Kip\View` instance rendering it) tells
`Kip\View::render()` to wrap the template's output in
`app/views/layout.php`, making the rendered content available there as
`$content`. Every array key you pass to `render()` becomes a local variable
in the template (`$posts`, `$page`, `$post`, ...) via `extract()`, with one
reserved exception: keys starting with `__kip_` are silently dropped, so
your own data never collides with the renderer's internal variables.
Missing template → `Kip\TemplateNotFoundException`, not a blank page.

## Step 4: nav link and homepage

Two small edits so the new pages are actually reachable and the homepage
reads like a blog instead of a bare skeleton.

In `app/views/layout.php`, change the title default and nav:

```php
<?php // app/views/layout.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Blog') ?></title>
  <link rel="stylesheet" href="/style.css">
  <style>@view-transition { navigation: auto; }</style>
  <script type="speculationrules">{"prerender": [{"where": {"href_matches": "/*"}, "eagerness": "conservative"}]}</script>
</head>
<body>
  <header>
    <nav>
      <a href="/">My Blog</a>
      <a href="/posts">Posts</a>
      <a href="/posts/create">Write</a>
    </nav>
  </header>
  <main><?= $content ?></main>
</body>
</html>
```

(The `/posts/create` link doesn't exist until Step 5, it'll 404 until
then, which is fine for a nav link you're about to make real.)

Replace `app/src/Controllers/HomeController.php` whole, the skeleton's
home page carried a logged-in check and a logout form, but the blog moves
logging out to the editor page you'll build in Step 5, so the blog's
`HomeController` is simpler than the skeleton's:

```php
<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\View;

final class HomeController
{
    public function __construct(private View $view) {}

    public function index(): string
    {
        return $this->view->render('home/index', ['title' => 'My Blog']);
    }
}
```

And simplify `app/views/home/index.php` to match:

```php
<?php // app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Hello from Kip</h1>
```

Reload <http://localhost:8080/>, new nav, new homepage. `/posts` still
works from Step 2.

## Step 5: auth-gated create and edit

This is where routing conventions meet PHP attributes. Add `create`,
`store`, `edit`, and `update` to `PostsController`:

```php
    #[Auth]
    public function create(): string
    {
        return $this->view->render('posts/edit', ['title' => 'New post', 'post' => ['id' => null, 'title' => '', 'body' => ''], 'csrf' => $this->session->csrfToken()]);
    }

    #[Auth] #[Post]
    public function store(): Response
    {
        $this->db->query('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
            [$this->request->str('title'), $this->request->str('body'), date('c')]);
        return Response::redirect('/posts/show/' . $this->db->lastInsertId());
    }

    #[Auth]
    public function edit(string $id): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post === null) return new Response('Post not found', 404);
        return $this->view->render('posts/edit', ['title' => 'Edit post', 'post' => $post, 'csrf' => $this->session->csrfToken()]);
    }

    #[Auth] #[Post]
    public function update(string $id): Response
    {
        $this->db->query('UPDATE posts SET title = ?, body = ? WHERE id = ?',
            [$this->request->str('title'), $this->request->str('body'), $id]);
        return Response::redirect("/posts/show/{$id}");
    }
```

(These four methods sit alongside `index()` and the interim `show()` from
Step 2 in the same class. `PostsController` has all six methods now,
though `show()` itself is still the interim version until Step 6.)

Create `app/views/posts/edit.php`: one form for both create and edit,
switching its action based on whether `$post['id']` is set:

```php
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
<form method="post" action="<?= $post['id'] ? '/posts/update/' . $this->e($post['id']) : '/posts/store' ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label>Title <input name="title" value="<?= $this->e($post['title']) ?>" required></label>
  <label>Body <textarea name="body" required><?= $this->e($post['body']) ?></textarea></label>
  <button>Save</button>
</form>
<form method="post" action="/auth/logout">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button>Log out</button>
</form>
```

**How it works, the convention router and its attributes**
(`src/Routing/Router.php`, `src/Routing/Attributes.php`):

- A method with **no verb attribute** is GET-only by convention. `#[Post]`
  (and `#[Put]`/`#[Delete]`, unused here) restrict it to that verb, hit
  `store()` with a GET and you get a **405 Method Not Allowed**, not a 404,
  with an `Allow` header naming the verbs that would work.
- `#[Auth]` marks a method as requiring a logged-in session. Hit
  `/posts/create` while logged out and the kernel redirects you to
  `/auth/login` before your controller code runs at all. You never write
  that check yourself.
- Every non-`GET` request is also checked for a CSRF token. `#[Auth]`
  routes are strict about it: the session's token must arrive as `_token`
  in the POST body (never the query string) or the request is rejected
  with **403**, no exceptions. That's what
  `$this->session->csrfToken()` (passed into the view as `csrf`) and the
  hidden `<input name="_token">` are for.

One more small edit while we're here. The skeleton's `AuthController` was
written before there was anywhere better to send a freshly-logged-in user
than the homepage. Now that `/posts` exists, point a successful login
there instead. In `app/src/Controllers/AuthController.php`, in `attempt()`:

```php
        if ($this->auth->attempt($email, $this->request->postStr('password'), $this->request->ip)) {
            return Response::redirect('/posts');
        }
```

(That's the only line that changes. `Response::redirect('/')` becomes
`Response::redirect('/posts')`. Everything else in `AuthController` stays
exactly as the skeleton had it.)

Log in, then visit <http://localhost:8080/posts/create>. Write a post, save
it, and you'll land on its `show` page. Edit it from there by visiting
`/posts/edit/<id>` directly (there's no edit link in the UI yet, feel free
to add one).

## Step 6: comments

Comments are guest-facing, no login required, which means they can't rely
on a session-bound CSRF token (there's no session yet for an anonymous
visitor). Add the migration, controller, and view for them.

`app/migrations/004_create_comments.php`:

```php
<?php // app/migrations/004_create_comments.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            author TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE comments'); }
};
```

`app/migrations/005_add_comments_post_id_index.php`, a plain index
migration, split out on its own so it's easy to skip on a low-traffic app
and add later without touching the table's shape:

```php
<?php // app/migrations/005_add_comments_post_id_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE INDEX idx_comments_post_id ON comments (post_id, created_at, id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP INDEX idx_comments_post_id'); }
};
```

```bash
php bin/kip migrate
```

```
Ran: 004_create_comments, 005_add_comments_post_id_index
```

`app/src/Controllers/CommentsController.php`:

```php
<?php // app/src/Controllers/CommentsController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Http\Response};
use Kip\Routing\Post;

final class CommentsController
{
    public function __construct(private Database $db, private Request $request) {}

    #[Post]
    public function store(string $postId): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$postId]);
        if ($post === null) return new Response('Post not found', 404);

        $author = $this->request->str('author');
        $body   = $this->request->str('body');
        if ($author === '' || $body === '') {
            return new Response('Author and comment are required.', 422);
        }

        $this->db->query('INSERT INTO comments (post_id, author, body, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $author, $body, date('c')]);
        return Response::redirect("/posts/show/{$postId}");
    }
}
```

Now wire comments into the post page. This is where the interim
`show()` and `show.php` from Step 2/3 become final. In
`PostsController`, replace `show()` with:

```php
    public function show(string $id): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post === null) return new Response('Post not found', 404);
        $comments = $this->db->all('SELECT * FROM comments WHERE post_id = ? ORDER BY created_at, id', [$id]);
        return $this->view->render('posts/show', [
            'title' => $post['title'],
            'post' => $post,
            'comments' => $comments,
        ]);
    }
```

And replace `app/views/posts/show.php` with:

```php
<?php // app/views/posts/show.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($post['title']) ?></h1>
  <p><?= nl2br($this->e($post['body'])) ?></p>
</article>
<?php require __DIR__ . '/_comments.php'; ?>
```

That last line references a partial you're about to write. Create
`app/views/posts/_comments.php`:

```php
<?php // app/views/posts/_comments.php ?>
<section>
  <h2>Comments</h2>
  <?php foreach ($comments as $c): ?>
    <p><strong><?= $this->e($c['author']) ?></strong>: <?= nl2br($this->e($c['body'])) ?></p>
  <?php endforeach; ?>
  <form method="post" action="/comments/store/<?= $this->e($post['id']) ?>">
    <label>Name <input name="author" required></label>
    <label>Comment <textarea name="body" required></textarea></label>
    <button>Add comment</button>
  </form>
</section>
```

Notice there's **no `_token` field** in that form, deliberately. Guest
routes without `#[Auth]` skip the mandatory-token check and instead get a
same-origin check: the kernel looks at the `Sec-Fetch-Site` header (falling
back to `Origin`/`Referer`) and compares it against the request's own
`Host`. A cross-site POST fails that check and gets rejected with 403; a
same-origin form submission, like the one your browser just sent, passes
without ever needing a token. The one deliberate gap: a request carrying
*none* of those headers is accepted rather than rejected, because legacy
browsers and privacy-hardened clients look exactly like that, and the worst
case on a guest-only route is a spam-shaped submission, not a
state-changing attack against a logged-in session. `docs/guide/06-security.md`
covers this lane in full, including where it does and doesn't apply.

Visit a post and leave a comment. It appears immediately, no login needed.

## Step 7: pagination

You already wrote this in Step 2. `PostsController::index()` fetches
`self::PER_PAGE + 1` rows (21) and checks whether it got more than 20 back,
rather than running a separate `COUNT(*)`. That's cheap at any table size.
Nothing left to do here except see it work: create 21+ posts (a quick loop
via `/posts/create` if you don't want to type that many, or seed the table
directly with SQLite) and `/posts` will show an "Older →" link; `/posts?page=2`
will show "← Newer" going back. Non-numeric or negative `page` values fall
back to page 1 rather than erroring.

## Watch the batteries work

Everything below has been running since Step 1. You just haven't looked at
it yet.

**The audit log.** Every request you've made, including the ones during
this tutorial, is in `app/logs.sqlite`:

```bash
php bin/kip logs
```

```
2026-08-16T02:16:51+00:00 200 GET    /posts                                      1.1ms ::1             guest
2026-08-16T02:17:03+00:00 302 POST   /posts/store                                2.4ms ::1             user:1
2026-08-16T02:17:11+00:00 200 GET    /posts/show/1                               1.0ms ::1             user:1
2026-08-16T02:17:20+00:00 302 POST   /comments/store/1                           1.8ms ::1             guest
```

Timestamp, status, method, path, timing, IP, and the user id if one was
logged in, nothing more. `bin/kip logs` and `bin/kip migrate` both prune
rows older than the retention window (`log_db.retention_days` in
`config.php`, default 30 days) every time they run, so the table doesn't
grow forever even if you forget to schedule the cron job in the deploy
section below.

**The page cache.** Guest `GET` requests are cached in `app/cache.sqlite`
and served with an `X-Kip-Cache` header telling you what happened:

```bash
curl -sI http://localhost:8080/posts/show/1 | grep -i x-kip-cache
```

```
X-Kip-Cache: MISS
```

Run the same `curl` again:

```
X-Kip-Cache: HIT
```

While rendering the miss, the kernel recorded which tables the page's
queries actually touched. `posts` and `comments`, since `show()` reads
both, and tagged the cached entry with them. Post a new comment on that
same post and the `comments` write purges every page tagged `comments`,
including this one:

```bash
curl -sI http://localhost:8080/posts/show/1 | grep -i x-kip-cache
```

```
X-Kip-Cache: MISS
```

Notice what *doesn't* get purged: `/posts` (the index) is tagged only
`posts`, since its query never touches `comments`, a comment on any post
leaves the index page's cache entry alone. That's the table-tag model in
one sentence: a cached page dies exactly when the data it actually read
changes, nothing more, nothing less.

**Login throttling.** The skeleton's auth battery came with this built in:
try it now. Log out, then submit the login form with a wrong password five
times in a row (or `curl -X POST` five times against `/auth/attempt`, note
`/auth/attempt`, not `/auth/login`, which only accepts `GET`). The first
five each show the ordinary "Wrong email or password" page. The sixth
request, even with the *correct* password, gets:

```
HTTP/1.1 429 Too Many Requests
```

with the login form re-rendered and "Too many attempts, try again in 15
minutes." Throttling keys on the email/IP pair over a 15-minute window and
resets on the next successful login. `docs/guide/06-security.md` covers the
one caveat worth knowing before you deploy behind a reverse proxy (the
`KIP_TRUSTED_PROXY` note below).

## Deploy

- Set `KIP_ENV=production` (or leave it unset. `prod` is the default) so
  stack traces never reach the browser; use `KIP_ENV=dev` only on your own
  machine.
- If TLS terminates at a reverse proxy in front of the app, set
  `KIP_TRUSTED_PROXY=1` so the session cookie's `secure` flag and the login
  throttle's IP both key off the real client, not the proxy.
- Run `php bin/kip migrate` as part of every deploy, before traffic is
  routed to the new code. It's idempotent (already-applied migrations are
  skipped) so it's safe to run on every deploy, not just ones that add a
  migration.
- Schedule log pruning independently of deploys, since a quiet app might not
  redeploy for weeks:
  ```
  0 3 * * * php /path/to/app/bin/kip logs:prune --days=30
  ```

`docs/guide/10-deployment.md` walks through a full VPS deploy shape.
PHP-FPM/nginx or `php -S` behind a reverse proxy, what to back up, and HTTPS
cookie behavior.

## Where to go next

You've now touched routing, controllers, views, migrations, auth, CSRF (both
lanes), the audit log, and the page cache, the whole battery lineup except
the CLI reference. Read [`docs/guide/README.md`](guide/README.md) for the
full user guide, chapter by chapter, or just keep building on the app you
already have.
