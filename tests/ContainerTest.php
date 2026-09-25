<?php // tests/ContainerTest.php
namespace Kip\Tests;
use Kip\Container;
use PHPUnit\Framework\TestCase;

class ContainerDep {}
class ContainerHost { public function __construct(public ContainerDep $dep) {} }

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
