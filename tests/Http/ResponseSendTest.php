<?php // tests/Http/ResponseSendTest.php
namespace Kip\Tests\Http;

use Kip\Http\Response;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * send() touches the real SAPI. The CLI SAPI ignores header() (headers_list()
 * stays empty), so header VALUES can't be asserted here, the header loop
 * still executes, and status + body are asserted for real.
 */
final class ResponseSendTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_send_emits_status_and_body(): void
    {
        $this->expectOutputString('hello world');
        (new Response('hello world', 201, ['X-Kip-Test' => '1']))->send();
        $this->assertSame(201, http_response_code());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_send_empty_body_redirect(): void
    {
        $this->expectOutputString('');
        Response::redirect('/posts')->send();
        $this->assertSame(302, http_response_code());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_send_scalar_set_cookie(): void
    {
        // Set-Cookie must always emit in append mode (header($s, false), also
        // for a scalar value): replace mode would drop every earlier Set-Cookie
        // header, one already queued by session_start() included, losing the
        // session cookie. The CLI SAPI ignores header() and headers_list() stays
        // empty, so the replace flag itself is not assertable here; this pins
        // that a scalar Set-Cookie response passes through send() while the
        // append-mode change is verified by inspection (one header() call whose
        // replace argument is a strcasecmp on the name).
        $this->expectOutputString('ok');
        (new Response('ok', 200, ['Set-Cookie' => 'a=1; Path=/']))->send();
        $this->assertSame(200, http_response_code());
    }
}
