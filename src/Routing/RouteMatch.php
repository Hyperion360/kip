<?php // src/Routing/RouteMatch.php  (review 2A: own file, PSR-4 clean; review 1A: invoke takes the request scope)
namespace Kip\Routing;

// No strict_types declaration in this file, deliberately. invoke() below is
// the one place Kip calls application code with scalars it parsed from the
// URL, and path segments are always strings. Under strict types an app
// action declaring `int $id` would raise a TypeError instead of receiving
// a coerced int, which would break every route with a non-string
// parameter. Tightening this needs a deprecation cycle, not an edit.

use Kip\Container;

final class RouteMatch
{
    /**
     * @param class-string $class controller the router resolved
     * @param list<string> $args  path segments passed to the action
     * @param ?string $policy policy name from #[Auth(policy: ...)], evaluated by App after login
     */
    public function __construct(
        public readonly string $class,
        private string $action,
        private array $args,
        public readonly bool $requiresAuth,
        public readonly bool $json = false,
        public readonly ?string $policy = null,
    ) {}

    public function invoke(Container $scope): mixed
    {
        return $scope->make($this->class)->{$this->action}(...$this->args);
    }
}
