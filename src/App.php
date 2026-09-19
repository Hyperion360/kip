<?php // src/App.php
namespace Kip;

use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Router;

final class App
{
    public readonly Container $container;
    public readonly Session $session;
    private Router $router;
    private array $sessionStore = [];
    private ?RequestLog $requestLog = null;
    private ?\Kip\Cache\PageCache $pageCache = null;

    public function __construct(private array $config, ?Session $session = null)
    {
        $this->container = new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance(View::class, new View($config['views'] ?? ($config['app_dir'] ?? '.') . '/views'));
        if (isset($config['db']['dsn'])) {
            $this->container->instance(Database::class, new Database($config['db']['dsn']));
        }
        if (isset($config['log_db']['dsn'])) {
            $this->requestLog = new RequestLog(new Database($config['log_db']['dsn']), $config['log_db']['retention_days'] ?? 30);
            $this->container->instance(RequestLog::class, $this->requestLog); // tests read it from here
        }
        if (isset($config['cache_db']['dsn'])) {
            // OWN Database instance, NEVER container-resolved, or self-tagging re-materializes
            // (the cache's own writes would tag themselves as invalidation targets).
            $this->pageCache = new \Kip\Cache\PageCache(
                new Database($config['cache_db']['dsn']),
                $config['cache_db']['ttl_seconds'] ?? 3600
            );
        }
        if (isset($config['uploads']['dir'])) {
            $this->container->instance(Storage::class, new Storage(
                $config['uploads']['dir'],
                $config['uploads']['max_bytes'] ?? Storage::DEFAULT_MAX_BYTES,
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
        $this->router = new Router($namespaces);
        $this->session = $session ?? new Session($this->sessionStore);
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
        $this->requestLog?->log($request, $response->status, $active->peek('user_id'), (hrtime(true) - $start) / 1e6, $auditPath);
        return $response;
    }

    /** Read-only config access for app code (base_url etc.). */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    private function cachedProcess(Request $request, Session $active): Response
    {
        if ($this->pageCache === null) {
            return $this->process($request, $active);
        }

        $cacheable = in_array($request->method, ['GET', 'HEAD'], true)
            && $request->cookies === [];              // any cookie (session esp.) → personal → bypass

        $query = http_build_query($request->get);
        if ($cacheable && ($hit = $this->pageCache->get($request->path, $query)) !== null) {
            return $this->conditional($request, $hit);
        }

        // Tap the DB for EVERY request: reads become tags (miss path), writes always purge.
        $reads = [];
        $writes = [];
        $db = null;
        try { $db = $this->container->make(Database::class); } catch (\Throwable) {}
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
        // a response that sets a cookie is personal, never cache it (threat model: cache poisoning)
        if ($response->status === 200 && $writes === [] && $active->touchCount() === $touchesBefore
            && array_intersect(['Set-Cookie', 'set-cookie'], array_keys($response->headers)) === []) {
            $this->pageCache->put($request->path, $query, $response, array_unique($reads));
        }
        return $this->conditional($request, $response->withHeader('X-Kip-Cache', 'MISS'));
    }

    /** ETag/304: answer conditional GETs without a body (RFC 9110 §13). */
    private function conditional(Request $request, Response $response): Response
    {
        if ($response->status !== 200) {
            return $response; // review D5c: no validators on error responses
        }
        $etag = $response->headers['ETag'] ?? '"' . hash('sha256', $response->body) . '"';
        $response = $response->withHeader('ETag', $etag);
        $inm = $request->header('if-none-match');
        if ($inm !== null && str_starts_with($inm, 'W/')) {
            $inm = substr($inm, 2); // review D5d: weak validators compare by value for GET
        }
        if ($inm === $etag) {
            return new Response('', 304, ['ETag' => $etag, 'X-Kip-Cache' => $response->headers['X-Kip-Cache'] ?? 'HIT']);
        }
        return $response;
    }

    private function process(Request $request, Session $active): Response
    {
        try {
            $match = $this->router->match($request);
            if ($match === null) return new Response('Page not found', 404);
            // Review 1A + 7A: request state (Request AND Session) lives in a
            // per-request scope, never the long-lived container, worker-mode
            // guarantee (D7). The boot Session alone would go stale under a
            // persistent worker, so each request may bring its own.
            if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                $tokenOk = $active->validateCsrf($request->postStr('_token') ?: null);
                if ($match->requiresAuth) {
                    // Ambient authority at stake: the session token is mandatory (v0.2 T2).
                    if (!$tokenOk) return new Response('Invalid or missing CSRF token', 403);
                } elseif (!$tokenOk && !$this->sameOriginProof($request)) {
                    return new Response('Cross-site request rejected', 403);
                }
            }
            if ($match->requiresAuth && !$this->authSessionValid($active)) {
                return Response::redirect('/auth/login');
            }
            $scope = clone $this->container;
            $scope->instance(Request::class, $request);
            $scope->instance(Session::class, $active);
            $result = $match->invoke($scope);
            return $result instanceof Response ? $result : new Response((string) $result);
        } catch (\Kip\Routing\MethodNotAllowedException $e) {
            return (new Response('Method not allowed', 405))->withHeader('Allow', $e->getMessage()); // review 9A
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
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
        try {
            $db = $this->container->make(Database::class);
        } catch (\Throwable) {
            return true;
        }
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

    private function errorResponse(\Throwable $e): Response
    {
        if (($this->config['env'] ?? 'prod') === 'dev') {
            $body = '<h1>' . htmlspecialchars(get_class($e)) . ': ' . htmlspecialchars($e->getMessage()) . '</h1>'
                  . '<p>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>'
                  . '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            return new Response($body, 500);
        }
        error_log((string) $e); // full detail to the log, never the browser
        return new Response('Something went wrong', 500);
    }
}
