<?php // src/Container.php
namespace Kip;

final class Container
{
    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var list<class-string> classes mid-construction, outermost first */
    private array $resolving = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param T               $obj
     */
    public function instance(string $class, object $obj): void
    {
        $this->instances[$class] = $obj;
    }

    /**
     * Autowire $class, reusing any instance() binding. Typed seam: callers get
     * back the class they asked for, not a bare object.
     *
     * @template T of object
     * @param  class-string<T> $class
     * @return T
     */
    public function make(string $class): object
    {
        /** @var T */
        return $this->resolve($class);
    }

    /**
     * Untyped recursion behind make(): reflection yields plain class-name
     * strings, which carry no template information.
     *
     * @param class-string $class
     */
    private function resolve(string $class): object
    {
        if (isset($this->instances[$class])) return $this->instances[$class];
        if (in_array($class, $this->resolving, true)) {
            $chain = [...array_slice($this->resolving, (int) array_search($class, $this->resolving, true)), $class];
            throw new \RuntimeException(
                'Circular dependency: ' . implode(' -> ', $chain) . '. Each constructor in '
                . 'the loop needs the next one built first, so none can be. Remove one of '
                . 'these constructor parameters to break the loop.'
            );
        }
        $this->resolving[] = $class;
        try {
            return $this->build($class);
        } finally {
            array_pop($this->resolving);
        }
    }

    /** @param class-string $class */
    private function build(string $class): object
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        $args = [];
        foreach ($ctor?->getParameters() ?? [] as $p) {
            $t = $p->getType();
            if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
                /** @var class-string $dep */
                $dep = $t->getName();
                $args[] = $this->resolve($dep);
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } else {
                throw new \RuntimeException(
                    "Cannot autowire {$class}::\${$p->getName()}: its type is not a single "
                    . 'class (a builtin, union or intersection type, or none) and it has no '
                    . 'default value, so the container cannot choose what to pass. Give the '
                    . "parameter a default, or bind the instance first "
                    . "with \$container->instance({$class}::class, \$obj)."
                );
            }
        }
        return new $class(...$args);
    }
}
