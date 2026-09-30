<?php // tests/Testing/TestClientCookieTest.php
namespace Kip\Tests\Testing;
use Kip\App;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class TestClientCookieTest extends TestCase
{
    public function test_client_sends_cookies_it_was_given(): void
    {
        $app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $client = new TestClient($app);
        $client->cookie('kip_theme', 'dark');
        $res = $client->get('/api/list');
        $this->assertSame(200, $res->status);
    }
}
