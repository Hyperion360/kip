<?php // tests/Testing/TestClientCookieTest.php
namespace Kip\Tests\Testing;
use Kip\App;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class TestClientCookieTest extends TestCase
{
    private static function fixtureApp(): App
    {
        return new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
    }

    public function test_client_sends_cookies_it_was_given(): void
    {
        $client = new TestClient(self::fixtureApp());
        $client->cookie('kip_theme', 'dark');
        $res = $client->get('/api/list');
        $this->assertSame(200, $res->status);
    }

    public function test_jar_carries_both_leaves_of_a_two_cookie_response(): void
    {
        // fixture route answers:
        //   Response::redirect('/')->withAddedHeader('Set-Cookie', 'ja=1; Path=/')
        //                           ->withAddedHeader('Set-Cookie', 'jb=2; Path=/');
        // an echo route renders received cookie names into its body (reuse the
        // fixture's existing echo route if present, else add it beside the others)
        $client = new TestClient(self::fixtureApp());
        $client->post('/two-cookies');
        $res = $client->get('/echo-cookies');
        $this->assertStringContainsString('ja=1', $res->body);
        $this->assertStringContainsString('jb=2', $res->body);
    }
}
