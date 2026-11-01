<?php // src/Routing/Router.php

declare(strict_types=1);
namespace Kip\Routing;

use Kip\Http\Request;

final class Router
{
    /**
     * The one first-segment grammar a URL may carry: lowercase letters and
     * digits, single - or _ separators. match() whitelists controller names
     * with it, and the rate limiter's '*' fallback gate reuses the same
     * constant so the two can never drift (a spelling one of them admits
     * but the other refuses would sit outside every throttle or inside a
     * phony bucket).
     */
    public const NAME = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D';

    /**
     * The one full-path grammar: '/' alone, or '/'-separated segments each
     * drawn from NAME's character set (lowercase letters, digits, - and _).
     * The naming GRAMMAR itself binds the first segment only; deeper segments
     * become route arguments and only their characters are constrained here.
     * No consecutive slashes, no trailing slash: one canonical URL per page.
     * match() 404s everything else, and the rate limiter's '*' fallback gate
     * reuses the same constant, so junk the router refuses ('//', '/a//b',
     * '/x/') costs nothing there either: firstSegmentOf() collapses those
     * spellings' slashes, so a gate on the first segment alone would charge
     * them into real buckets. Both anchors carry the D modifier: a bare $
     * also matches before a trailing raw newline, which would admit a
     * spelling like '/posts\n' that no browser sends but a hand-built
     * Request could carry.
     */
    public const PATH = '#^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$#D';

    /**
     * URL segment → Studly word ('my-billing'/'my_billing' → 'MyBilling'). THE one
     * implementation of the separator rule: the View studly-cases feature template
     * segments with it too, so a controller and its template folder can never resolve
     * under two different spellings.
     */
    public static function studly(string $segment): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $segment)));
    }

    /**
     * @param string|list<string> $namespace one prefix, or several tried in order
     * @param ?string $featureNamespace feature-folder prefix (e.g. App\Features\), or null to disable the feature form
     */
    public function __construct(
        private string|array $namespace = 'App\\Controllers\\',
        private string $suffix = 'Controller',
        private ?string $featureNamespace = null,
    ) {}

    /**
     * Explicit routes, checked in registration order BEFORE convention matching.
     *
     * @var list<array{segments: list<array{string, bool}>, class: class-string, action: string}>
     */
    private array $routes = [];

    /**
     * Register an explicit pattern route. The pattern is '/'-separated segments:
     * a literal (lowercase letters, digits, and single '-', '_' or '.' separators,
     * so 'sitemap.xml' and 'rss.xml' are legal) or a parameter written <name>,
     * which matches one convention-grammar segment (Router::NAME) and is passed
     * to the action as a positional string argument, left to right. The first
     * registered route that matches wins, so list specific patterns before
     * broader ones ('/sitemap.xml' before '/<slug>'). Verb attributes, #[Auth],
     * #[Json] and #[CrossSite] on the target action are honored exactly as on a
     * convention route. A path no pattern matches falls through to convention
     * matching untouched.
     *
     * @param class-string $controller
     */
    public function route(string $pattern, string $controller, string $action = 'index'): void
    {
        if ($pattern === '/') {
            $segments = [];
        } else {
            if (!str_starts_with($pattern, '/')) {
                throw new \InvalidArgumentException("Route pattern \"{$pattern}\" must start with '/'");
            }
            $segments = [];
            $names = [];
            foreach (explode('/', substr($pattern, 1)) as $part) {
                if (preg_match('/^<([a-z_][a-z0-9_]*)>$/D', $part, $m) === 1) {
                    if (in_array($m[1], $names, true)) {
                        throw new \InvalidArgumentException("Route pattern \"{$pattern}\" repeats the parameter <{$m[1]}>");
                    }
                    $names[] = $m[1];
                    $segments[] = ['', true];
                } elseif (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $part) === 1) {
                    $segments[] = [$part, false];
                } else {
                    throw new \InvalidArgumentException("Route pattern \"{$pattern}\" has an invalid segment \"{$part}\": use lowercase letters, digits, single '-', '_' or '.' separators, or <name>");
                }
            }
        }
        if (!class_exists($controller) || (new \ReflectionClass($controller))->getName() !== $controller) {
            throw new \InvalidArgumentException("Route \"{$pattern}\" names controller {$controller}, which does not exist");
        }
        if (!method_exists($controller, $action)) {
            throw new \InvalidArgumentException("Route \"{$pattern}\" names {$controller}::{$action}, which does not exist");
        }
        $method = new \ReflectionMethod($controller, $action);
        if (!$method->isPublic() || str_starts_with($action, '__')) {
            throw new \InvalidArgumentException("Route \"{$pattern}\" names {$controller}::{$action}, which is not a public action");
        }
        $params = count(array_filter($segments, static fn(array $s): bool => $s[1]));
        if ($params > $method->getNumberOfParameters() || $params < $method->getNumberOfRequiredParameters()) {
            throw new \InvalidArgumentException("Route \"{$pattern}\" captures {$params} parameter(s) but {$controller}::{$action} takes {$method->getNumberOfRequiredParameters()} to {$method->getNumberOfParameters()}");
        }
        $this->routes[] = ['segments' => $segments, 'class' => $controller, 'action' => $action];
    }

    /**
     * True when a registered pattern matches the path (any verb). The rate
     * limiter asks this so a dotted literal path, which the convention grammar
     * (PATH) refuses, is still counted under its own bucket instead of
     * slipping past the '*' fallback.
     */
    public function hasRouteFor(string $path): bool
    {
        return $this->matchRoute($path) !== null;
    }

    /** @return ?array{route: array{segments: list<array{string, bool}>, class: class-string, action: string}, args: list<string>} */
    private function matchRoute(string $path): ?array
    {
        if ($this->routes === [] || $path === '' || $path[0] !== '/') return null;
        // Empty segments mean '//' or a trailing slash: one canonical URL per page, as in convention.
        $parts = $path === '/' ? [] : explode('/', substr($path, 1));
        foreach ($this->routes as $route) {
            if (count($route['segments']) !== count($parts)) continue;
            $args = [];
            foreach ($route['segments'] as $i => [$literal, $isParam]) {
                if ($isParam) {
                    if (preg_match(self::NAME, $parts[$i]) !== 1) continue 2;
                    $args[] = $parts[$i];
                } elseif ($parts[$i] !== $literal) {
                    continue 2;
                }
            }
            return ['route' => $route, 'args' => $args];
        }
        return null;
    }

    public function match(Request $request): ?RouteMatch
    {
        $explicit = $this->matchRoute($request->path);
        if ($explicit !== null) {
            return $this->resolve($request, $explicit['route']['class'], $explicit['route']['action'], $explicit['args']);
        }
        // Lowercase-only (no i-flag), no consecutive slashes, no trailing slash:
        // /POSTS/SHOW/1, /posts//show/1 and /posts/1/ are all 404, one canonical
        // URL per page (reviews 9A, D15). Request stores the path as given, so a
        // trailing slash reaches this whitelist instead of being trimmed away.
        if (!preg_match(self::PATH, $request->path)) return null; // whitelist, threat model
        // Filter empty strings ONLY. A bare array_filter would eat the legal segment "0" (review D15)
        $parts = array_values(array_filter(explode('/', $request->path), static fn(string $p): bool => $p !== ''));
        $name   = $parts[0] ?? 'home';
        $action = $parts[1] ?? 'index';
        $args   = array_slice($parts, 2);
        // One canonical URL per page: '/' is the home controller's only spelling
        // (not '/home'), and an index action is reached at the bare controller
        // path (not '/posts/index'). Both aliases 404 (fuzz + qa 2026-09-26).
        if ($parts === ['home']) return null;
        if (isset($parts[1]) && $parts[1] === 'index') return null;
        // A separator only joins words: /posts-, /_posts and /po--sts would otherwise all
        // studly-case to PostsController, giving one page several URLs.
        if (!preg_match(self::NAME, $name)) return null;

        $studly = self::studly($name);
        $class = null;
        foreach ((array) $this->namespace as $ns) {   // first listed namespace shadows later ones
            $candidate = $ns . $studly . $this->suffix;
            // class_exists() ignores case, so /po-sts (PoStsController) would find
            // PostsController: require the declared name to match exactly.
            if (class_exists($candidate) && (new \ReflectionClass($candidate))->getName() === $candidate) { $class = $candidate; break; }
        }
        // Feature folders come AFTER every plain namespace (review P2-1): a layered app
        // adopting folders gradually keeps code and templates resolving app-root-first,
        // so the two can never flip independently. A same-named plain controller wins;
        // apps must not keep both, the docs say so.
        if ($class === null && $this->featureNamespace !== null) {
            $candidate = $this->featureNamespace . $studly . '\\' . $studly . $this->suffix;
            if (class_exists($candidate) && (new \ReflectionClass($candidate))->getName() === $candidate) $class = $candidate;
        }
        if ($class === null) return null;
        if (!method_exists($class, $action)) return null;

        return $this->resolve($request, $class, $action, $args);
    }

    /**
     * The one place a resolved controller, action and argument list becomes a
     * RouteMatch: arity checks, then the verb, #[Auth], #[Json], #[CrossSite]
     * and policy reading. Convention and explicit routes both end here, so
     * neither can drift from the other's security semantics.
     *
     * @param class-string $class
     * @param list<string> $args
     */
    private function resolve(Request $request, string $class, string $action, array $args): ?RouteMatch
    {
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
        // silently public. PHP carries attributes across none of `extends`,
        // `implements` or `use`, so both are read from the whole hierarchy. #[Auth]
        // counts wherever it is declared: on the controller class, a parent, an
        // interface or a trait (gating every action), or on any declaration of this
        // action, so an override that drops it cannot make a gated action public.
        // Verbs come from the nearest declaration of this action that names any, so
        // an override that drops a parent's #[Post] stays POST-only instead of
        // becoming a GET that skips the CSRF check. Fails closed.
        $declarers = self::declarers(new \ReflectionClass($class));
        $requiresAuth = self::hasAttributeIn($declarers, $action, 'Auth');
        $isJson = self::hasAttributeIn($declarers, $action, 'Json');
        $crossSite = self::hasAttributeIn($declarers, $action, 'CrossSite');
        $allowed = self::verbsIn($declarers, $action) ?: ['GET'];
        if (!in_array($requestMethod, $allowed, true)) {
            // The route exists but the verb is wrong. That's a 405, not a 404 (review 9A)
            throw new MethodNotAllowedException(implode(', ', $allowed), $requiresAuth);
        }

        $policy = self::authPolicyIn($declarers, $action);
        return new RouteMatch($class, $action, $args, $requiresAuth, $isJson, $policy, $crossSite);
    }

    /**
     * The policy named by the nearest #[Auth] declaring one (the action's own
     * declaration first), null when every #[Auth] is bare. Arguments are read
     * WITHOUT instantiation (the short-name match may hit an app-local Auth
     * class), and arguments that name no policy fail loud: a typo must break
     * the route, not weaken it to login-only.
     *
     * @param list<\ReflectionClass<object>> $declarers
     */
    private static function authPolicyIn(array $declarers, string $action): ?string
    {
        foreach ($declarers as $d) {
            $sources = [];
            if ($d->hasMethod($action)) {
                // Only the declarer's OWN method declaration counts here:
                // getMethod() also returns inherited methods, and reading the
                // parent's method attributes through the child would let a
                // distant method policy outrank the child's nearer class
                // policy (closing-pass finding: an inherited member policy
                // beat the child's admin policy and the route returned 200).
                $m = $d->getMethod($action);
                if ($m->getDeclaringClass()->getName() === $d->getName()) {
                    $sources[] = $m;
                }
            }
            $sources[] = $d;
            foreach ($sources as $source) {
                foreach ($source->getAttributes() as $attr) {
                    if (strcasecmp(self::shortName($attr->getName()), 'Auth') !== 0) continue;
                    if (($policy = self::authPolicyOf($attr)) !== null) return $policy;
                }
            }
        }
        return null;
    }

    /**
     * Bare = no arguments; one non-empty string (named policy: or positional) = that policy; anything else fails loud.
     *
     * @param \ReflectionAttribute<object> $attr
     */
    private static function authPolicyOf(\ReflectionAttribute $attr): ?string
    {
        try {
            $args = $attr->getArguments();
        } catch (\Throwable $e) {
            throw new \LogicException("{$attr->getName()} arguments do not evaluate: {$e->getMessage()}", 0, $e);
        }
        if ($args === []) return null;
        $policy = $args['policy'] ?? $args[0] ?? null;
        if (count($args) === 1 && is_string($policy) && $policy !== '') return $policy;
        throw new \LogicException($attr->getName() . " must be bare or carry exactly one policy string, e.g. #[Auth(policy: 'can-edit')]");
    }

    /**
     * The class and every parent, each followed by its traits (nearest first), then
     * every interface. getInterfaces() already includes interfaces inherited from
     * parents and from other interfaces.
     *
     * @param \ReflectionClass<object> $class
     * @return list<\ReflectionClass<object>>
     */
    private static function declarers(\ReflectionClass $class): array
    {
        $declarers = [];
        for ($c = $class; $c !== false; $c = $c->getParentClass()) {
            $declarers[] = $c;
            array_push($declarers, ...self::traitsOf($c));
        }
        return [...$declarers, ...array_values($class->getInterfaces())];
    }

    /**
     * True when #[<short>] (Auth, Json) sits on any declarer, or on any declarer's $action.
     *
     * @param list<\ReflectionClass<object>> $declarers
     */
    private static function hasAttributeIn(array $declarers, string $action, string $short): bool
    {
        foreach ($declarers as $d) {
            if (self::hasAttribute($d->getAttributes(), $short)) return true;
            if ($d->hasMethod($action) && self::hasAttribute($d->getMethod($action)->getAttributes(), $short)) return true;
        }
        return false;
    }

    /**
     * The verbs on the nearest declaration of $action that names any; empty when none does.
     *
     * @param list<\ReflectionClass<object>> $declarers
     * @return list<string>
     */
    private static function verbsIn(array $declarers, string $action): array
    {
        foreach ($declarers as $d) {
            if (!$d->hasMethod($action)) continue;
            $verbs = [];
            foreach ($d->getMethod($action)->getAttributes() as $attr) {
                $verb = strtoupper(self::shortName($attr->getName()));
                if (in_array($verb, ['GET', 'POST', 'PUT', 'DELETE'], true)) $verbs[] = $verb;
            }
            if ($verbs !== []) return $verbs;
        }
        return [];
    }

    /**
     * Every trait $class uses, directly or through other traits.
     *
     * @param \ReflectionClass<object> $class
     * @return list<\ReflectionClass<object>>
     */
    private static function traitsOf(\ReflectionClass $class): array
    {
        $traits = [];
        foreach ($class->getTraits() as $trait) {
            $traits[] = $trait;
            array_push($traits, ...self::traitsOf($trait));
        }
        return $traits;
    }

    /** @param array<\ReflectionAttribute<object>> $attributes */
    private static function hasAttribute(array $attributes, string $short): bool
    {
        foreach ($attributes as $attr) {
            if (strcasecmp(self::shortName($attr->getName()), $short) === 0) return true;
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
