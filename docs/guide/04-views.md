# 4. Views

Views are plain PHP files under `app/views/` (or wherever `config.php`'s
`views` key points). No template compiler, no custom syntax to learn.
`<?php ?>` and `<?= ?>` are the whole language.

## Rendering

```php
public function index(): string
{
    return $this->view->render('posts/index', ['posts' => $posts]);
}
```

`Kip\View::render(string $template, array $data = []): string`
(`src/View.php`) resolves `$template` to a file, `posts/index` →
`{views}/posts/index.php`, and extracts `$data` into local variables
available inside it (`extract($data, EXTR_SKIP)`), so
`['posts' => $posts]` becomes a `$posts` variable in the template.

## Escaping, `e()`

**Nothing is auto-escaped.** Kip does not compile templates or wrap output
for you; every value you print from user- or database-sourced data must go
through `$this->e()`:

```php
<h1><?= $this->e($post['title']) ?></h1>
<p><?= nl2br($this->e($post['body'])) ?></p>
```

`View::e(mixed $value): string` is `htmlspecialchars((string) $value,
ENT_QUOTES, 'UTF-8')`. Inside a template, `$this` is the `View` instance
doing the rendering, so `$this->e(...)` and `$this->layout(...)` are always
available. The discipline of calling `e()` on every interpolated value is
yours to maintain. There's no safety net catching an unescaped `<?=
$post['title'] ?>` for you. `nl2br()` is safe to apply *after* `e()` (as
above) since it only inserts `<br>` tags around already-escaped text;
never apply it before escaping.

## Layouts

```php
<?php // app/views/posts/show.php ?>
<?php $this->layout('layout'); ?>
<article><h1><?= $this->e($post['title']) ?></h1></article>
```

Calling `$this->layout('layout')` from inside a template tells `render()`
to wrap that template's output in `{views}/layout.php`, exposing the
rendered body there as `$content`:

```php
<?php // app/views/layout.php ?>
<!doctype html>
<html>
<body><main><?= $content ?></main></body>
</html>
```

`layout()` is a no-op call that just records a name. It doesn't render
anything itself. If the template you're rendering never calls it, no
layout wraps the output; that's how partials (below) opt out of layout
wrapping automatically.

## Partials

There's no partial-rendering helper. A plain PHP `require` is the
partial mechanism:

```php
<?php // app/views/posts/show.php ?>
<?php $this->layout('layout'); ?>
<article>...</article>
<?php require __DIR__ . '/_comments.php'; ?>
```

The required file sees the same local-variable scope as its parent
(`$post`, `$comments`, whatever the parent extracted), and since it's a
plain `require` rather than a call to `render()`, it doesn't get its own
layout. It's just more output appended to the current one. Prefix partial
filenames with `_` by convention (`_comments.php`) to mark them as
not-directly-routable fragments; nothing enforces this, it's a naming
convention only.

## Reserved data keys

The `__kip_` prefix is reserved: `View::render()` uses `__kip_`-prefixed
names (`__kip_template`, `__kip_data`, etc.) for its own internal
variables, and `extract()` runs with `EXTR_SKIP`, so a data key colliding
with any of them is silently ignored rather than clobbering the renderer's
internals. Treat the whole prefix as off-limits for your own keys, in
practice you'll never type one by accident.

One specific collision is guarded explicitly rather than left to
`EXTR_SKIP`: a data key literally named `content` (a very plausible name
for a caller to pick) cannot leak into `$content` inside the *layout* and
be mistaken for the rendered body. `render()` unconditionally overwrites
`$content` with the actual rendered output right before including the
layout file, so the layout always renders what was actually rendered, never
stray caller data with the same key name.

## Missing templates

Rendering a template or layout that doesn't exist throws
`Kip\TemplateNotFoundException` (`src/TemplateNotFoundException.php`, a
`RuntimeException`) with a message naming the template and the directory
searched, not a blank page or a PHP warning. In `prod` mode this becomes
the generic 500 page (see [chapter 6](06-security.md)); in `dev` mode
you'll see the exception and a stack trace directly.

## How it works

`View::render()` wraps the whole render (including the layout pass) in a
`try`/`finally` that restores the previous layout selection and closes any
output buffer the template opened but didn't clean up, a template that
throws mid-render can't leave a dangling `ob_start()` behind, and a partial
rendered via `render()` (as opposed to `require`) can't clobber its
caller's layout choice. This is what makes nested `render()` calls safe if
you ever reach for them instead of a `require`-based partial.
