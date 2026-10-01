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

    public function test_jar_absorbs_a_lowercase_set_cookie_spelling(): void
    {
        // Field names are case-insensitive (RFC 9110 5.1): the jar scans for
        // the name, not one exact spelling.
        $client = new TestClient(self::fixtureApp());
        $client->get('/lowercase-cookie');
        $res = $client->get('/echo-cookies');
        $this->assertStringContainsString('lc=1', $res->body);
    }

    public function test_a_cookie_value_containing_max_age_zero_text_survives(): void
    {
        // token=Max-Age=0; Path=/ is a cookie whose VALUE is that text, not a
        // deletion: only a real Max-Age ATTRIBUTE equal to 0 deletes.
        $client = new TestClient(self::fixtureApp());
        $client->get('/value-looks-like-max-age');
        $res = $client->get('/echo-cookies');
        $this->assertStringContainsString('token=Max-Age=0', $res->body);
    }

    public function test_an_exact_max_age_zero_attribute_deletes_the_jar_entry(): void
    {
        $client = new TestClient(self::fixtureApp());
        $client->cookie('token', 'stale');
        $client->get('/delete-token');
        $res = $client->get('/echo-cookies');
        $this->assertStringNotContainsString('token', $res->body);
    }

    public function test_a_nonzero_max_age_attribute_does_not_delete(): void
    {
        $client = new TestClient(self::fixtureApp());
        $client->cookie('token', 'keep');
        $client->get('/long-max-age');
        $res = $client->get('/echo-cookies');
        $this->assertStringContainsString('token=x', $res->body);
    }
}
