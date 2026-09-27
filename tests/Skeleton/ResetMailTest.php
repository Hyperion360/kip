<?php // tests/Skeleton/ResetMailTest.php
namespace Kip\Tests\Skeleton;
use Kip\{App, Auth, Database, Session};
use Kip\Http\Request;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/skeleton/app/src/Controllers/AuthController.php';

/**
 * The reset email goes only to existing accounts, so sending it inside the request
 * made a known email answer slower than an unknown one (5s against 0.02s against a
 * stalled relay). remind() defers the send until after the response is out.
 */
final class ResetMailTest extends TestCase
{
    private string $mailLog;
    private App $app;

    protected function setUp(): void
    {
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kip-mail-');
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__, 2) . '/skeleton/app/views',
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog],
        ]);
        $db = $this->app->container->make(Database::class);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, ip TEXT, attempted_at TEXT)');
        $db->query('CREATE TABLE password_resets (email TEXT PRIMARY KEY, token_hash TEXT, expires_at TEXT)');
        $store = [];
        (new Auth($db, new Session($store), static function (): void {}))->register('known@x.y', 'original-pass');
    }

    protected function tearDown(): void
    {
        @unlink($this->mailLog);
    }

    private function remind(string $email): string
    {
        $store = [];
        $session = new Session($store);
        $token = $session->csrfToken();
        $res = $this->app->handle(new Request('POST', '/auth/remind', [], ['email' => $email, '_token' => $token], [], '127.0.0.1'), $session);
        $this->assertSame(200, $res->status, $res->body);
        return $res->body;
    }

    public function test_reset_mail_is_sent_after_the_response_not_during_it(): void
    {
        $known = $this->remind('known@x.y');
        $this->assertSame('', (string) file_get_contents($this->mailLog), 'nothing sent while the response is built');

        $this->app->runDeferred();
        $this->assertStringContainsString('/auth/reset/', (string) file_get_contents($this->mailLog));

        $this->assertSame($known, $this->remind('ghost@x.y'), 'the same page either way');
        $this->app->runDeferred();
        $this->assertSame(1, substr_count((string) file_get_contents($this->mailLog), 'Subject:'), 'no mail for an unknown email');
    }

    public function test_the_test_client_runs_deferred_work_like_production_does(): void
    {
        (new TestClient($this->app))->postWithToken('/auth/remind', ['email' => 'known@x.y']);
        $this->assertStringContainsString('/auth/reset/', (string) file_get_contents($this->mailLog));
    }
}
