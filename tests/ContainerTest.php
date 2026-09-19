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
}

class NeedsScalar { public function __construct(public string $label) {} }
