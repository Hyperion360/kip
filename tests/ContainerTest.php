<?php // tests/ContainerTest.php
namespace Kip\Tests;
use Kip\Container;
use PHPUnit\Framework\TestCase;

class ContainerDep {}
class ContainerHost { public function __construct(public ContainerDep $dep) {} }
class ContainerCycleA { public function __construct(public ContainerCycleB $b) {} }
class ContainerCycleB { public function __construct(public ContainerCycleA $a) {} }
class ContainerSelf { public function __construct(public ContainerSelf $self) {} }
class ContainerDiamond { public function __construct(public ContainerHost $left, public ContainerDep $right) {} }

final class ContainerTest extends TestCase
{
    public function test_autowires_constructor_dependencies(): void
    {
        $c = new Container();
        $host = $c->make(ContainerHost::class);
        $this->assertInstanceOf(ContainerDep::class, $host->dep);
    }

    public function test_registered_instances_are_shared(): void
    {
        $c = new Container();
        $dep = new ContainerDep();
        $c->instance(ContainerDep::class, $dep);
        $this->assertSame($dep, $c->make(ContainerHost::class)->dep);
    }

    public function test_circular_dependency_throws_naming_the_chain(): void
    {
        $c = new Container();
        try {
            $c->make(ContainerCycleA::class);
            $this->fail('a cycle must throw, not recurse until PHP runs out of memory');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'Circular dependency: Kip\\Tests\\ContainerCycleA -> Kip\\Tests\\ContainerCycleB -> Kip\\Tests\\ContainerCycleA',
                $e->getMessage()
            );
        }
        // The failed resolve leaves nothing behind: the same container still works.
        $this->assertInstanceOf(ContainerDep::class, $c->make(ContainerHost::class)->dep);
    }

    public function test_a_class_depending_on_itself_is_a_cycle(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency: Kip\\Tests\\ContainerSelf -> Kip\\Tests\\ContainerSelf');
        (new Container())->make(ContainerSelf::class);
    }

    public function test_a_dependency_reached_twice_is_not_a_cycle(): void
    {
        $diamond = (new Container())->make(ContainerDiamond::class); // ContainerDep via two paths
        $this->assertInstanceOf(ContainerDep::class, $diamond->left->dep);
        $this->assertInstanceOf(ContainerDep::class, $diamond->right);
    }

    public function test_unautowirable_parameter_throws_named_error(): void // review 4A
    {
        $c = new Container();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('$label');
        $c->make(NeedsScalar::class);
    }

    /**
     * make() must still autowire recursively after being made generic: the
     * public seam carries the template type, the private recursion does not.
     */
    public function test_make_autowires_nested_dependencies(): void
    {
        $c = new \Kip\Container();
        $outer = $c->make(ContainerOuterFixture::class);

        $this->assertInstanceOf(ContainerOuterFixture::class, $outer);
        $this->assertInstanceOf(ContainerInnerFixture::class, $outer->inner);
    }

    /** An instance() binding must still short-circuit autowiring. */
    public function test_instance_binding_wins_over_autowiring(): void
    {
        $c = new \Kip\Container();
        $bound = new ContainerInnerFixture();
        $c->instance(ContainerInnerFixture::class, $bound);

        $outer = $c->make(ContainerOuterFixture::class);
        $this->assertSame($bound, $outer->inner, 'the bound instance must be reused, not rebuilt');
    }
}

class NeedsScalar { public function __construct(public string $label) {} }

final class ContainerInnerFixture
{
}

final class ContainerOuterFixture
{
    public function __construct(public ContainerInnerFixture $inner) {}
}
