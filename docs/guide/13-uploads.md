# 13. File uploads

An upload is the one request where an attacker chooses both a filename
and the bytes behind it. Kip splits the problem in two:
`Kip\Http\Request::file()` hands your controller one clean `$_FILES`
entry, or null, and `Kip\Storage` (`src/Storage.php`) validates the
file and stores it under a name the server generated. Your controller
never parses `$_FILES` or touches the filesystem directly.

## Config

```php
// config.php
'uploads' => ['dir' => __DIR__ . '/public/uploads'],
```

Like every battery, the key's presence is the switch: `Kip\App` only
constructs `Storage` when `uploads.dir` exists, an app that never
uploads anything never builds the object. Two optional keys ride along:

```php
'uploads' => [
    'dir'       => __DIR__ . '/public/uploads',
    'max_bytes' => 10_485_760,               // default 5_242_880 (5 MiB)
    'ext'       => ['jpg', 'jpeg', 'png'],   // REPLACES the default whitelist
],
```

`Storage::put()` always returns `/uploads/<name>`, the public prefix
is fixed, so `dir` must be an `uploads/` directory directly under your
web root for the returned path to resolve.

## In a controller

```php
namespace App\Controllers;

use Kip\{Database, Http\Request, Http\Response, Session, Storage, UploadException};
use Kip\Routing\{Auth, Post};

final class AvatarsController
{
    public function __construct(
        private Database $db,
        private Storage $storage,
        private Request $request,
        private Session $session,
    ) {}

    #[Auth] #[Post]
    public function update(): Response
    {
        $file = $this->request->file('avatar');
        if ($file === null) {
            return new Response('Choose an image to upload', 422);
        }
        try {
            $path = $this->storage->put($file);
        } catch (UploadException $e) {
            return new Response($e->getMessage(), 422);
        }
        $this->db->query('UPDATE users SET avatar_path = ? WHERE id = ?',
            [$path, $this->session->get('user_id')]);
        return Response::redirect('/profile');
    }
}
```

The form side:

```html
<form method="post" action="/avatars/update" enctype="multipart/form-data">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="file" name="avatar" accept="image/*" required>
  <button>Upload</button>
</form>
```

`enctype="multipart/form-data"` is not optional, without it the
browser sends no file and `file()` returns null. The `_token` field is
the session-bound CSRF token every `#[Auth]` POST requires
([chapter 6](06-security.md)).

`Request::file()` returns the entry only for a well-formed,
successfully-uploaded file; an absent field, a PHP-level error, or a
mangled shape all come back null (null doesn't tell you which, "no
file chosen" is the answer worth showing). What reaches `put()` is
validated: anything that fails: oversize, disallowed extension, MIME
mismatch. Throws `UploadException` (`src/UploadException.php`, a
`RuntimeException` subclass) with a message safe to show. On success
`put()` returns the public path, `/uploads/9f2ab3c1d2e4f5a6.png`,
which you store in your table like any other string column.

## The threat model

`Storage`'s header comment states it outright: attacker-controlled
filename and content. The defenses:

| Defense | Mechanism | What it stops |
|---|---|---|
| Extension whitelist | `jpg jpeg png gif webp pdf txt` (`Storage::DEFAULT_EXT`); replaced via the `ext` key | `.php` and every other type; `.svg` is deliberately absent. A script inside an SVG served from your origin is stored XSS |
| MIME sniff | `finfo` reads the temp file's real content type; it must match the claimed extension (`Storage::MIME`) | a PHP payload renamed to `.png`. Finfo reads bytes, not names |
| Server-generated name | `bin2hex(random_bytes(8)) . '.' . $ext` | path traversal (`../../etc/passwd.png`), overwriting existing files, any attacker-chosen destination |
| Size cap | `size` over `max_bytes` throws | disk-filling uploads |
| Error-code check | anything but `UPLOAD_ERR_OK` throws, code in the message | partial or failed uploads being stored as truncated files |

The checks run cheap-first in `put()`: error, size, extension, MIME,
then move; `Request::file()` has already filtered errored entries, so
the error-code check is defense in depth for callers passing a raw
`$_FILES` entry. One nuance: PHP rejects files over its own
`upload_max_filesize` before your code runs. That arrives as
`UPLOAD_ERR_INI_SIZE` on the error-code row, not the size-cap row.

## Serving

Files land in `public/uploads` and are served by your web server as
static files. PHP is never on the read path, and a GET for an image
doesn't construct a controller. The extension whitelist is the
execution guard, never widen `ext` to anything your server (or any
`.htaccess`-style config) might execute, however unlikely that seems.

> **Exercise: add a cover image to the tutorial blog's posts.** Every
> piece exists already. (1) A migration adding a nullable `cover_path
> TEXT` to `posts` ([chapter 5](05-database-and-migrations.md)). (2)
> `enctype="multipart/form-data"` on the post create/edit form. (3) In
> `PostsController`'s store/update actions, `Request::file('cover')`.
> nullable, since a cover is optional, then `Storage::put()` and the
> returned path into the INSERT/UPDATE. (4) In the view, an
> `<img src="<?= $this->e($post['cover_path']) ?>">` guarded by a null
> check. No new concepts anywhere. That's the point.

## How it works

`Storage` is one class under 60 lines. `put()` creates the target
directory (`mkdir` 0755, recursive) if it's missing, then moves the
temp file through a constructor seam: `?callable $mover`, defaulting to
`move_uploaded_file()`. That PHP function only accepts files genuinely
uploaded through the SAPI, exactly right in production, unusable in a
test run, so `tests/StorageTest.php` injects `rename()` and exercises
everything else for real.

One honest rule: every stored extension gets a content check. The
built-in map (`Storage::MIME`) covers the default whitelist plus a few
common additions (`csv`); an extension you configure via `ext` that has
no entry in that map is **rejected**, not silently stored unverified;
extend the map in a subclass if you genuinely need a new type. The hard
denylist (execution and script-family types: `php`, `html`, `svg`,
`js`, `xml`, …) applies regardless of config. Two accepted caveats:
`pdf` is default-allowed and served from your origin, where some
viewers execute embedded script, strip it from the list (or serve
`/uploads` with `Content-Disposition: attachment`) if your threat model
cares; and no thumbnails, no S3 or any object
store, and images are never re-encoded. Finfo reads the magic bytes
at the head of the file, so a real PNG with junk appended passes and
keeps its junk. If that matters for your app, re-encode user images
yourself. These are boundary decisions, not TODOs, see
[design decisions](../design-decisions.md).
