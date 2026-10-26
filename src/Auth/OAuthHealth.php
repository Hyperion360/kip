<?php // src/Auth/OAuthHealth.php
declare(strict_types=1);
namespace Kip\Auth;

use Kip\Database;

/**
 * The readiness gate behind the "Sign in with ..." buttons: a provider is
 * offered only while its credentials pass OAuthProvider::probe() against the
 * provider's own token endpoint. Verdicts cache in the cache database (healthy
 * for an hour, failed for five minutes) so a page render probes only on a
 * miss, and a miss is claimed before the probe (a provisional failed row), so
 * concurrent renders coalesce onto one probe per provider per window instead
 * of each paying the timeout; an indeterminate probe (timeout, 429, 5xx) keeps
 * the previous verdict and re-stamps it (a sliding window, so an outage probes
 * at most once per TTL) and, with none, fails closed: an unproven provider
 * stays hidden.
 * 'oauth' => ['probe' => false] turns the network half off: readiness then
 * means config-complete, for hosts with no outbound network at boot time.
 * Cache trouble degrades to per-request memory, never a failed page.
 */
final class OAuthHealth
{
    public const TTL_OK = 3600;
    public const TTL_FAILED = 300;

    private ?Database $cache;

    /** @var array<string, bool> per-request memo when the cache is unavailable */
    private array $memo = [];

    private bool $tableReady = false;

    /** @param array<string, mixed> $providers complete entries (client id, secret/key, redirect_uri)
     * @param ?\Closure(string): ?bool $prober test seam; default probes via OAuthProvider. A Closure, not a callable type: PHP forbids callable property types. */
    public function __construct(?Database $cache, private array $providers, private bool $probe = true, private ?\Closure $prober = null)
    {
        $this->cache = $cache; // the table is created lazily on the first cache read/write, never as DDL on a plain page render
    }

    /** One-time lazy table create; cache trouble degrades to memory, never a failed page. */
    private function ensureTable(): void
    {
        if ($this->cache === null || $this->tableReady) return;
        try {
            $this->cache->query('CREATE TABLE IF NOT EXISTS oauth_health (
                provider TEXT PRIMARY KEY, healthy INTEGER NOT NULL, checked_at INTEGER NOT NULL)');
            $this->tableReady = true;
        } catch (\PDOException) {
            $this->cache = null;
        }
    }

    /** The configured provider names whose credentials currently pass.
     * @return list<string> */
    public function ready(): array
    {
        $names = [];
        foreach ($this->providers as $name => $entry) {
            if ($this->healthy((string) $name, is_array($entry) ? $entry : [])) $names[] = (string) $name;
        }
        return $names;
    }

    /** @param array<string, mixed> $entry */
    public function healthy(string $name, array $entry): bool
    {
        if (!OAuthProvider::configured($name, $entry)) return false;
        if (!$this->probe) return true;
        $key = $name;
        if (isset($this->memo[$key])) return $this->memo[$key];
        $cached = $this->cached($name);
        if ($cached !== null) return $this->memo[$key] = $cached;
        if ($this->cache === null && isset(self::$local[$name])) {
            // Cache-less hosts keep a process-local verdict with the SAME TTL rules
            // as the cache row, so a render never re-probes inside a window.
            [$verdict, $stamp] = self::$local[$name];
            if (time() - $stamp <= ($verdict ? self::TTL_OK : self::TTL_FAILED)) return $this->memo[$key] = $verdict;
        }
        // Claim before probing: a provisional failed row stamped now means every
        // concurrent render inside this window reads a fresh verdict and skips
        // the network. One probe per provider per window across all workers,
        // whatever the request rate, and the real verdict overwrites it below.
        $prior = $this->cached($name, true);
        $this->store($name, false);
        $verdict = $this->prober !== null ? ($this->prober)($name) : $this->probeProvider($name, $entry);
        if ($this->cache === null) {
            // Indeterminate with no prior is a failed verdict for the window, the
            // same rule the cache row follows below.
            self::$local[$name] = [$verdict ?? (self::$local[$name][0] ?? false), time()];
        }
        if ($verdict === null) {
            if ($prior !== null) {
                // Sliding window: keep the previous verdict AND re-stamp it, so a
                // long outage probes at most once per TTL instead of on every
                // render, and the provider stays visible until an actual false
                // verdict lands (never blinked off by a timeout alone).
                $this->store($name, $prior);
                return $this->memo[$key] = $prior;
            }
            return $this->memo[$key] = false; // the claim already holds the failed TTL
        }
        $this->store($name, $verdict);
        return $this->memo[$key] = $verdict;
    }

    /** @var array<string, array{0: bool, 1: int}> process-local verdicts for the cache-less path (name => [verdict, stamp]) */
    private static array $local = [];

    /** Forget the cache-less verdicts: tests, and a long-lived worker whose providers were reconfigured. */
    public static function resetClaims(): void
    {
        self::$local = [];
    }

    /** @param array<string, mixed> $entry */
    private function probeProvider(string $name, array $entry): ?bool
    {
        try {
            $sessionData = []; // Session takes its array by reference, so a variable, not a literal
            $provider = new OAuthProvider($name, $entry, new \Kip\Session($sessionData));
            return $provider->probe();
        } catch (\Throwable) {
            return null; // a broken entry cannot render anyway; gate it off quietly
        }
    }

    private function cached(string $name, bool $ignoreTtl = false): ?bool
    {
        $this->ensureTable();
        if ($this->cache === null) return null;
        try {
            $row = $this->cache->one('SELECT healthy, checked_at FROM oauth_health WHERE provider = ?', [$name]);
        } catch (\PDOException) {
            return null;
        }
        if ($row === null) return null;
        $ttl = (int) $row['healthy'] === 1 ? self::TTL_OK : self::TTL_FAILED;
        if (!$ignoreTtl && time() - (int) $row['checked_at'] > $ttl) return null;
        return (int) $row['healthy'] === 1;
    }

    private function store(string $name, bool $verdict): void
    {
        $this->ensureTable();
        if ($this->cache === null) return;
        try {
            $this->cache->query(
                'INSERT INTO oauth_health (provider, healthy, checked_at) VALUES (?, ?, ?)
                 ON CONFLICT(provider) DO UPDATE SET healthy = excluded.healthy, checked_at = excluded.checked_at',
                [$name, $verdict ? 1 : 0, time()]
            );
        } catch (\PDOException) {
            $this->cache = null; // the write path broke; keep gating from memory
        }
    }
}
