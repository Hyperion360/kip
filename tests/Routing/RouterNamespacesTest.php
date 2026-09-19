<?php // tests/Routing/RouterNamespacesTest.php
namespace Kip\Tests\Routing;
use Kip\Http\Request;
use Kip\Routing\Router;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

final class RouterNamespacesTest extends TestCase
{
    public function test_string_namespace_still_works(): void
    {
        $r = new Router('Kip\\Tests\\Routing\\');
        $m = $r->match(new Request('GET', '/posts', [], [], []));
        $this->assertSame('Kip\\Tests\\Routing\\PostsController', $m->class);
    }

    public function test_falls_through_to_second_namespace(): void
    {
        $r = new Router(['App\\NoSuch\\', 'Kip\\Tests\\Routing\\']);
        $m = $r->match(new Request('GET', '/posts', [], [], []));
        $this->assertSame('Kip\\Tests\\Routing\\PostsController', $m->class);
    }

    public function test_first_namespace_wins_when_both_match(): void
    {
        // Both namespaces ship a PostsController, first listed shadows.
        $r = new Router(['Kip\\Tests\\Fixtures\\Controllers\\', 'Kip\\Tests\\Routing\\']);
        $m = $r->match(new Request('GET', '/posts', [], [], []));
        $this->assertSame('Kip\\Tests\\Fixtures\\Controllers\\PostsController', $m->class);
    }

    public function test_no_namespace_matches_is_404(): void
    {
        $r = new Router(['App\\NoSuch\\', 'Also\\Missing\\']);
        $this->assertNull($r->match(new Request('GET', '/posts', [], [], [])));
    }
}
