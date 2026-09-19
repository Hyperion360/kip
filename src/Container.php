<?php // src/Container.php
namespace Kip;

final class Container
{
    /** @var array<class-string, object> */
    private array $instances = [];

    public function instance(string $class, object $obj): void
    {
        $this->instances[$class] = $obj;
    }

    public function make(string $class): object
    {
        if (isset($this->instances[$class])) return $this->instances[$class];
        $ctor = (new \ReflectionClass($class))->getConstructor();
        $args = [];
        foreach ($ctor?->getParameters() ?? [] as $p) {
            $t = $p->getType();
            if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
                $args[] = $this->make($t->getName());
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } else {
                throw new \RuntimeException("Cannot autowire {$class}::\${$p->getName()}");
            }
        }
        return new $class(...$args);
    }
}
