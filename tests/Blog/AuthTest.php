<?php // tests/Blog/AuthTest.php
namespace Kip\Tests\Blog;

use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

// The blog's controllers share the App\Controllers namespace with the
// skeleton's, so this file requires its controller inside setUp() and runs
// isolated (same reason as NavTest).
//
// Covers the blog's /auth/attempt endpoint end to end: a failed login
// re-renders the login page with the submitted email, and that email is
// request input, so it must reach the page only through the view's escaping.
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class AuthTest extends TestCase
{
    private App $app;
    private Database $db;
    private TestClient $client;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Nav.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Text.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/HomeController.php';
        require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Controllers/AuthController.php';
        $blog = dirname(__DIR__, 2) . '/examples/blog';
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => $blog . '/app/views',
        ]);
        $this->db = $this->app->container->make(Database::class);
        (new Migrator($this->db, $blog . '/app/migrations'))->migrate();
        $this->client = new TestClient($this->app);
    }

    public function test_a_failed_attempt_escapes_the_email_into_the_form(): void
    {
        $res = $this->client->post('/auth/attempt', ['email' => '<script>alert(1)</script>@x.com', 'password' => 'wrong']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Wrong email or password', $res->body, 'the failure renders the login page');
        $this->assertStringContainsString('&lt;script&gt;', $res->body, 'the email is escaped into the form value');
        $this->assertStringNotContainsString('<script>', $res->body, 'and the raw tag never reaches the page');
    }
}
