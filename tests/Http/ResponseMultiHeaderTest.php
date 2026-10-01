<?php // tests/Http/ResponseMultiHeaderTest.php
declare(strict_types=1);
namespace Kip\Tests\Http;

use Kip\Http\Response;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ResponseMultiHeaderTest extends TestCase
{
    public function test_constructor_accepts_list_valued_headers(): void
    {
        $r = new Response('', 302, [
            'Location' => '/x',
            'Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/'],
        ]);
        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $r->headers['Set-Cookie']);
    }

    public function test_cr_lf_in_any_leaf_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 302, ['Set-Cookie' => ["a=1\r\nX-Evil: 1", 'b=2']]);
    }

    public function test_nested_arrays_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 302, ['Set-Cookie' => [['a=1']]]);
    }

    public function test_with_added_header_accumulates(): void
    {
        $r = Response::redirect('/x')
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/');
        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $r->headers['Set-Cookie']);
        // existing scalar under the same name is kept as the first leaf
        $r2 = (new Response('', 302, ['Set-Cookie' => 'a=1']))
            ->withAddedHeader('Set-Cookie', 'b=2');
        $this->assertSame(['a=1', 'b=2'], $r2->headers['Set-Cookie']);
    }

    public function test_scalar_headers_are_unchanged(): void
    {
        $r = new Response('ok', 200, ['Location' => '/y']);
        $this->assertSame('/y', $r->headers['Location']);
        $this->assertIsString($r->headers['Content-Type']);
    }

    public function test_page_cache_roundtrips_a_list_valued_header(): void
    {
        // Store a cacheable GET whose response carries a repeated X- header;
        // the HIT must carry both leaves (json_encode/decode preserves lists).
        $db = new \Kip\Database('sqlite::memory:');
        $cache = new \Kip\Cache\PageCache($db, 3600);
        $res = new Response('cached', 200, ['X-Test' => ['one', 'two']]);
        $cache->put('/t', '', $res, []);
        $hit = $cache->get('/t', '');
        $this->assertNotNull($hit);
        $this->assertSame(['one', 'two'], $hit->headers['X-Test']);
    }

    public function test_page_cache_refusal_scans_are_list_safe(): void
    {
        // put() casts header values to string for the X-Robots-Tag/Vary/ETag
        // scans; a list value must not fatal (Array-to-string): the leaf is
        // flattened and the refusal decision made per leaf.
        $db = new \Kip\Database('sqlite::memory:');
        $cache = new \Kip\Cache\PageCache($db, 3600);
        $noindex = new Response('x', 200, ['X-Robots-Tag' => ['noindex, nofollow']]);
        $cache->put('/n', '', $noindex, []);
        $this->assertNull($cache->get('/n', '')); // refused, and no TypeError
    }

    // RFC 9110 5.1: field names are case-insensitive. The map key an app's
    // spelling refers to must be found regardless of case, or two spellings
    // of one field ride the same response.
    public function test_with_header_replaces_under_the_existing_key_regardless_of_case(): void
    {
        $r = (new Response('x', 200, ['etag' => '"one"']))->withHeader('ETag', '"two"');
        $this->assertSame('"two"', $r->headers['etag']); // the existing key's spelling
        $this->assertArrayNotHasKey('ETag', $r->headers);
    }

    public function test_with_header_resolves_case_against_the_defaults_too(): void
    {
        // 'content-type' beside the default 'Content-Type' used to emit both.
        $r = (new Response('x'))->withHeader('content-type', 'text/plain');
        $this->assertSame('text/plain', $r->headers['Content-Type']);
        $this->assertArrayNotHasKey('content-type', $r->headers);
    }

    public function test_with_added_header_appends_into_the_existing_key_regardless_of_case(): void
    {
        $r = (new Response('', 302, ['Set-Cookie' => 'a=1']))->withAddedHeader('set-cookie', 'b=2');
        $this->assertSame(['a=1', 'b=2'], $r->headers['Set-Cookie']);
        $this->assertArrayNotHasKey('set-cookie', $r->headers);
    }

    public function test_constructor_rejects_input_names_differing_only_by_case(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 200, ['x-foo' => '1', 'X-Foo' => '2']);
    }

    public function test_constructor_rejects_an_input_name_colliding_with_a_default(): void
    {
        // Fail loud at the boundary, the assertHeaderSafe doctrine: an override
        // must use the default's spelling, or the response carries both.
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 200, ['content-type' => 'text/plain']);
    }

    public function test_exact_case_still_overrides_a_default(): void // the legal override path is unchanged
    {
        $r = new Response('ok', 200, ['Content-Type' => 'text/plain']);
        $this->assertSame('text/plain', $r->headers['Content-Type']);
    }

    // RFC 9110 6.4.2/14.4: Location names exactly one target; a list would
    // emit two redirect targets.
    public function test_a_list_valued_location_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 302, ['Location' => ['/a', '/b']]);
    }

    public function test_a_lowercase_list_valued_location_is_rejected_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('', 302, ['location' => ['/a', '/b']]);
    }

    public function test_with_header_rejects_a_list_valued_location(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect('/a')->withHeader('Location', ['/a', '/b']);
    }

    public function test_with_added_header_rejects_location(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect('/a')->withAddedHeader('Location', '/b');
    }

}
