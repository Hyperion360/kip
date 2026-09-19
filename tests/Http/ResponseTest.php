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
    }

    public function test_custom_headers_can_override_defaults(): void
    {
        $r = new Response('ok', 200, ['X-Frame-Options' => 'DENY', 'Content-Type' => 'text/plain']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']); // defaults merge under custom
    }
}
