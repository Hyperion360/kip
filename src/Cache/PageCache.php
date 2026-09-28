<?php // src/Cache/PageCache.php

declare(strict_types=1);
namespace Kip\Cache;

use Kip\Database;
use Kip\Http\Response;

/** Full-page cache for anonymous GETs: SQLite store + table-tag purge (CDN Surrogate-Key model, v0.2). */
final class PageCache
{
    public const DEFAULT_MAX_PAGES = 10000;
    /** Bumped whenever the rules deciding what may be stored change; rows written
     *  under older rules are cleared once on first open (see the constructor). */
    private const FORMAT_VERSION = 2;

    /** @param int $maxPages row cap: every distinct query string is its own row, so without one junk queries fill the disk */
    public function __construct(private Database $db, private int $ttlSeconds = 3600, private int $maxPages = self::DEFAULT_MAX_PAGES)
    {
        if ($maxPages < 1) {
            // 0 would evict every page as it is written: caching silently off.
            throw new \InvalidArgumentException("cache_db.max_pages must be at least 1, got {$maxPages}");
        }
        $this->db->query('CREATE TABLE IF NOT EXISTS pages (
            key TEXT PRIMARY KEY, body TEXT NOT NULL, headers TEXT NOT NULL,
            etag TEXT NOT NULL, created_at INTEGER NOT NULL
        )');
        $this->db->query('CREATE TABLE IF NOT EXISTS page_tags (
            tag TEXT NOT NULL, key TEXT NOT NULL, PRIMARY KEY (tag, key)
        )');
        // Serves the TTL prune and the oldest-first eviction below.
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_pages_created_at ON pages (created_at)');
        // page_tags' key leads with tag, so every delete by page key (prune, eviction,
        // purge) scanned the whole table without this one.
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_page_tags_key ON page_tags (key)');
        $this->db->query('CREATE TABLE IF NOT EXISTS cache_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        $version = $this->db->one('SELECT v FROM cache_meta WHERE k = ?', ['format']);
        if (($version['v'] ?? null) !== (string) self::FORMAT_VERSION) {
            // Rows written under older storing rules must not outlive them: clear once.
            // A fresh database clears nothing (the tables were just created empty).
            // One transaction, like every other multi-write this class performs.
            $this->db->begin();
            try {
                $this->db->query('DELETE FROM pages');
                $this->db->query('DELETE FROM page_tags');
                $this->db->query('INSERT OR REPLACE INTO cache_meta (k, v) VALUES (?, ?)', ['format', self::FORMAT_VERSION]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }

    private function key(string $path, string $query): string
    {
        return hash('sha256', $path . '?' . $query);
    }

    public function get(string $path, string $query): ?Response
    {
        $row = $this->db->one('SELECT * FROM pages WHERE key = ?', [$this->key($path, $query)]);
        if ($row === null) return null;
        if (time() - (int) $row['created_at'] >= $this->ttlSeconds) { // >= not >: ttl 0 must expire same-second entries (OV P1b)
            $this->forget($row['key']);
            return null;
        }
        $headers = json_decode($row['headers'], true);
        return new Response($row['body'], 200, [...$headers, 'X-Kip-Cache' => 'HIT', 'ETag' => $row['etag']]);
    }

    /**
     * Store a 200 HTML response with the tables its render read as purge tags.
     *
     * @param list<string> $tables
     */
    public function put(string $path, string $query, Response $response, array $tables): void
    {
        if ($response->status !== 200) return;
        foreach ($response->headers as $n => $v) {
            // A noindexed page (an empty listing, say) must not consume cache rows:
            // junk URLs would otherwise each write one TTL-bounded row. The header
            // name is matched case-insensitively; the value only has to contain
            // the directive (comma lists included). Cast, not trust: the
            // array<string,string> docblock is a contract PHP does not enforce.
            if (strcasecmp((string) $n, 'X-Robots-Tag') === 0 && stripos((string) $v, 'noindex') !== false) return;
            // The cache key is path plus query string; a Vary header declares a
            // representation variance this key does not model, so the row is
            // refused rather than shared across the varied requests.
            if (strcasecmp((string) $n, 'Vary') === 0) return;
        }
        $this->db->begin(); // one transaction: a write plus its prune is one sync, not one per statement
        try {
            $this->store($path, $query, $response, $tables);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param list<string> $tables */
    private function store(string $path, string $query, Response $response, array $tables): void
    {
        $key = $this->key($path, $query);
        // The app's own validator, else a body hash: the stored tag must be the
        // same one the MISS path emitted, so revalidation compares like with like.
        // Field names are case-insensitive (RFC 9110 5.1), so a scan, not a
        // single-key lookup: an app spelling the header etag must not get its
        // tag replaced by the body hash (App::conditional scans the same way).
        $etag = null;
        foreach ($response->headers as $n => $v) {
            if (strcasecmp((string) $n, 'ETag') === 0) { $etag = (string) $v; break; }
        }
        $etag ??= '"' . hash('sha256', $response->body) . '"';
        $defaults = Response::defaultHeaders();
        $stored = [];
        foreach ($response->headers as $n => $v) {
            // An entry identical to a current default is not stored: defaults must
            // come from the reading framework, not the caching one. Trade-off: an
            // app that deliberately sets a header to exactly the default value also
            // loses it on the next default change, until the page is re-cached.
            if (($defaults[$n] ?? null) === $v) continue;
            // The validator lives in the etag column; a stored copy under any
            // spelling would put two validators on a HIT.
            if (strcasecmp((string) $n, 'ETag') === 0) continue;
            $stored[$n] = $v;
        }
        $this->db->query('INSERT OR REPLACE INTO pages (key, body, headers, etag, created_at) VALUES (?, ?, ?, ?, ?)',
            [$key, $response->body, json_encode($stored), $etag, time()]);
        $this->db->query('DELETE FROM page_tags WHERE key = ?', [$key]);
        foreach ($tables as $t) {
            $this->db->query('INSERT OR IGNORE INTO page_tags (tag, key) VALUES (?, ?)', [$t, $key]);
        }
        // Opportunistic prune of expired rows keeps the file bounded (cheap on write path).
        // Tags first. The subquery needs the still-live pages rows (T4 fix-round: the
        // pages-only delete orphaned page_tags rows, growing unbounded for cold tags).
        $cutoff = time() - $this->ttlSeconds;
        $this->db->query('DELETE FROM page_tags WHERE key IN (SELECT key FROM pages WHERE created_at < ?)', [$cutoff]);
        $this->db->query('DELETE FROM pages WHERE created_at < ?', [$cutoff]);
        // Row cap: evict the oldest pages, so a flood of unique query strings replaces
        // older entries instead of growing the file. Set-based, like the prune above: a
        // cache already far over the cap (an upgrade, a lowered max_pages) trims in two
        // statements, not two per row.
        $excess = (int) $this->db->one('SELECT COUNT(*) c FROM pages')['c'] - $this->maxPages;
        if ($excess > 0) {
            $oldest = 'SELECT key FROM pages ORDER BY created_at, rowid LIMIT ?';
            $this->db->query("DELETE FROM page_tags WHERE key IN ({$oldest})", [$excess]);
            $this->db->query("DELETE FROM pages WHERE key IN ({$oldest})", [$excess]);
        }
    }

    /** @param string[] $tables written tables → purge every page tagged with any of them */
    public function purgeByTables(array $tables): void
    {
        foreach ($tables as $t) {
            foreach ($this->db->all('SELECT key FROM page_tags WHERE tag = ?', [$t]) as $row) {
                $this->forget($row['key']);
            }
        }
    }

    private function forget(string $key): void
    {
        $this->db->query('DELETE FROM pages WHERE key = ?', [$key]);
        $this->db->query('DELETE FROM page_tags WHERE key = ?', [$key]);
    }
}
