<?php // src/Routing/Router.php
namespace Kip\Routing;

use Kip\Http\Request;

final class Router
{
    /** @param string|list<string> $namespace one prefix, or several tried in order */
    public function __construct(
        private string|array $namespace = 'App\\Controllers\\',
        private string $suffix = 'Controller',
    ) {}

    public function match(Request $request): ?RouteMatch
    {
        // Lowercase-only (no i-flag), no consecutive slashes: /POSTS/SHOW/1 and /posts//show/1
        // are both 404, one canonical URL per page (reviews 9A, D15)
        if (!preg_match('#^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$#', $request->path)) return null; // whitelist, threat model
        // Filter empty strings ONLY. A bare array_filter would eat the legal segment "0" (review D15)
        $parts = array_values(array_filter(explode('/', $request->path), static fn(string $p): bool => $p !== ''));
        $name   = $parts[0] ?? 'home';
        $action = $parts[1] ?? 'index';
        $args   = array_slice($parts, 2);

        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $class = null;
        foreach ((array) $this->namespace as $ns) {   // first listed namespace shadows later ones
            if (class_exists($ns . $studly . $this->suffix)) { $class = $ns . $studly . $this->suffix; break; }
        }
        if ($class === null) return null;
        if (!method_exists($class, $action)) return null;

        $method = new \ReflectionMethod($class, $action);
        if (!$method->isPublic() || str_starts_with($action, '__')) return null;
        if (count($args) > $method->getNumberOfParameters()) return null;
        if (count($args) < $method->getNumberOfRequiredParameters()) return null;

        // HEAD is served by GET handlers (RFC 9110 §9.3.2); the kernel strips the body.
        $requestMethod = $request->method === 'HEAD' ? 'GET' : $request->method;
        // Verb attributes: no verb attribute → GET-only by convention; #[Post] etc. restrict explicitly.
        // Attributes match by short name and case-insensitively, the way PHP resolves class
        // names, so they work whether or not their class was imported and in any letter case.
        // That matters most for #[Auth]: an unimported #[Auth] resolves to the controller's
        // own namespace, and an exact match on Kip\Routing\Auth would leave the route
        // silently public. PHP copies attributes across neither `extends` nor
        // `implements`, so #[Auth] counts wherever it is declared: on the controller
        // class, a parent class or an interface (gating every action), or on any
        // declaration of this action in that hierarchy, so an override that drops the
        // attribute cannot make a gated action public. Authorization fails closed.
        $attributes = $method->getAttributes();
        $requiresAuth = self::hierarchyHasAuth(new \ReflectionClass($class), $action);
        $verbs = [];
        foreach ($attributes as $attr) {
            $verb = strtoupper(self::shortName($attr->getName()));
            if (in_array($verb, ['GET', 'POST', 'PUT', 'DELETE'], true)) $verbs[] = $verb;
        }
        $allowed = $verbs ?: ['GET'];
        if (!in_array($requestMethod, $allowed, true)) {
            // The route exists but the verb is wrong. That's a 405, not a 404 (review 9A)
            throw new MethodNotAllowedException(implode(', ', $allowed));
        }

        return new RouteMatch($class, $action, $args, $requiresAuth);
    }

    /**
     * True when #[Auth] sits on the class, any parent or interface, or on any of
     * their declarations of $action. getInterfaces() already includes interfaces
     * inherited from parents and from other interfaces.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function hierarchyHasAuth(\ReflectionClass $class, string $action): bool
    {
        $declarers = array_values($class->getInterfaces());
        for ($c = $class; $c !== false; $c = $c->getParentClass()) {
            $declarers[] = $c;
        }
        foreach ($declarers as $d) {
            if (self::hasAuth($d->getAttributes())) return true;
            if ($d->hasMethod($action) && self::hasAuth($d->getMethod($action)->getAttributes())) return true;
        }
        return false;
    }

    /** @param array<\ReflectionAttribute<object>> $attributes */
    private static function hasAuth(array $attributes): bool
    {
        foreach ($attributes as $attr) {
            if (strcasecmp(self::shortName($attr->getName()), 'Auth') === 0) return true;
        }
        return false;
    }

    /** The name after the last backslash, or the whole name for a global one such as #[\Auth]. */
    private static function shortName(string $name): string
    {
        $slash = strrpos($name, '\\');
        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
