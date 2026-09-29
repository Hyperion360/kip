<?php // tests/Http/JsonBodyTest.php
namespace Kip\Tests\Http;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

final class JsonBodyTest extends TestCase
{
    public function test_body_is_injected_through_the_constructor(): void
    {
        $r = new Request('POST', '/api/store', [], [], [], '', [], [], '{"a":1}');
        $this->assertSame('{"a":1}', $r->body);
        $this->assertSame('', (new Request('GET', '/', [], [], []))->body); // defaulted
    }

    public function test_json_parses_an_object_body_to_an_array(): void
    {
        $r = new Request('POST', '/x', [], [], [], '', [], [], '{"title": "Hi", "n": 7, "ok": true}');
        $this->assertSame(['title' => 'Hi', 'n' => 7, 'ok' => true], $r->json());
    }

    public function test_json_parses_a_top_level_array_body(): void
    {
        $r = new Request('POST', '/x', [], [], [], '', [], [], '[1,2,3]');
        $this->assertSame([1, 2, 3], $r->json());
    }

    public function test_json_is_null_on_malformed_json(): void
    {
        foreach (['{"broken": ', 'not json at all', '{1:2}', "{'single':1}"] as $body) {
            $r = new Request('POST', '/x', [], [], [], '', [], [], $body);
            $this->assertNull($r->json(), $body);
        }
    }

    public function test_json_is_null_on_an_empty_body(): void
    {
        $this->assertNull((new Request('POST', '/x', [], [], []))->json());
    }

    /** Scalars decode to non-arrays; the body contract is an object or array. */
    public function test_json_is_null_on_scalar_and_null_documents(): void
    {
        foreach (['"a string"', '42', 'true', 'null', '1.5'] as $body) {
            $r = new Request('POST', '/x', [], [], [], '', [], [], $body);
            $this->assertNull($r->json(), $body);
        }
    }

    /** Invalid UTF-8 must fail to parse, not be silently substituted: a 400 is the honest answer. */
    public function test_json_is_null_on_invalid_utf8(): void
    {
        $r = new Request('POST', '/x', [], [], [], '', [], [], "\"\xB1\x31\"");
        $this->assertNull($r->json());
    }

    /** The CLI sapi never reads stdin as a request body; phpunit runs under CLI, so this pins the guard. */
    public function test_from_globals_body_is_empty_under_the_cli_sapi(): void
    {
        $r = Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x']);
        $this->assertSame('', $r->body);
    }

    /** CGI and FPM expose the content type as the bare CONTENT_TYPE key, not HTTP_CONTENT_TYPE (codex fold 1). */
    public function test_from_globals_normalizes_cgi_content_type(): void
    {
        $r = Request::fromGlobals(server: [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ]);
        $this->assertSame('application/json; charset=utf-8', $r->header('Content-Type'));
    }

    public function test_from_globals_http_content_type_still_wins(): void
    {
        $r = Request::fromGlobals(server: [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x',
            'CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_TYPE' => 'text/plain',
        ]);
        $this->assertSame('text/plain', $r->header('content-type'));
    }

    public function test_from_globals_without_any_content_type_has_no_header(): void
    {
        $r = Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x']);
        $this->assertNull($r->header('content-type'));
    }
}
