<?php // src/Routing/RouteMatch.php  (review 2A: own file, PSR-4 clean; review 1A: invoke takes the request scope)
namespace Kip\Routing;

use Kip\Container;

final class RouteMatch
{
    /** @param class-string $class */
    public function __construct(
        public readonly string $class,
        private string $action,
        private array $args,
        public readonly bool $requiresAuth,
    ) {}

    public function invoke(Container $scope): mixed
    {
        return $scope->make($this->class)->{$this->action}(...$this->args);
    }
}
