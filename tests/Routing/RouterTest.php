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

    /**
     * Path segments are always strings, so an app action declaring int relies on
     * coercion at the call site in RouteMatch::invoke(). That file must NOT
     * declare strict_types, or every app route with an int parameter throws a
     * TypeError. Verified: the strict call raises
     * "Argument #2 ($position) must be of type int, string given".
     */
    public function test_int_route_parameter_still_coerces_from_a_path_segment(): void
    {
        $m = $this->router()->match(new Request('GET', '/posts/rank/my-story/3', [], [], []));
        $this->assertNotNull($m);
        $this->assertSame('rank:my-story:3', $m->invoke(new Container()));
    }

    public function test_root_maps_to_home_index(): void
    {
        // '/' → HomeController::index, the root page's only spelling; '/home' 404s below
        $m = $this->router()->match(new Request('GET', '/', [], [], []));
        $this->assertNotNull($m);
        $this->assertSame('home', $m->invoke(new Container()));
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

    /** TRUST BOUNDARY, both directions: a bare #[Auth] and an ungated action carry no policy. */
    public function test_policy_name_surfaces_on_the_match(): void
    {
        $m = $this->router()->match(new Request('GET', '/posts/destroy/9', [], [], []));
        $this->assertNotNull($m);
        $this->assertSame('can-edit', $m->policy);
        $this->assertTrue($m->requiresAuth, 'a policy route is gated too');
    }

    public function test_policy_defaults_to_null_in_both_directions(): void
    {
        $bare = $this->router()->match(new Request('GET', '/posts/edit/1', [], [], []));
        $this->assertNotNull($bare);
        $this->assertNull($bare->policy, 'a bare #[Auth] gates login-only');
        $open = $this->router()->match(new Request('GET', '/posts/show/1', [], [], []));
        $this->assertNull($open->policy, 'an ungated action carries no policy');
    }

    public function test_positional_string_policy_is_accepted(): void
    {
        $this->assertSame('positional', $this->router()->match(new Request('GET', '/posts/purge', [], [], []))->policy);
    }

    public function test_method_policy_overrides_the_class_policy(): void
    {
        $r = $this->router();
        $this->assertSame('admin-only', $r->match(new Request('GET', '/policy-panel', [], [], []))->policy, 'the class policy reaches undecorated actions');
        $this->assertSame('override', $r->match(new Request('GET', '/policy-panel/settings', [], [], []))->policy, 'the action\'s own policy wins');
        $this->assertSame('admin-only', $r->match(new Request('GET', '/policy-panel/reports', [], [], []))->policy, 'a bare method #[Auth] does not clear the class policy');
    }

    public function test_a_child_declaration_wins_over_the_parent(): void
    {
        $r = $this->router();
        $this->assertSame('narrower', $r->match(new Request('GET', '/sub-panel/settings', [], [], []))->policy, 'the override\'s policy beats the parent class\'s');
        $this->assertSame('admin-only', $r->match(new Request('GET', '/sub-panel', [], [], []))->policy, 'an inherited action keeps the parent class policy');
    }

    /** A typo'd named argument must fail closed, never fall back to login-only while the author believes a policy runs. */
    public function test_malformed_policy_arguments_fail_loud(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('policy');
        $this->router()->match(new Request('GET', '/typo-policy', [], [], []));
    }

    /** Short-name trust boundary: an app-local Auth whose arguments cannot name a policy fails loud, not silently login-only. */
    public function test_an_app_local_auth_with_unrelated_arguments_fails_loud(): void
    {
        require_once __DIR__ . '/LocalGate.php';
        $this->expectException(\LogicException::class);
        (new Router(namespace: 'Kip\\Tests\\Routing\\LocalGate\\'))->match(new Request('GET', '/notes', [], [], []));
    }

    public function test_router_rejects_traversal_path(): void
    {
        $this->assertNull($this->router()->match(new Request('GET', '/../etc/passwd', [], [], [])));
    }

    /** TRUST BOUNDARY, both directions: a filter matching every attribute would set json everywhere, one matching nothing nowhere. */
    public function test_json_attribute_flags_the_match_in_both_directions(): void
    {
        $r = $this->router();
        $marked = $r->match(new Request('GET', '/report/stats', [], [], []));
        $this->assertNotNull($marked);
        $this->assertTrue($marked->json, 'a #[Json] action must carry the flag');
        $plain = $r->match(new Request('GET', '/report/plain', [], [], []));
        $this->assertNotNull($plain);
        $this->assertFalse($plain->json, 'an action without #[Json] must not carry the flag');
    }

    public function test_class_level_json_flags_every_action(): void
    {
        $r = $this->router();
        foreach (['/catalog', '/catalog/item/7'] as $path) {
            $m = $r->match(new Request('GET', $path, [], [], []));
            $this->assertNotNull($m, $path);
            $this->assertTrue($m->json, "a class-level #[Json] must flag {$path}");
        }
    }

    public function test_json_flag_defaults_false_on_older_style_matches(): void
    {
        $this->assertFalse($this->router()->match(new Request('GET', '/posts', [], [], []))->json);
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

    public function test_trailing_slash_is_not_matched(): void // one canonical URL: /posts/ must not alias /posts
    {
        foreach (['/posts/', '/posts/show/1/', '/team/'] as $path) {
            $this->assertNull($this->router()->match(new Request('GET', $path, [], [], [])), $path);
        }
    }

    public function test_alias_spellings_are_not_matched(): void // /home, /posts/index, //browse
    {
        foreach (['/home', '/home/index', '/posts/index', '/posts/index/7', '//browse', '//'] as $path) {
            $this->assertNull($this->router()->match(new Request('GET', $path, [], [], [])), $path);
        }
        // The canonical spellings still resolve: '/' and '/posts' (action index by default).
        $this->assertNotNull($this->router()->match(new Request('GET', '/posts', [], [], [])));
    }

    public function test_feature_folder_controller_resolves(): void
    {
        $r = new Router(namespace: 'Kip\\Tests\\Routing\\None\\', featureNamespace: 'Kip\\Tests\\Routing\\Features\\');
        $m = $r->match(new Request('GET', '/billing', [], [], []));
        $this->assertSame('Kip\\Tests\\Routing\\Features\\Billing\\BillingController', $m->class);
        $this->assertSame('feature billing home', $m->invoke(new Container()));
    }

    public function test_feature_folder_controller_receives_action_and_args(): void
    {
        $r = new Router(namespace: 'Kip\\Tests\\Routing\\None\\', featureNamespace: 'Kip\\Tests\\Routing\\Features\\');
        $m = $r->match(new Request('GET', '/billing/invoice/42', [], [], []));
        $this->assertSame('feature invoice:42', $m->invoke(new Container()));
    }

    /** Review P2-1: plain namespaces win over feature folders, mirroring the View's app-root-first fallback. */
    public function test_a_plain_controller_wins_over_a_same_named_feature_controller(): void
    {
        $r = new Router(namespace: 'Kip\\Tests\\Routing\\', featureNamespace: 'Kip\\Tests\\Routing\\Features\\');
        $m = $r->match(new Request('GET', '/billing', [], [], []));
        $this->assertSame('Kip\\Tests\\Routing\\BillingController', $m->class);
        $this->assertSame('plain billing', $m->invoke(new Container()));
        // Plain resolution is unchanged when the feature form is on:
        $this->assertSame('Kip\\Tests\\Routing\\PostsController', $r->match(new Request('GET', '/posts', [], [], []))->class);
    }

    public function test_null_feature_namespace_disables_the_feature_form(): void
    {
        $r = new Router(namespace: 'Kip\\Tests\\Routing\\None\\', featureNamespace: null);
        $this->assertNull($r->match(new Request('GET', '/billing', [], [], [])));
    }

    public function test_feature_class_name_case_guard_rejects_a_word_split(): void
    {
        $r = new Router(namespace: 'Kip\\Tests\\Routing\\None\\', featureNamespace: 'Kip\\Tests\\Routing\\Features\\');
        $this->assertNotNull($r->match(new Request('GET', '/billing', [], [], []))); // loads the feature class first
        // class_exists() ignores case, so /bil-ling (BilLing\BilLingController) would find
        // Billing\BillingController: the declared-name guard applies to the feature form too.
        $this->assertNull($r->match(new Request('GET', '/bil-ling', [], [], [])));
    }
}
