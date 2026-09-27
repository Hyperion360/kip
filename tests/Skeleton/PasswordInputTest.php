<?php // tests/Skeleton/PasswordInputTest.php
namespace Kip\Tests\Skeleton;
use Kip\{App, Auth, Database, Session};
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The skeleton's own controller, loaded directly: no other test declares
// App\Controllers\AuthController, so it cannot collide.
require_once dirname(__DIR__, 2) . '/skeleton/app/src/Controllers/AuthController.php';

/**
 * password_hash() throws ValueError on a NUL byte, so a new password carrying one
 * must be refused as bad input before it gets there, not surface as a 500.
 */
final class PasswordInputTest extends TestCase
{
    public function test_reset_refuses_a_nul_byte_password_with_a_form_error(): void
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__, 2) . '/skeleton/app/views',
            'mail' => ['transport' => 'log', 'log_path' => '/dev/null'], // never sent: confirm() mails nothing
        ]);
        $db = $app->container->make(Database::class);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, ip TEXT, attempted_at TEXT)');
        $db->query('CREATE TABLE password_resets (email TEXT PRIMARY KEY, token_hash TEXT, expires_at TEXT)');
        $store = [];
        $auth = new Auth($db, new Session($store), static function (): void {});
        $auth->register('reset@x.y', 'original-pass');
        $token = (string) $auth->createReset('reset@x.y');

        $res = (new TestClient($app))->postWithToken('/auth/confirm', ['token' => $token, 'password' => "new\0password"]);

        $this->assertSame(422, $res->status, $res->body);
        $this->assertStringContainsString('cannot contain', $res->body);
        $hash = (string) $db->one('SELECT password_hash FROM users')['password_hash'];
        $this->assertTrue(password_verify('original-pass', $hash), 'the password must be unchanged');
    }
}
