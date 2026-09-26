<?php // tests/Routing/AttributeAutoloadTest.php
namespace Kip\Tests\Routing;

use Kip\Routing\Router;
use Kip\Http\Request;
use PHPUnit\Framework\TestCase;

// REQUIRED, not optional (outside review 4): Fixtures.php declares
// Kip\Tests\Routing\PostsController, and PSR-4 cannot autoload it because the
// file name does not match the class name. Verified: class_exists() on it
// returns false. Without this require the router matches nothing and the gate
// test below fails when run standalone. RouterTest.php:8 does the same.
require_once __DIR__ . '/Fixtures.php';

final class AttributeAutoloadTest extends TestCase
{
    /**
     * PSR-4 must be able to resolve every route attribute by name. Before the
     * one-class-per-file split these all lived in Attributes.php and none of
     * them was autoloadable; the router happened not to care because it
     * compares attribute names as strings.
     */
    public function test_every_route_attribute_is_autoloadable(): void
    {
        foreach (['Get', 'Post', 'Put', 'Delete', 'Auth'] as $short) {
            $fqcn = 'Kip\\Routing\\' . $short;
            $this->assertTrue(
                class_exists($fqcn),
                "{$fqcn} must be autoloadable via PSR-4 (one class per file)"
            );
        }
    }

    /** An attribute class must be instantiable, which needs a real autoload. */
    public function test_auth_attribute_can_be_instantiated(): void
    {
        $this->assertInstanceOf(\Kip\Routing\Auth::class, new \Kip\Routing\Auth());
    }

    /**
     * TRUST BOUNDARY. #[Auth] is the authorization gate. Assert BOTH directions:
     * a positive-only test would still pass if the attribute filter matched
     * every attribute, which would make requiresAuth true everywhere; a
     * negative-only test would pass if it matched nothing, which would make
     * every gated route public.
     *
     * Reuses the existing tests/Routing/Fixtures.php controller: edit() carries
     * #[Auth], show() does not. The pre-existing
     * RouterTest::test_auth_attribute_surfaces_on_match covers only the
     * positive direction, which is exactly the blind spot this closes.
     */
    public function test_auth_attribute_gates_in_both_directions(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\');

        $gated = $router->match(new Request('GET', '/posts/edit/1', [], [], []));
        $this->assertNotNull($gated);
        $this->assertTrue($gated->requiresAuth, '#[Auth] must still mark a route as gated');

        $open = $router->match(new Request('GET', '/posts/show/42', [], [], []));
        $this->assertNotNull($open);
        $this->assertFalse($open->requiresAuth, 'a method without #[Auth] must stay public');
    }

    /**
     * TRUST BOUNDARY, fail closed. An #[Auth] whose class was never imported
     * resolves to the controller's own namespace (or the global one, for
     * #[\Auth]), not to Kip\Routing\Auth. Matching the attribute by its fully
     * qualified name left such a route silently public, while the verb
     * attributes kept working because they matched by short name. Authorization
     * must fail closed (docs/design-decisions.md), so #[Auth] matches the same
     * way the verbs do.
     */
    public function test_unimported_auth_attribute_still_gates_the_route(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        $wipe = $router->match(new Request('POST', '/secret/wipe', [], [], []));
        $this->assertNotNull($wipe);
        $this->assertTrue($wipe->requiresAuth, 'an unimported #[Auth] must still gate the route');

        $peek = $router->match(new Request('GET', '/secret/peek', [], [], []));
        $this->assertNotNull($peek);
        $this->assertTrue($peek->requiresAuth, 'a global-namespace #[\\Auth] must still gate the route');

        $open = $router->match(new Request('GET', '/secret/open', [], [], []));
        $this->assertNotNull($open);
        $this->assertFalse($open->requiresAuth, 'a method with no Auth attribute must stay public');

        $this->expectException(\Kip\Routing\MethodNotAllowedException::class);
        $router->match(new Request('GET', '/secret/wipe', [], [], []));  // unimported #[Post] still enforced
    }

    /**
     * PHP resolves class names case-insensitively, and the router must match the
     * same way. An earlier version of the short-name fix compared with ===, which
     * regressed an IMPORTED #[\Kip\Routing\AUTH]: the old fully qualified filter
     * gated it, the string compare left it public and on the lighter CSRF lane.
     */
    public function test_auth_attribute_matches_in_any_letter_case(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        foreach (['lower' => 'unimported #[auth]', 'upper' => 'imported #[\\Kip\\Routing\\AUTH]'] as $action => $label) {
            $m = $router->match(new Request('GET', '/secret/' . $action, [], [], []));
            $this->assertNotNull($m);
            $this->assertTrue($m->requiresAuth, "{$label} must gate the route");
        }

        $post = $router->match(new Request('POST', '/secret/lowverb', [], [], []));
        $this->assertNotNull($post, 'a lowercase #[post] must still allow POST');
        $this->expectException(\Kip\Routing\MethodNotAllowedException::class);
        $router->match(new Request('GET', '/secret/lowverb', [], [], [])); // POST-only, not the GET default
    }

    /** A class-level #[Auth] on a base controller gates every subclass action. */
    public function test_class_level_auth_on_a_parent_gates_the_subclass(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        foreach (['index' => 'the subclass\'s own action', 'shared' => 'an inherited action'] as $action => $label) {
            $m = $router->match(new Request('GET', '/vault-child/' . $action, [], [], []));
            $this->assertNotNull($m);
            $this->assertTrue($m->requiresAuth, "{$label} must inherit the parent's #[Auth]");
        }
    }

    /** A class-level #[Auth] on an implemented interface gates the controller. */
    public function test_class_level_auth_on_an_interface_gates_the_implementer(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        $m = $router->match(new Request('GET', '/vault-contract/index', [], [], []));
        $this->assertNotNull($m);
        $this->assertTrue($m->requiresAuth, "an interface's #[Auth] must gate the implementing controller");
    }

    /**
     * A method-level #[Auth] anywhere the action is declared, on a parent class or on
     * an interface, gates it. An override that drops the attribute must not silently
     * make a gated action public; other actions stay public.
     */
    public function test_method_level_auth_on_a_parent_or_interface_declaration_gates_the_action(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        $cases = [
            '/contract-actions/secret' => [true, 'an interface signature carrying #[Auth]'],
            '/contract-actions/open' => [false, 'an action gated nowhere'],
            '/overriding-child/edit' => [true, 'an override of a parent method carrying #[Auth]'],
            '/overriding-child/view' => [false, 'an inherited action gated nowhere'],
        ];
        foreach ($cases as $path => [$gated, $label]) {
            $m = $router->match(new Request('GET', $path, [], [], []));
            $this->assertNotNull($m, $path);
            $this->assertSame($gated, $m->requiresAuth, "{$label} ({$path})");
        }
    }

    /**
     * Traits: a method-level #[Auth] from a trait gates that action, and a class-level
     * #[Auth] on a trait, used directly or through another trait, gates every action.
     */
    public function test_auth_on_a_used_trait_gates_the_controller(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        $cases = [
            '/trait-gate/index' => [true, 'a class-level #[Auth] on a used trait'],
            '/trait-gate/shared' => [true, 'an action the guarded trait itself provides'],
            '/nested-trait/index' => [true, 'a guarded trait pulled in by another trait'],
            '/trait-method/secret' => [true, 'a method-level #[Auth] on a trait method'],
            '/trait-method/open' => [false, 'an action gated nowhere'],
        ];
        foreach ($cases as $path => [$gated, $label]) {
            $m = $router->match(new Request('GET', $path, [], [], []));
            $this->assertNotNull($m, $path);
            $this->assertSame($gated, $m->requiresAuth, "{$label} ({$path})");
        }
    }

    /** #[Auth] on the controller class gates every action in it. */
    public function test_class_level_auth_attribute_gates_every_action(): void
    {
        $router = new Router(namespace: 'Kip\\Tests\\Routing\\Unimported\\');

        $m = $router->match(new Request('GET', '/vault/index', [], [], []));
        $this->assertNotNull($m);
        $this->assertTrue($m->requiresAuth, 'a class-level #[Auth] must gate its actions');
    }
}
