<?php // src/RateLimit.php

declare(strict_types=1);
namespace Kip;

use Kip\Routing\Router;

/**
 * Fixed-window rate limiting, table-backed. Config maps a route prefix (the
 * first URL segment, canonicalized through the router's studly() rule so
 * dashed and underscored spellings of one controller share a bucket) to a
 * `['max' => 10, 'window' => 60]` pair; every non-GET request to a configured
 * prefix counts one hit, enforced in App::process() BEFORE routing, so an
 * over-limit request never reaches a controller, a session, or a transaction.
 *
 * One hit is one UPSERT: INSERT ... ON CONFLICT (prefix, ip, window_start)
 * DO UPDATE SET hits = hits + 1 RETURNING hits, atomic under concurrency by
 * construction. The hit that opens a new window also retires expired rows
 * (one DELETE), so a configured POST-action route pays exactly one upsert in
 * the steady state.
 * Unconfigured apps and every GET/HEAD request pay nothing, and with no
 * '*' key an unconfigured prefix pays nothing either: page renders keep
 * the one-query budget. The key '*' configures the fallback consulted
 * when no explicit prefix matches; the hit still records under the
 * request's own canonical prefix, so surfaces sharing the fallback hold
 * independent buckets, and an explicit entry (the empty string for
 * POST / included) always wins.
 */
final class RateLimit
{
    /** The counting statement: one row per (prefix, ip, window), the conflict target IS the primary key. */
    private const UPSERT = 'INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?, ?, ?, 1)'
        . ' ON CONFLICT (prefix, ip, window_start) DO UPDATE SET hits = hits + 1'
        . ' RETURNING hits';

    /** Retires expired windows across every key at once; seeks idx_rate_limits_window. */
    private const PRUNE = 'DELETE FROM rate_limits WHERE window_start < ?';

    /** The router's own segment grammar (Router::NAME, the shared constant), the root spelled ''. */
    private const SEGMENT = Router::NAME;

    /**
     * Longest raw first segment the fallback will count: limiter policy, not
     * router policy (the router sets no length bound). A controller segment
     * longer than this would route yet go uncounted under '*', so no real
     * app should have one; a real controller name is short.
     */
    private const MAX_FALLBACK_SEGMENT = 64;


    /** @var array<string, array{max: int, window: int}> studly-canonicalized prefix => limit */
    private array $limits;

    /** @var array{max: int, window: int}|null the '*' fallback limit pair, or null when unset */
    private ?array $catchAll = null;

    /** The largest configured window: the prune's grace period, see check(). */
    private int $maxWindow;

    /**
     * @param array<array-key, mixed> $limits prefix => ['max' => int, 'window' => int] (or '*' for the fallback)
     * @throws \InvalidArgumentException on a shape that would silently protect nothing
     * @throws \RuntimeException on a driver that cannot run the counting upsert
     */
    public function __construct(private Database $db, array $limits)
    {
        self::assertDriverSupport($db->dsn(), $db->serverVersion());
        $parsed = [];
        $this->maxWindow = 0;
        foreach ($limits as $prefix => $limit) {
            // PHP folds the array key '0' to the integer 0; the segment "0"
            // is a legal first segment and must still configure a prefix.
            $prefix = is_int($prefix) ? (string) $prefix : $prefix;
            if ($prefix === '*') {
                // The catch-all: not a route prefix (the segment grammar below
                // rejects it, so no explicit key can collide), never
                // canonicalized, its window still bounds the prune grace like
                // any explicit one. Handled BEFORE the segment check, which
                // would refuse '*' as not a route prefix.
                $this->catchAll = self::parseLimit($limit, '*');
                $this->maxWindow = max($this->maxWindow, $this->catchAll['window']);
                continue;
            }
            if (!($prefix === '' || preg_match(self::SEGMENT, $prefix) === 1)) {
                throw new \InvalidArgumentException(sprintf(
                    'config key "rate_limit.%s" is not a route prefix: use the first URL segment '
                    . '(lowercase letters, digits, single - or _ separators), the empty string for POST /, '
                    . "or '*' for every prefix without its own entry",
                    $prefix
                ));
            }
            $canonical = Router::studly($prefix);
            if (isset($parsed[$canonical])) {
                throw new \InvalidArgumentException(sprintf(
                    'config keys under "rate_limit" collide: "%s" and its alias both canonicalize to %s '
                    . '(the router serves both spellings); configure one',
                    $prefix, $canonical
                ));
            }
            $parsed[$canonical] = self::parseLimit($limit, $prefix);
            $this->maxWindow = max($this->maxWindow, $parsed[$canonical]['window']);
        }
        $this->limits = $parsed;
    }

    /**
     * Count one hit against the bucket the path maps to and report the verdict.
     * Returns null when the request is allowed (or no limit applies: the
     * prefix has no explicit entry and no '*' fallback is configured, which
     * never touches the database), or the Retry-After seconds when it is
     * over the limit. The optional $now is the test clock
     * seam; production always passes nothing and reads the real clock once,
     * immediately before the upsert.
     */
    public function check(string $path, string $ip, ?int $now = null): ?int
    {
        $raw = self::firstSegmentOf($path);
        $prefix = Router::studly($raw);
        $limit = $this->limits[$prefix] ?? null;
        if ($limit === null && $this->catchAll !== null) {
            // The fallback counts only spellings the router's grammar ADMITS:
            // the root, or a short first segment passing Router::NAME (the
            // rule every config key must pass). Uppercase, encoded,
            // doubled-separator, and oversized spellings are guaranteed 404s,
            // so charging them would let rotated junk POSTs write
            // attacker-named rows before the 404 and never trip the cap
            // (every rotation is a fresh bucket). A grammatical spelling that
            // routes nowhere is still charged, by design: it is indistinguishable
            // from a real route before routing runs, one row per distinct
            // segment within the prune grace. Explicit prefixes keep today's
            // semantics: a spelling that canonicalizes to a configured key
            // counts.
            if ($raw !== '' && (strlen($raw) > self::MAX_FALLBACK_SEGMENT || preg_match(self::SEGMENT, $raw) !== 1)) {
                return null;
            }
            $limit = $this->catchAll;
        }
        if ($limit === null) return null;
        if ($this->db->transactionDepth() > 0) {
            // The Jobs::claim() contract: a hit inside a caller's transaction
            // would roll back with it, and the spent quota would never count.
            throw new \RuntimeException(sprintf(
                'RateLimit::check() needs an autocommit connection, a transaction is open (depth %d). '
                . 'Count a hit outside Database::begin()/commit(); inside one, a rollback would erase it.',
                $this->db->transactionDepth()
            ));
        }
        $now ??= time();
        if ($now < 0) {
            throw new \InvalidArgumentException('$now must be a unix timestamp, got ' . $now);
        }
        $windowStart = intdiv($now, $limit['window']) * $limit['window'];
        $row = $this->db->one(self::UPSERT, [$prefix, self::ipKey($ip), $windowStart]);
        if ($row === null) {
            throw new \RuntimeException('the counting upsert returned no row, the hit was not recorded');
        }
        $hits = (int) $row['hits'];
        if ($hits === 1) {
            // A new window row was just created: retire expired rows. The
            // cutoff keeps every LIVE bucket: a bucket with window W starting
            // at S is live while S > now - W, and W <= maxWindow gives
            // S > now - W >= now - maxWindow, so nothing at or after the
            // cutoff is ever live. Cutting at the requester's own window
            // start instead would delete live buckets of prefixes configured
            // with shorter windows.
            $this->db->query(self::PRUNE, [$now - $this->maxWindow]);
        }
        if ($hits > $limit['max']) {
            return $limit['window'] - ($now - $windowStart);
        }
        return null;
    }

    /**
     * The driver must run the counting upsert (ON CONFLICT ... RETURNING):
     * SQLite 3.35+ (bundled in every PHP 8.3+ build, but a system-linked
     * build can be older) and PostgreSQL 9.5+. Anything else fails at boot
     * with the remedy named, never as a 500 on the first counted request.
     */
    public static function assertDriverSupport(string $dsn, string $serverVersion): void
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            if (version_compare($serverVersion, '3.35', '<')) {
                throw new \RuntimeException(sprintf(
                    'rate limiting needs SQLite 3.35+ for its counting upsert (RETURNING), this PHP reports SQLite %s',
                    $serverVersion
                ));
            }
            return;
        }
        if (str_starts_with($dsn, 'pgsql:')) {
            if (version_compare($serverVersion, '9.5', '<')) {
                throw new \RuntimeException(sprintf(
                    'rate limiting needs PostgreSQL 9.5+ for its counting upsert (ON CONFLICT), this server reports %s',
                    $serverVersion
                ));
            }
            return;
        }
        throw new \RuntimeException(sprintf(
            'rate limiting supports the SQLite and PostgreSQL drivers for its counting upsert, this "%s" DSN is neither',
            strstr($dsn, ':', true) ?: $dsn
        ));
    }

    /**
     * The request's raw first URL segment ('' for the root), before
     * canonicalization: filter empty strings only (the segment "0" is
     * legal). check() canonicalizes through studly() so both spellings of a
     * dashed controller name are one prefix, and gates the fallback on this
     * raw shape, the shape the router's whitelist judges.
     */
    private static function firstSegmentOf(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn(string $p): bool => $p !== ''));
        return $segments[0] ?? '';
    }

    /**
     * One form per address: equivalent IPv6 spellings ('2001:db8::1' and the
     * expanded form) share a bucket. A value that is not an IP at all (a
     * broken SAPI, a test double) is the key verbatim: distinct garbage must
     * never collapse into one bucket, and '' stays the shared no-IP bucket.
     */
    private static function ipKey(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false
            ? $ip
            : (string) inet_ntop((string) inet_pton($ip));
    }

    /**
     * Config integers accept ints and numeric strings ('30'); anything else,
     * or a value below $minimum, is a boot-time error naming the key, the
     * App::intConfig semantics.
     */
    private static function intConfig(mixed $value, string $key, int $minimum): int
    {
        $int = is_int($value) ? $value
            : (is_string($value) && is_numeric(trim($value)) ? (int) trim($value) : null);
        if ($int === null || $int < $minimum) {
            throw new \InvalidArgumentException(sprintf(
                'config key "%s" must be an int or a numeric string >= %d, got %s',
                $key,
                $minimum,
                get_debug_type($value)
            ));
        }
        return $int;
    }

    /**
     * One config entry to a max/window pair, the boot-time contract: the value
     * must be an array and both members must be ints (or numeric strings) at
     * or above their minimums, anything else names its own key in the error.
     * @return array{max: int, window: int}
     */
    private static function parseLimit(mixed $limit, string $key): array
    {
        if (!is_array($limit)) {
            throw new \InvalidArgumentException("config key \"rate_limit.{$key}\" must be an array with max and window");
        }
        return [
            'max' => self::intConfig($limit['max'] ?? null, "rate_limit.{$key}.max", 0),
            'window' => self::intConfig($limit['window'] ?? null, "rate_limit.{$key}.window", 1),
        ];
    }
}
