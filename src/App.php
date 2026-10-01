<?php // src/App.php

declare(strict_types=1);
namespace Kip;

use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Router;

final class App
{
    public readonly Container $container;
    public readonly Session $session;
    private Router $router;
    /** @var array<string, mixed> backing store for an eager session */
    private array $sessionStore = [];
    private ?RequestLog $requestLog = null;
    private ?\Kip\Cache\PageCache $pageCache = null;
    private ?RateLimit $rateLimit = null;
    /** @var list<callable(): void> work queued by defer(), run after the response is sent */
    private array $deferred = [];
    private bool $deferFallbackArmed = false;
    /** @var array<string, \Closure> named authorization policies, registered once per App via policy() */
    private array $policies = [];

    /** @param array<string, mixed> $config */
    public function __construct(private array $config, ?Session $session = null)
    {
        $this->container = new Container();
        $this->container->instance(self::class, $this);
        // Feature folders (ch. 3): one detection drives both View and Router: an
        // explicit features_dir config wins, else app_dir/Features when it exists,
        // else '' (layered app, nothing changes).
        $appDir = $config['app_dir'] ?? '.';
        $featuresDir = $config['features_dir'] ?? (is_dir($appDir . '/Features') ? $appDir . '/Features' : '');
        $this->container->instance(View::class, new View($config['views'] ?? $appDir . '/views', $featuresDir));
        if (isset($config['db']['dsn'])) {
            $this->container->instance(Database::class, new Database(
                $config['db']['dsn'],
                $config['db']['user'] ?? null,
                $config['db']['pass'] ?? null
            ));
        }
        // Rate limiting counts in the content database (its rate_limits table
        // ships as app migration 009), so the config without one is a boot
        // error, never a silent no-op.
        if (isset($config['rate_limit'])) {
            if (!isset($config['db']['dsn'])) {
                throw new \InvalidArgumentException('config key "rate_limit" needs the content database to count in: configure "db.dsn" or remove "rate_limit"');
            }
            $this->rateLimit = new RateLimit($this->container->make(Database::class), $config['rate_limit']);
        }
        if (isset($config['log_db']['dsn'])) {
            $this->requestLog = new RequestLog(new Database(
                $config['log_db']['dsn'],
                $config['log_db']['user'] ?? null,
                $config['log_db']['pass'] ?? null
            ), self::intConfig($config['log_db']['retention_days'] ?? 30, 'log_db.retention_days'));
            $this->container->instance(RequestLog::class, $this->requestLog); // tests read it from here
        }
        if (isset($config['cache_db']['dsn'])) {
            // OWN Database instance, NEVER container-resolved, or self-tagging re-materializes
            // (the cache's own writes would tag themselves as invalidation targets).
            $this->pageCache = new \Kip\Cache\PageCache(
                new Database(
                    $config['cache_db']['dsn'],
                    $config['cache_db']['user'] ?? null,
                    $config['cache_db']['pass'] ?? null
                ),
                self::intConfig($config['cache_db']['ttl_seconds'] ?? 3600, 'cache_db.ttl_seconds'),
                self::intConfig($config['cache_db']['max_pages'] ?? \Kip\Cache\PageCache::DEFAULT_MAX_PAGES, 'cache_db.max_pages')
            );
        }
        if (isset($config['uploads']['dir'])) {
            $this->container->instance(Storage::class, new Storage(
                $config['uploads']['dir'],
                self::intConfig($config['uploads']['max_bytes'] ?? Storage::DEFAULT_MAX_BYTES, 'uploads.max_bytes'),
                $config['uploads']['ext'] ?? null,
            ));
        }
        if (isset($config['mail'])) {
            $this->container->instance(Mailer::class, new Mailer($config['mail']));
        }
        $namespaces = [$config['controller_namespace'] ?? 'App\\Controllers\\'];
        if (($config['admin']['enabled'] ?? false) && isset($config['db']['dsn'])) {
            $namespaces[] = 'Kip\\Admin\\Controllers\\';   // app namespace first: an app can shadow /admin
        }
        // The feature form stays OFF until the Features directory exists (review P2-2):
        // a layered app resolves nothing from App\Features, so it behaves byte-identically.
        // array_key_exists, never ??, so an explicit feature_namespace => null keeps the
        // form off (review P1-2).
        $featureNamespace = $featuresDir !== '' && is_dir($featuresDir)
            ? (array_key_exists('feature_namespace', $config) ? $config['feature_namespace'] : 'App\\Features\\')
            : null;
        $this->router = new Router($namespaces, featureNamespace: $featureNamespace);
        $this->session = $session ?? new Session($this->sessionStore);
    }

    /**
     * Config integers accept ints and numeric strings ('30'); anything else is a
     * boot-time error naming the key, matching the behavior before the casts existed.
     */
    private static function intConfig(mixed $value, string $key): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }
        throw new \InvalidArgumentException(sprintf(
            'config key "%s" must be an int or a numeric string, got %s',
            $key,
            get_debug_type($value)
        ));
    }

    /**
     * The container's Database, or null when none is bound (or its construction
     * failed): caching, the transaction unwind, and auth validation all degrade
     * to no-ops without one rather than breaking the request path.
     */
    private function dbOrNull(): ?Database
    {
        try { return $this->container->make(Database::class); } catch (\Throwable) { return null; }
    }

    // review D13-A: every request is timed and audited to a separate logs.sqlite.
    // logging wraps process() so it covers error responses too.
    public function handle(Request $request, ?Session $session = null): Response
    {
        $active = $session ?? $this->session; // classic PHP: boot session ≡ this request's session
        // Worker-mode note: a persistent App MUST pass a per-request Session here, the
        // boot-session fallback is only correct when App is constructed per request (classic PHP).
        $start = hrtime(true);
        $response = $this->cachedProcess($request, $active);
        if ($request->method === 'HEAD') {
            $response = new Response('', $response->status, $response->headers); // headers/status kept, body stripped
        }
        // Audit only when a log DB was explicitly configured (T12c-fix, a container
        // lookup here would silently autowire against the main Database).
        // D3: reset tokens travel in the URL; never persist them in the audit log.
        // Token length comes from Auth so the redaction cannot drift from token generation.
        $auditPath = preg_replace('#^(/auth/reset/)[0-9a-f]{' . Auth::RESET_TOKEN_HEX . '}$#', '$1<redacted>', $request->path);
        // The session store is app-authored and may hold a numeric string where the
        // framework declares int; only integer-shaped strings audit as that user.
        // Anything else ('1.5', arrays) audits as a guest; sessionValid() still
        // fail-closes on bad data.
        $sessionUserId = $active->peek('user_id');
        $auditUserId = is_string($sessionUserId) && preg_match('/^\d+$/', $sessionUserId) === 1
            ? (int) $sessionUserId
            : (is_int($sessionUserId) ? $sessionUserId : null);
        $this->requestLog?->log($request, $response->status, $auditUserId, (hrtime(true) - $start) / 1e6, $auditPath);
        return $response;
    }

    /**
     * Queue work to run after the response has been sent, so its duration never shows
     * in the response time. Use it for side effects whose cost would reveal something,
     * such as mail that is only sent when an account exists. The front controller calls
     * runDeferred() after send(); under PHP-FPM, fastcgi_finish_request() closes the
     * connection first, so the client never waits on this work. runDeferred() closes
     * the session before running the queue, so a task must not read or write it.
     * One queue per App: under a persistent worker, call runDeferred() once per request.
     *
     * @param callable(): void $task
     */
    public function defer(callable $task): void
    {
        $this->deferred[] = $task;
        if (!$this->deferFallbackArmed) {
            // A front controller that never calls runDeferred() must not lose the work
            // (a reset email, say): whatever is still queued runs when the script ends.
            register_shutdown_function(fn () => $this->runDeferred());
            $this->deferFallbackArmed = true;
        }
    }

    /**
     * Run and clear the deferred queue. A failing task is logged and the rest still run:
     * the response is already gone, so there is no one left to report an error to.
     */
    public function runDeferred(): void
    {
        // Release the session lock first. With PHP's file handler the session stays
        // locked until the script ends, so a deferred send (only made for an existing
        // account) would stall the visitor's next request and reveal the account.
        // One session per request, worker loops included: close it even when nothing was deferred.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        // Deferred work runs after process() has unwound controller transactions, so
        // it carries the same guarantee per task: a task that opens a transaction and
        // fails is unwound to its entry depth, never leaked into later tasks or the
        // next request on a persistent App. Deferred writes also join the cache
        // contract: their tables purge cached pages, like in-request writes do.
        $db = $this->dbOrNull();
        $writes = [];
        $db?->onQuery(function (string $sql) use (&$writes): void {
            if (!\Kip\Cache\TableTagger::isWrite($sql)) return;
            foreach (\Kip\Cache\TableTagger::tables($sql) as $t) $writes[] = $t;
        });
        while ($this->deferred !== []) {
            $task = array_shift($this->deferred);
            $depth = $db?->transactionDepth() ?? 0;
            try {
                $task();
            } catch (\Throwable $e) {
                // Where it failed, not the full trace: trace arguments would copy a mail
                // recipient or message into the log.
                error_log(sprintf('Deferred task failed: %s: %s at %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            } finally {
                if ($db !== null && $db->transactionDepth() > $depth) $db->rollBackToDepth($depth);
            }
        }
        $db?->onQuery(fn () => null); // detach: the closure holds task-local refs
        if ($writes !== []) {
            $this->pageCache?->purgeByTables(array_unique($writes));
        }
    }

    /** Read-only config access for app code (base_url etc.). */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Register a named authorization policy. A route gated by
     * #[Auth(policy: 'name')] runs the closure after login and CSRF and
     * before the controller; false is a 403 (logged in but not allowed).
     * Register once per App in bootstrap; duplicate names are an error.
     * A closure may declare only the Session parameter: the kernel passes
     * (Session, Request), and extra inputs are ignored by userland closures.
     *
     * @param \Closure(Session $session, Request $request): bool $check
     */
    public function policy(string $name, \Closure $check): void
    {
        if (isset($this->policies[$name])) {
            throw new \LogicException("Policy '{$name}' is already registered; policy names are unique per App");
        }
        $this->policies[$name] = $check;
    }

    private function cachedProcess(Request $request, Session $active): Response
    {
        if ($this->pageCache === null) {
            return $this->process($request, $active);
        }

        $cacheable = in_array($request->method, ['GET', 'HEAD'], true)
            && $request->cookies === []                    // any cookie (session esp.) → personal → bypass
            && $request->body === ''                       // a body-carrying GET is body-dependent → bypass
            && $request->header('authorization') === null; // never served from or stored in a shared cache (RFC 9111)

        $query = http_build_query($request->get);
        if ($cacheable && ($hit = $this->pageCache->get($request->path, $query)) !== null) {
            return $this->conditional($request, $hit);
        }

        // Tap the DB for EVERY request: reads become tags (miss path), writes always purge.
        $reads = [];
        $writes = [];
        $db = $this->dbOrNull();
        $db?->onQuery(function (string $sql) use (&$reads, &$writes): void {
            foreach (\Kip\Cache\TableTagger::tables($sql) as $t) {
                \Kip\Cache\TableTagger::isWrite($sql) ? $writes[] = $t : $reads[] = $t;
            }
        });
        $touchesBefore = $active->touchCount();
        try {
            $response = $this->process($request, $active);
        } finally {
            $db?->onQuery(fn () => null); // detach. The closure holds request-local refs (worker safety)
        }

        if ($writes !== []) {
            $this->pageCache->purgeByTables(array_unique($writes)); // stale pages die with the write
        }

        if (!$cacheable) {
            return $response->withHeader('X-Kip-Cache', 'BYPASS');
        }
        // A render that touched the session is personal: never cache it (touch-delta, not a flag,
        // so one App instance serving many requests judges each request on its own).
        // A response that sets a cookie is personal too: never cache it (threat model: cache
        // poisoning). Field names are case-insensitive (RFC 9110 5.1), so every key is scanned,
        // not two spellings enumerated.
        $setsCookie = false;
        foreach (array_keys($response->headers) as $n) {
            if (strcasecmp((string) $n, 'Set-Cookie') === 0) { $setsCookie = true; break; }
        }
        if ($response->status === 200 && $writes === [] && $active->touchCount() === $touchesBefore && !$setsCookie) {
            $this->pageCache->put($request->path, $query, $response, array_unique($reads));
        }
        return $this->conditional($request, $response->withHeader('X-Kip-Cache', 'MISS'));
    }

    /**
     * The members of an If-None-Match list, weak-normalized: quoted tags keep
     * embedded commas, a W/ prefix drops, and * passes through (RFC 9110 §8.8.3).
     *
     * @return list<string>
     */
    private static function entityTagList(string $inm): array
    {
        // Four backslashes = one literal backslash: PHP single-quoting halves '\\\\'
        // to '\\', and the regex engine reads '\\' as one backslash. [^"\\\\] is
        // "any char except quote and backslash"; the \\\\. alternative consumes an
        // escaped char, which is what keeps a tag like "a\"b" (or a quoted comma)
        // inside one member instead of truncating it. Halving either pair, or
        // dropping the arm, breaks member extraction and with it every 304 on a list.
        preg_match_all('~\*|(?:W/)?"(?:[^"\\\\]|\\\\.)*"~', $inm, $m);
        $members = [];
        foreach ($m[0] as $tag) {
            $members[] = str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
        }
        return $members;
    }

    /** ETag/304: answer conditional GETs without a body (RFC 9110 §13). */
    private function conditional(Request $request, Response $response): Response
    {
        if ($response->status !== 200) {
            return $response; // review D5c: no validators on error responses
        }
        // Field names are case-insensitive (RFC 9110 5.1): an app may spell the
        // header etag, so a scan replaces the single-key lookup.
        $etag = null;
        foreach ($response->headers as $n => $v) {
            // A list ETag takes its first leaf, mirroring PageCache::store():
            // the miss path and the stored/hit path must use the same validator
            // (a bare cast would produce the string "Array" here).
            if (strcasecmp((string) $n, 'ETag') === 0) { $etag = (string) (is_array($v) ? ($v[0] ?? '') : $v); break; }
        }
        $etag ??= '"' . hash('sha256', $response->body) . '"';
        // Canonicalize, not append: PHP array keys are case-sensitive, so
        // withHeader('ETag') beside the app's lowercase spelling would send two
        // ETag fields (RFC 9110 §8.8.3 allows at most one).
        $kept = [];
        foreach ($response->headers as $n => $v) {
            if (strcasecmp((string) $n, 'ETag') !== 0) $kept[(string) $n] = $v;
        }
        $response = new Response($response->body, $response->status, [...$kept, 'ETag' => $etag]);
        // RFC 9110 §13.1.2: If-None-Match is a comma-separated list of validators,
        // or the wildcard *. The weak prefix strips per member (review D5d: weak
        // validators compare by value for GET). A validator is always present here,
        // so * matches whatever this response carries.
        $match = false;
        $inm = $request->header('if-none-match');
        if ($inm !== null) {
            $weak = str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
            foreach (self::entityTagList($inm) as $member) {
                if ($member === '*' || $member === $weak) { $match = true; break; }
            }
        }
        if ($match) {
            // RFC 9110 §15.4.5: a 304 carries the cache-relevant headers the 200 would
            // have sent. Field names are case-insensitive (RFC 9110 5.1), so keys are
            // scanned, not two spellings enumerated. No etag arm: the single
            // validator is set once from $etag below.
            $keep = ['x-kip-cache', 'cache-control', 'expires', 'vary', 'content-location'];
            $headers = [];
            foreach ($response->headers as $n => $v) {
                if (in_array(strtolower((string) $n), $keep, true)) $headers[(string) $n] = $v;
            }
            $headers['ETag'] = $etag;
            $headers['X-Kip-Cache'] ??= 'HIT';
            return new Response('', 304, $headers);
        }
        return $response;
    }

    private function process(Request $request, Session $active): Response
    {
        // A controller that opened a transaction and then failed must not leak it
        // into the next request on a persistent App: snapshot the depth at entry
        // and unwind to it on the way out.
        $db = $this->dbOrNull();
        $depth = $db?->transactionDepth() ?? 0;
        try {
            // Rate limiting (ch. 6) sits before routing: a configured prefix
            // counts every non-GET request (one upsert), page renders and
            // unconfigured prefixes pay nothing, and an over-limit request
            // never reaches a controller, a session, or a transaction. The
            // plain body matches the framework's other error responses;
            // Retry-After says when the window resets.
            if ($this->rateLimit !== null && !in_array($request->method, ['GET', 'HEAD'], true)) {
                $retryAfter = $this->rateLimit->check($request->path, $request->ip);
                if ($retryAfter !== null) {
                    return new Response('Too many requests', 429, ['Retry-After' => (string) $retryAfter]);
                }
            }
            $match = $this->router->match($request);
            if ($match === null) return new Response('Page not found', 404);
            // Review 1A + 7A: request state (Request AND Session) lives in a
            // per-request scope, never the long-lived container, worker-mode
            // guarantee (D7). The boot Session alone would go stale under a
            // persistent worker, so each request may bring its own.
            // Login before CSRF: a guest gets the same redirect on a gated route whatever
            // the token, so neither the response nor its status confirms the route exists.
            if ($match->requiresAuth && !$this->authSessionValid($active)) {
                return Response::redirect('/auth/login');
            }
            if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                // The token rides the form field or the X-CSRF-Token header: a
                // browser cannot set a custom header cross-site without the CORS
                // preflight this app has not granted, so the header is not a
                // weakening; it still has to match the session's via hash_equals.
                $tokenOk = $active->validateCsrf($request->postStr('_token') ?: $request->header('x-csrf-token'));
                if ($match->requiresAuth) {
                    // Ambient authority at stake: the session token is mandatory (v0.2 T2).
                    if (!$tokenOk) return new Response('Invalid or missing CSRF token', 403);
                } elseif (!$tokenOk && !$this->sameOriginProof($request)) {
                    return new Response('Cross-site request rejected', 403);
                }
                // A #[Json] route whose body claims a JSON media type but does not
                // parse is a 400 before the action runs. After the CSRF gate, so a
                // tokenless POST stays 403; an empty body passes (json() is null,
                // the action decides). Zero change for non-JSON routes.
                $contentType = strtolower(trim((string) $request->header('content-type')));
                if ($match->json && $request->body !== '' && $request->json() === null
                    && preg_match('#^application/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)#', $contentType) === 1
                ) {
                    return new Response('Malformed JSON body', 400);
                }
            }
            // Named policy: after login and CSRF (ambient authority proven before
            // any authorization outcome is revealed), before the controller. An
            // unregistered name is a loud misconfiguration, never an allow; a
            // non-bool verdict is too (a truthy string from ?: shorthand must
            // not authorize). Denial is the framework's plain 403, JSON routes
            // included: the user is logged in, just not allowed.
            if ($match->policy !== null) {
                $check = $this->policies[$match->policy]
                    ?? throw new \LogicException("Route '{$request->path}' names policy '{$match->policy}' but no App::policy() registered it");
                $verdict = $check($active, $request);
                if (!is_bool($verdict)) {
                    throw new \LogicException("Policy '{$match->policy}' must return bool, got " . get_debug_type($verdict));
                }
                if (!$verdict) return new Response('Forbidden', 403);
            }
            $scope = clone $this->container;
            $scope->instance(Request::class, $request);
            $scope->instance(Session::class, $active);
            $result = $match->invoke($scope);
            if ($result instanceof Response) return $result;
            if ($match->json) {
                // THROW_ON_ERROR: an unencodable value raises JsonException into the
                // existing catch (dev error page / prod 500), never a half-encoded body.
                return new Response((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/json']);
            }
            return new Response((string) $result);
        } catch (\Kip\Routing\MethodNotAllowedException $e) {
            // A guest learns nothing about a gated route: the same redirect a right-verb request gets.
            if ($e->requiresAuth && !$this->authSessionValid($active)) return Response::redirect('/auth/login');
            return (new Response('Method not allowed', 405))->withHeader('Allow', $e->getMessage()); // review 9A
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $request);
        } finally {
            if ($db !== null && $db->transactionDepth() > $depth) $db->rollBackToDepth($depth);
        }
    }

    /**
     * Epoch gate: with a database configured, a logged-in session must still match the
     * user's current password-hash prefix, a password reset or admin password edit
     * revokes every session logged in before it. Without a database there is nothing
     * to verify against; session-only auth is that app's documented mode.
     */
    private function authSessionValid(Session $active): bool
    {
        if ($active->get('user_id') === null) return false;
        $db = $this->dbOrNull();
        if ($db === null) return true;
        return (new Auth($db, $active))->sessionValid();
    }

    /** OWASP fetch-metadata pattern: reject only on positive cross-site evidence; absent-all = non-browser client, no CSRF victim. */
    private function sameOriginProof(Request $request): bool
    {
        $sfs = $request->header('sec-fetch-site');
        if ($sfs !== null) {
            return in_array($sfs, ['same-origin', 'none'], true);
        }
        $origin = $request->header('origin') ?? $request->header('referer');
        if ($origin !== null) {
            // Compare against the request's own Host header, nothing to misconfigure (OV P2b).
            // Host is server/proxy-derived; a cross-site victim's browser cannot alter it.
            $host = parse_url($origin, PHP_URL_HOST);
            $port = parse_url($origin, PHP_URL_PORT);
            $originHost = $host . ($port !== null ? ":{$port}" : '');
            return $originHost !== '' && $originHost === ($request->header('host') ?? '');
        }
        // Accepted risk (README-documented, OV P2a): headerless clients pass. Legacy or
        // privacy-hardened browsers lacking Origin/Referer/Fetch-Metadata match this same
        // signature. For guest-only routes with no ambient authority, worst case is
        // spam-shaped, which the comment-throttle TODO owns.
        return true;
    }

    private function errorResponse(\Throwable $e, Request $request): Response
    {
        if (($this->config['env'] ?? 'prod') === 'dev') {
            return new Response((new DevErrorPage())->render($e, $request->method, $request->path), 500);
        }
        error_log((string) $e); // full detail to the log, never the browser
        return new Response('Something went wrong', 500);
    }
}
