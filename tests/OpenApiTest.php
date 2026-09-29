<?php // tests/OpenApiTest.php
namespace Kip\Tests;
use Kip\OpenApi;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI generator's contract, pinned as one exact snapshot: URL mapping
 * mirrors the Router (segment dasherization, lowercased action names, bare
 * index paths, required params only), verbs default to GET, #[Auth] adds
 * x-kip-auth, #[Json] switches the response media type, and discovery loads
 * nothing except files matching the router's controller shapes.
 */
final class OpenApiTest extends TestCase
{
    private function doc(): array
    {
        $fx = dirname(__DIR__) . '/tests/OpenApi/Fixtures';
        return (new OpenApi(
            [
                'Kip\\Tests\\OpenApi\\Fixtures\\Plain\\' => $fx . '/Plain',
                'Kip\\Tests\\OpenApi\\Fixtures\\Shadow\\' => $fx . '/alt',
            ],
            'Test API',
            ['Kip\\Tests\\OpenApi\\Fixtures\\Features\\' => $fx . '/Features'],
        ))->generate();
    }

    public function test_document_is_the_exact_snapshot(): void
    {
        $html = ['description' => 'OK', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]];
        $json = ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        $param = static fn(string $name): array => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        $op = static fn(string $id, string $tag, array $responses, bool $auth = false, array $params = []): array => array_filter([
            'operationId' => $id,
            'tags' => [$tag],
            'x-kip-auth' => $auth ?: null,
            'parameters' => $params ?: null,
            'responses' => ['200' => $responses],
        ], static fn(mixed $v): bool => $v !== null);

        $expected = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test API', 'version' => '0.1.0'],
            'paths' => [
                '/' => ['get' => $op('Home.index', 'Home', $html)],
                '/a-p-i' => ['get' => $op('API.index', 'API', $html)],
                '/billing' => ['get' => $op('Billing.index', 'Billing', $html)],
                '/billing/invoice/{id}' => ['get' => $op('Billing.invoice', 'Billing', $html, false, [$param('id')])],
                '/blog-posts' => ['get' => $op('BlogPosts.index', 'BlogPosts', $html)],
                '/blog-posts/edit/{id}' => ['get' => $op('BlogPosts.edit', 'BlogPosts', $html, true, [$param('id')])],
                '/blog-posts/feed' => ['get' => $op('BlogPosts.feed', 'BlogPosts', $json)],
                '/blog-posts/replace/{id}' => ['put' => $op('BlogPosts.replace', 'BlogPosts', $json, false, [$param('id')])],
                '/blog-posts/show/{slug}' => ['get' => $op('BlogPosts.show', 'BlogPosts', $html, false, [$param('slug')])],
                '/blog-posts/store' => ['post' => $op('BlogPosts.store', 'BlogPosts', $html)],
                '/dashboard/listing' => ['get' => $op('Dashboard.listing', 'Dashboard', $html)],
                '/gallery' => ['get' => $op('Gallery.index', 'Gallery', $html)],
                '/home/about' => ['get' => $op('Home.about', 'Home', $html)],
                '/snapshot/dowork' => ['get' => $op('Snapshot.doWork', 'Snapshot', $html)],
                '/snapshot/list_all' => ['get' => $op('Snapshot.list_all', 'Snapshot', $html)],
            ],
        ];
        $this->assertSame($expected, $this->doc());
    }

    /**
     * TRUST BOUNDARY, the headline mappings on their own for readable failures:
     * the index-with-required-params omission, the acronym fallback, the
     * action-segment case rule, and the shadow-source precedence.
     */
    public function test_paths_are_exactly_the_routable_ones(): void
    {
        $paths = array_keys($this->doc()['paths']);
        $this->assertSame([
            '/', '/a-p-i', '/billing', '/billing/invoice/{id}', '/blog-posts',
            '/blog-posts/edit/{id}', '/blog-posts/feed', '/blog-posts/replace/{id}',
            '/blog-posts/show/{slug}', '/blog-posts/store', '/dashboard/listing',
            '/gallery', '/home/about', '/snapshot/dowork', '/snapshot/list_all',
        ], $paths);
    }

    public function test_a_later_same_segment_controller_is_skipped_entirely(): void
    {
        $doc = $this->doc();
        $this->assertArrayNotHasKey('/blog-posts/extra', $doc['paths'], 'the shadowed controller adds no operations');
        $this->assertSame('BlogPosts.index', $doc['paths']['/blog-posts']['get']['operationId']);
    }

    public function test_an_index_with_required_parameters_is_omitted(): void
    {
        $this->assertArrayNotHasKey('/dashboard', $this->doc()['paths']);
    }
}
