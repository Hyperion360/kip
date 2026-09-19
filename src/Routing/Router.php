<?php // src/Routing/Router.php
namespace Kip\Routing;

use Kip\Http\Request;

final class Router
{
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
        $verbs = [];
        foreach ($method->getAttributes() as $attr) {
            $short = substr($attr->getName(), strrpos($attr->getName(), '\\') + 1);
            if (in_array($short, ['Get', 'Post', 'Put', 'Delete'], true)) $verbs[] = strtoupper($short);
        }
        $allowed = $verbs ?: ['GET'];
        if (!in_array($requestMethod, $allowed, true)) {
            // The route exists but the verb is wrong. That's a 405, not a 404 (review 9A)
            throw new MethodNotAllowedException(implode(', ', $allowed));
        }

        $requiresAuth = $method->getAttributes(Auth::class) !== [];
        return new RouteMatch($class, $action, $args, $requiresAuth);
    }
}
