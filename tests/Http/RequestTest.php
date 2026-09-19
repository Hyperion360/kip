<?php // tests/Http/RequestTest.php
namespace Kip\Tests\Http;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_from_globals_style_construction(): void
    {
        $r = new Request(method: 'POST', path: '/posts/edit/42', get: [], post: ['title' => 'Hi'], cookies: []);
        $this->assertSame('POST', $r->method);
        $this->assertSame('/posts/edit/42', $r->path);
        $this->assertSame('Hi', $r->input('title'));
        $this->assertNull($r->input('missing'));
        $this->assertSame('x', $r->input('missing', 'x'));
    }

    public function test_path_is_normalized(): void
    {
        $r = new Request('GET', '/team/', [], [], []);
        $this->assertSame('/team', $r->path); // trailing-slash normalization, D4
        $this->assertSame('/', (new Request('GET', '/', [], [], []))->path);
    }

    public function test_str_casts_and_trims(): void // review 3A: the framework's most-typed line
    {
        $r = new Request('POST', '/x', [], ['title' => '  Hi  ', 'n' => 7], []);
        $this->assertSame('Hi', $r->str('title'));
        $this->assertSame('7', $r->str('n'));
        $this->assertSame('', $r->str('missing'));
    }

    public function test_from_globals_uses_remote_addr_by_default(): void // v0.1.1 T5 (also closes the fromGlobals coverage gap)
    {
        $r = Request::fromGlobals(server: [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/a/b?x=1',
            'REMOTE_ADDR' => '9.9.9.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);
        $this->assertSame('POST', $r->method);
        $this->assertSame('/a/b', $r->path);
        $this->assertSame('9.9.9.9', $r->ip); // XFF ignored when proxy is not trusted
    }

    public function test_from_globals_trusted_proxy_uses_last_forwarded_ip(): void // review D3: proxy APPENDS the true client. Last element is the only trustworthy one
    {
        $r = Request::fromGlobals(server: [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 1.2.3.4',
        ], trustedProxy: true);
        $this->assertSame('1.2.3.4', $r->ip); // client-spoofed '9.9.9.9' prefix must never win the throttle key
    }

    public function test_trusted_proxy_with_invalid_forwarded_ip_falls_back(): void
    {
        $r = Request::fromGlobals(server: [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'evil<script>',
        ], trustedProxy: true);
        $this->assertSame('10.0.0.1', $r->ip); // garbage XFF never becomes the throttle key
    }

    public function test_post_str_ignores_query_string(): void // v0.1.1 T6: credentials never from GET
    {
        $r = new Request('POST', '/x', ['password' => 'from-query'], ['password' => ' from-body '], []);
        $this->assertSame('from-body', $r->postStr('password'));
        $this->assertSame('', $r->postStr('only_in_get'));
        $r2 = new Request('POST', '/x', ['token' => 'evil'], [], []);
        $this->assertSame('', $r2->postStr('token'));
        $r3 = new Request('POST', '/x', [], ['password' => ['a']], []); // array input fails closed, not TypeError (QA T6)
        $this->assertSame('', $r3->postStr('password'));
    }

    public function test_origin_and_sec_fetch_site_are_exposed(): void // v0.2 T2
    {
        $r = new Request('POST', '/x', [], [], [], '', ['origin' => 'https://example.com', 'sec-fetch-site' => 'same-origin']);
        $this->assertSame('https://example.com', $r->header('origin'));
        $this->assertSame('same-origin', $r->header('sec-fetch-site'));
        $this->assertNull($r->header('absent'));
    }
}
