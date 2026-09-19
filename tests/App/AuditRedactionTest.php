<?php // tests/App/AuditRedactionTest.php
namespace Kip\Tests\App;
use Kip\App;
use Kip\RequestLog;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class AuditRedactionTest extends TestCase
{
    public function test_reset_token_is_redacted_in_audit_log(): void
    {
        $app = new App([
            'env' => 'dev',
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        // Length pinned to Auth's generator so the redaction cannot drift from token generation.
        $token = bin2hex(random_bytes(\Kip\Auth::RESET_TOKEN_BYTES));
        $this->assertSame(\Kip\Auth::RESET_TOKEN_HEX, strlen($token));
        (new TestClient($app))->get('/auth/reset/' . $token); // 404 is fine, logging wraps process()
        $rows = $app->container->make(RequestLog::class)->recent(5);
        $this->assertStringContainsString('/auth/reset/<redacted>', $rows[0]['path']);
        $this->assertStringNotContainsString($token, $rows[0]['path']);
    }

    public function test_ordinary_paths_are_logged_verbatim(): void
    {
        $app = new App([
            'env' => 'dev',
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        (new TestClient($app))->get('/posts');
        $this->assertSame('/posts', $app->container->make(RequestLog::class)->recent(5)[0]['path']);
    }
}
