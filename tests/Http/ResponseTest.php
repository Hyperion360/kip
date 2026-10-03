<?php // tests/Http/ResponseTest.php
namespace Kip\Tests\Http;
use Kip\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_status_code_is_respected(): void // D4: a silently-swallowed status is a real-world top bug
    {
        $r = new Response('gone', 404);
        $this->assertSame(404, $r->status);
        $this->assertSame('gone', $r->body);
    }

    public function test_defaults_and_headers(): void
    {
        $r = new Response('ok');
        $this->assertSame(200, $r->status);
        $r = $r->withHeader('Content-Type', 'application/json');
        $this->assertSame('application/json', $r->headers['Content-Type']);
    }

    public function test_redirect_factory(): void
    {
        $r = Response::redirect('/login');
        $this->assertSame(302, $r->status);
        $this->assertSame('/login', $r->headers['Location']);
    }

    public function test_send_emits_body_and_status(): void // review 4A (header() is a no-op in CLI SAPI; body+code are assertable)
    {
        ob_start();
        (new Response('payload', 418))->send();
        $this->assertSame('payload', ob_get_clean());
        $this->assertSame(418, http_response_code());
    }

    public function test_secure_default_headers(): void // v0.1.1 T2: clickjacking + MIME-sniff defense
    {
        $r = new Response('ok');
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $r->headers['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $r->headers['Referrer-Policy']);
        $this->assertSame("base-uri 'self'; object-src 'none'", $r->headers['Content-Security-Policy']);
    }

    public function test_custom_headers_can_override_defaults(): void
    {
        $r = new Response('ok', 200, ['X-Frame-Options' => 'DENY', 'Content-Type' => 'text/plain']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']); // defaults merge under custom
    }

    public function testRejectsHeaderInjection(): void // response splitting dies at construction, not at send
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response('ok'))->withHeader('X-Evil', "a\r\nSet-Cookie: session=stolen");
    }

    public function testRejectsHeaderInjectionThroughTheConstructor(): void // the header loop validates too
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('ok', 200, ['X-Evil' => "a\nSet-Cookie: session=stolen"]);
    }

    public function test_header_names_must_be_rfc9110_tokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 200, ["X-Foo\r\nEvil" => '1']);
    }

    public function test_header_names_reject_colons_spaces_and_empty(): void
    {
        foreach (['X-Foo: bar', 'X Foo', '', 'X=Foo'] as $name) {
            try {
                new Response('', 200, [$name => 'v']);
                $this->fail("header name '$name' passed validation");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_integer_keyed_header_lists_are_refused_with_the_map_shape_named(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name => value map');
        new Response('', 200, ['x']);
    }

    public function test_valid_token_names_with_special_characters_pass(): void
    {
        $r = new Response('', 200, ["X.Corners*!#%" => 'v']);
        $this->assertSame('v', $r->headers["X.Corners*!#%"]);
    }
}
