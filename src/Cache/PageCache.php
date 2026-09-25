<?php // src/Cache/PageCache.php
namespace Kip\Cache;

use Kip\Database;
use Kip\Http\Response;

/** Full-page cache for anonymous GETs: SQLite store + table-tag purge (CDN Surrogate-Key model, v0.2). */
final class PageCache
{
    public function __construct(private Database $db, private int $ttlSeconds = 3600)
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS pages (
            key TEXT PRIMARY KEY, body TEXT NOT NULL, headers TEXT NOT NULL,
            etag TEXT NOT NULL, created_at INTEGER NOT NULL
        )');
        $this->db->query('CREATE TABLE IF NOT EXISTS page_tags (
            tag TEXT NOT NULL, key TEXT NOT NULL, PRIMARY KEY (tag, key)
        )');
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
        $key = $this->key($path, $query);
        $etag = '"' . hash('sha256', $response->body) . '"';
        $this->db->query('INSERT OR REPLACE INTO pages (key, body, headers, etag, created_at) VALUES (?, ?, ?, ?, ?)',
            [$key, $response->body, json_encode($response->headers), $etag, time()]);
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
