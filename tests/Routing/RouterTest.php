<?php // tests/Routing/RouterTest.php
namespace Kip\Tests\Routing;
use Kip\Container;
use Kip\Http\Request;
use Kip\Routing\Router;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        // Points at this test namespace, so /posts/... resolves to the fixture PostsController above.
        return new Router(namespace: 'Kip\\Tests\\Routing\\');
    }

    public function test_convention_maps_path_to_controller_action_args(): void
    {
        $m = $this->router()->match(new Request('GET', '/posts/show/42', [], [], []));
        $this->assertSame('show:42', $m->invoke(new Container()));
    }

    public function test_root_maps_to_home_index(): void
    {
        // '/' → HomeController::index. But Fake namespace has no Home, so 404
        $this->assertNull($this->router()->match(new Request('GET', '/', [], [], [])));
    }

    public function test_verb_attribute_enforced(): void
    {
        $r = $this->router();
        $this->assertSame('stored', $r->match(new Request('POST', '/posts/store', [], [], []))->invoke(new Container()));
        $this->expectException(\Kip\Routing\MethodNotAllowedException::class); // review 9A: wrong verb ≠ unknown route
        $r->match(new Request('GET', '/posts/store', [], [], []));
    }

    public function test_uppercase_path_is_not_matched(): void // review 9A: one canonical URL
    {
        $this->assertNull($this->router()->match(new Request('GET', '/POSTS/SHOW/1', [], [], [])));
    }

    public function test_auth_attribute_surfaces_on_match(): void
    {
        $m = $this->router()->match(new Request('GET', '/posts/edit/1', [], [], []));
        $this->assertTrue($m->requiresAuth);
    }

    public function test_router_rejects_traversal_path(): void
    {
        $this->assertNull($this->router()->match(new Request('GET', '/../etc/passwd', [], [], [])));
    }

    public function test_too_many_args_is_404(): void
    {
        $this->assertNull($this->router()->match(new Request('GET', '/posts/index/a/b/c', [], [], [])));
    }

    public function test_zero_is_a_valid_argument(): void // review D15: "0" must survive path splitting
    {
        $m = $this->router()->match(new Request('GET', '/posts/show/0', [], [], []));
        $this->assertSame('show:0', $m->invoke(new Container()));
        $this->assertNull($this->router()->match(new Request('GET', '/posts/index/0', [], [], []))); // extra arg must 404, not vanish
    }

    public function test_consecutive_slashes_are_not_matched(): void // review D15: canonicalization consistency
    {
        $this->assertNull($this->router()->match(new Request('GET', '/posts//show/1', [], [], [])));
    }

    /**
     * A separator only joins words: a controller segment that starts or ends with one,
     * or doubles one, would otherwise studly-case to the same controller as the clean
     * spelling and give one page several URLs.
     */
    public function test_stray_separators_in_the_controller_segment_are_not_matched(): void
    {
        foreach (['/_posts', '/-posts', '/posts-', '/posts_', '/po--sts', '/po__sts', '/po-_sts', '/-/show/1'] as $path) {
            $this->assertNull($this->router()->match(new Request('GET', $path, [], [], [])), $path);
        }
        $this->assertNotNull($this->router()->match(new Request('GET', '/posts', [], [], [])));
    }

    /**
     * PHP class names are case-insensitive, so /po-sts (PoStsController) used to find
     * PostsController. The studly name must match the declared class name exactly.
     */
    public function test_a_word_split_that_changes_the_class_name_case_is_not_matched(): void
    {
        foreach (['/po-sts', '/p_osts', '/post-s/show/1'] as $path) {
            $this->assertNull($this->router()->match(new Request('GET', $path, [], [], [])), $path);
        }
    }

    public function test_head_matches_get_routes(): void // v0.1.1 T1: HEAD must not 405 (RFC 9110)
    {
        $m = $this->router()->match(new Request('HEAD', '/posts/show/42', [], [], []));
        $this->assertNotNull($m);
        $this->assertSame('show:42', $m->invoke(new Container()));
    }

    public function test_head_still_405_on_post_only_routes(): void
    {
        $this->expectException(\Kip\Routing\MethodNotAllowedException::class);
        $this->router()->match(new Request('HEAD', '/posts/store', [], [], []));
    }
}
