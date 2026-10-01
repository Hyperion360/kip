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

}
