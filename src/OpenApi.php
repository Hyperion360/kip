<?php // src/OpenApi.php

declare(strict_types=1);
namespace Kip;

/**
 * An OpenAPI 3.1.0 document generated from the Router's own conventions:
 * controller URL segments, lowercased action names, verb attributes, and the
 * #[Auth] and #[Json] markers. Discovery loads ONLY files that match the
 * router's controller shapes (top-level <Name>Controller.php in plain
 * sources, <Name>/<Name>Controller.php at depth one in feature sources);
 * views, migrations and tests sharing those trees are never loaded. The
 * hierarchy walks below deliberately duplicate Router's private ones: the
 * generator must read the same semantics without widening the Router diff
 * (two later batteries rebase onto the same file).
 */
final class OpenApi
{
    private const VERB_ORDER = ['get', 'post', 'put', 'delete'];

    /**
     * @param array<string, string> $sources        namespace (trailing backslash) => directory; tried in order, the first source claiming a URL segment wins
     * @param array<string, string> $featureSources feature trees, scanned after every plain source (the Router's own order)
     */
    public function __construct(
        private array $sources,
        private string $title,
        private array $featureSources = [],
    ) {}

    /** @return array<string, mixed> the OpenAPI 3.1.0 document as a plain array */
    public function generate(): array
    {
        $paths = [];
        foreach ($this->discover() as [$class, $short, $segment]) {
            $ref = new \ReflectionClass($class);
            $declarers = self::declarers($ref);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                $action = $m->name;
                if (str_starts_with($action, '__')) continue;
                $required = [];
                foreach ($m->getParameters() as $p) {
                    if (!$p->isOptional()) $required[] = $p->getName();
                }
                if ($action === 'index') {
                    // The bare controller path is index()'s only spelling
                    // ('/<controller>/index/...' 404s), so a required parameter
                    // makes the action unroutable and it is omitted entirely.
                    if ($required !== []) continue;
                    $path = $segment === 'home' ? '/' : '/' . $segment;
                } else {
                    // The router matches action segments case-insensitively on the
                    // literal URL text; lowercase is the canonical spelling and
                    // underscores stay ('doWork' serves at '/<ctl>/dowork').
                    $path = '/' . $segment . '/' . strtolower($action);
                    foreach ($required as $r) $path .= '/{' . $r . '}';
                }
                $auth = self::hasAttributeIn($declarers, $action, 'Auth');
                $isJson = self::hasAttributeIn($declarers, $action, 'Json');
                $media = $isJson ? 'application/json' : 'text/html';
                $type = $isJson ? 'object' : 'string';
                foreach (self::verbsIn($declarers, $action) ?: ['GET'] as $verb) {
                    $op = ['operationId' => "{$short}.{$action}", 'tags' => [$short]];
                    if ($auth) $op['x-kip-auth'] = true;
                    if ($required !== []) {
                        $op['parameters'] = array_map(
                            static fn(string $r): array => ['name' => $r, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            $required
                        );
                    }
                    $op['responses'] = ['200' => ['description' => 'OK', 'content' => [$media => ['schema' => ['type' => $type]]]]];
                    $paths[$path][strtolower($verb)] = $op;
                }
            }
        }
        foreach ($paths as $path => $item) {
            $ordered = [];
            foreach (self::VERB_ORDER as $verb) if (isset($item[$verb])) $ordered[$verb] = $item[$verb];
            $paths[$path] = $ordered;
        }
        ksort($paths);
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $this->title, 'version' => '0.1.0'],
            'paths' => $paths,
        ];
    }

    /**
     * Every routable controller across all sources, in precedence order.
     *
     * @return list<array{0: class-string, 1: string, 2: string}> [class, short name, URL segment]
     */
    private function discover(): array
    {
        $found = [];
        $claimed = [];
        foreach ([[false, $this->sources], [true, $this->featureSources]] as [$feature, $sources]) {
            foreach ($sources as $ns => $dir) {
                foreach ($this->candidates((string) $dir, $feature) as $base => $file) {
                    $segment = self::segmentFor($base);
                    if ($segment === null || isset($claimed[$segment])) continue; // no canonical segment, or an earlier source claimed it
                    require_once $file; // the only files ever loaded, already shape- and name-filtered
                    $class = ($feature ? $ns . $base . '\\' : $ns) . $base . 'Controller';
                    if (!class_exists($class)) continue;
                    $ref = new \ReflectionClass($class);
                    if ($ref->getName() !== $class || $ref->isAbstract() || $ref->isInterface() || $ref->isEnum()) continue;
                    $claimed[$segment] = true;
                    $found[] = [$class, $base, $segment];
                }
            }
        }
        return $found;
    }

    /**
     * The controller-shaped files of one source. Plain sources read top-level
     * <Name>Controller.php files only; feature sources read
     * <Name>/<Name>Controller.php at depth one only (the Router's shape).
     * Everything else on disk is filtered out BEFORE any require, so a nested
     * migration, view or test file can never execute.
     *
     * @return array<string, string> basename (minus Controller) => file path
     */
    private function candidates(string $dir, bool $feature): array
    {
        error_clear_last();
        $entries = @scandir($dir);
        if ($entries === false) {
            $why = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Cannot list {$dir}: {$why}. Refusing to emit a partial controller inventory as a complete document.");
        }
        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if ($feature) {
                $file = $dir . '/' . $entry . '/' . $entry . 'Controller.php';
                if (is_file($file)) $found[$entry] = $file; // is_file implies $entry is a directory; wrong-shape names never match
            } elseif (is_file($dir . '/' . $entry) && preg_match('/^([A-Za-z][A-Za-z0-9]*)Controller\.php$/', $entry, $m) === 1) {
                $found[$m[1]] = $dir . '/' . $entry;
            }
        }
        ksort($found);
        return $found;
    }

    /**
     * The URL segment the Router needs for this controller basename: the
     * lowercase letter-split form that studly() inverts exactly ('BlogPosts'
     * -> 'blog-posts', 'API' -> 'a-p-i', which is an acronym's only canonical
     * segment: '/api' studlies to Api). Null when nothing round-trips: the
     * class is unreachable by convention and its file stays unloaded.
     */
    private static function segmentFor(string $base): ?string
    {
        $words = array_values(array_filter(
            preg_split('/(?=[A-Z])/', $base) ?: [],
            static fn(string $w): bool => $w !== ''
        ));
        if ($words === []) return null;
        $segment = strtolower(implode('-', $words));
        return \Kip\Routing\Router::studly($segment) === $base ? $segment : null;
    }

    // The hierarchy walks below mirror Router's private ones by design (see the
    // class docblock): attributes match by short name, case-insensitively, and
    // are read from the whole hierarchy (parents, traits, interfaces).

    /**
     * The class and every parent, each followed by its traits (nearest first),
     * then every interface.
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
            foreach ($d->getAttributes() as $attr) {
                if (strcasecmp(self::shortName($attr->getName()), $short) === 0) return true;
            }
            if ($d->hasMethod($action)) {
                foreach ($d->getMethod($action)->getAttributes() as $attr) {
                    if (strcasecmp(self::shortName($attr->getName()), $short) === 0) return true;
                }
            }
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

    /** The name after the last backslash, or the whole name for a global one such as #[\Auth]. */
    private static function shortName(string $name): string
    {
        $slash = strrpos($name, '\\');
        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
