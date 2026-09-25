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
}
