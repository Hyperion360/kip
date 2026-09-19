# 11. Testing your app

`Kip\Testing\TestClient` (`src/Testing/TestClient.php`) drives your
real `App` the way a browser would, one full request/response cycle
per call: with no server, no sockets, and no database on disk: point
the config at `sqlite::memory:` and assert on real `Response` objects.

## The client

```php
$client = new TestClient($app);                      // your real App
$client->get('/posts', ['page' => '2']);             // GET, query params
$client->post('/comments/store', ['body' => 'hi']);  // POST, no CSRF token
$client->postWithToken('/posts/store', $data);       // POST, session CSRF token merged in
$client->postWithFile('/uploads/store', $data, 'doc', $file); // POST a $_FILES entry + token
$client->actingAs(1);                                // log in as user 1, no password round-trip
$client->csrfToken();                                // the session's current token
$client->request('POST', '/x', [], $data, ['sec-fetch-site' => 'cross-site']); // full control
```

Every call returns a real `Kip\Http\Response`, assert on `->status`,
`->body`, `->headers` (default security headers included; see
[chapter 3](03-controllers.md)). `postWithToken()` is the workhorse:
`#[Auth]` routes require the session CSRF token outright
([chapter 6](06-security.md)), a bare `post()` against `/posts/store`
comes back 403.

## The cookie rule

A fresh client is a cookieless guest; a client whose session holds
state sends a cookie. The rule keeps the page cache honest:
`App::cachedProcess()` considers a GET cacheable only when it carries
no cookies at all ([chapter 7](07-performance.md)), and `TestClient`
mirrors that. Until the session holds state, requests go out
cookieless and a configured page cache can serve a HIT; once it does,
every request carries `kip_test_session=1` and is a BYPASS, exactly
like a logged-in visitor.

## A worked example: create a post end to end

The create-post flow from the framework's own fixture suite
(`tests/App/PostsFlowTest.php`), adapted to the [tutorial](../tutorial.md)
app shape, the controller parts under test:

```php
#[Auth]
public function create(): string
{
    return $this->view->render('posts/edit', ['post' => ['id' => null, 'title' => '', 'body' => ''],
        'csrf' => $this->session->csrfToken()]);
}

#[Auth] #[Post]
public function store(): Response
{
    $this->db->query('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$this->request->str('title'), $this->request->str('body'), date('c')]);
    return Response::redirect('/posts/show/' . $this->db->lastInsertId());
}
```

And the test: GET the form, POST it with the token, read the row back:

```php
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class CreatePostFlowTest extends TestCase
{
    private TestClient $client;

    protected function setUp(): void
    {
        $app = new App(['env' => 'dev', 'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\', 'views' => __DIR__ . '/../app/views']);
        $db = $app->container->make(Database::class);
        $db->query('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT, created_at TEXT)');
        $this->client = new TestClient($app);
    }

    public function test_author_creates_a_post(): void
    {
        $this->client->actingAs(1);
        $this->assertSame(200, $this->client->get('/posts/create')->status); // the form

        $res = $this->client->postWithToken('/posts/store', [   // submit it
            'title' => 'Hello <b>world</b>',
            'body'  => 'First!',
        ]);
        $this->assertSame(302, $res->status);

        $show = $this->client->get('/posts/show/1');            // read it back
        $this->assertSame(200, $show->status);
        $this->assertStringContainsString('Hello &lt;b&gt;world&lt;/b&gt;', $show->body); // escaped
    }
}
```

`actingAs(1)` sets the session's `user_id`, the same key
`Auth::attempt()` sets on a real login. So no `users` table is needed
unless your code calls `Auth::user()`.

## phpunit.xml

Bootstrap the app's autoloader (your `App\` classes plus the
`kip/framework` package), point at `tests`, run `vendor/bin/phpunit`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
  <testsuites><testsuite name="app"><directory>tests</directory></testsuite></testsuites>
</phpunit>
```

## How it works

`TestClient` holds your `App`, one private array (`$store`), and a
`Session` constructed over it. `Session`'s constructor takes the store
by reference, so the same array backs every call and state (login, CSRF
token) survives between calls. Every helper bottoms out in `request()`,
which builds a `Request` (IP `127.0.0.1`, cookies synthesized per the
rule above) and calls `App::handle(Request, Session)` directly: one
full kernel cycle: routing, both CSRF lanes, the `#[Auth]` gate,
constructor autowiring, error handling, the same path
`public/index.php` drives, minus `Response::send()`.

## What it doesn't cover

- **In-process only.** No real browser, no JavaScript, moot for a Kip
  app, which ships no client-side code ([chapter 7](07-performance.md)).
- **No network layer.** `Response::send()` never runs, so header
  emission, the status line, HTTPS cookie flags (`SessionStarter`
  reads the SAPI), and web-server behavior aren't exercised, verify
  them against a real deploy with `curl -I` ([chapter 10](10-deployment.md)).
- **Uploads are simulated.** `postWithFile()` passes your array through
  `Request::$files` untouched. Nothing checks it came from a real SAPI
  upload. A fake entry is `$_FILES`-shaped, with real bytes behind
  `tmp_name` (finfo checks them):

  ```php
  $tmp = tempnam(sys_get_temp_dir(), 'kipu');
  file_put_contents($tmp, $bytes);
  $file = ['name' => 'photo.png', 'tmp_name' => $tmp, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, 'type' => ''];
  ```

  `Storage::put()` validates that entry for real, extension whitelist,
  finfo MIME against the tmp file's bytes. But its default mover is
  `move_uploaded_file()`, which rejects non-SAPI files. The
  constructor's `$mover` seam exists for this; bind a stand-in so
  controllers receive it:

  ```php
  $app->container->instance(Storage::class, new Storage('/tmp/test-uploads', mover: fn ($t, $d) => rename($t, $d)));
  ```
