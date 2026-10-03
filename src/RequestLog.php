<?php // src/RequestLog.php

declare(strict_types=1);
namespace Kip;

use Kip\Http\Request;

final class RequestLog
{
    public function __construct(private Database $db, private int $retentionDays = 30)
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            method TEXT NOT NULL,
            path TEXT NOT NULL,
            status INTEGER NOT NULL,
            duration_ms REAL NOT NULL,
            ip TEXT NOT NULL,
            user_id INTEGER
        )');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_requests_retention ON requests(julianday(created_at))');
    }

    /** Best-effort: a logging failure must never break the response. */
    public function log(Request $request, int $status, ?int $userId, float $durationMs, ?string $pathOverride = null): void
    {
        try {
            // Strips full OSC escape sequences (ESC ] ... BEL) as a unit, then any
            // remaining stray control bytes (incl. an unterminated ESC, NUL, DEL).
            $path = preg_replace('/\x1b\][^\x07]*\x07|[\x00-\x1F\x7F]/', '', $pathOverride ?? $request->path);
            // The reset link arrives by email, so its path carries a live, single-use
            // credential from click until form submit. Redact the token at write time,
            // same discipline as the control-byte strip; the viewer renders what the
            // audit row holds, so this also cleans the admin logs UI.
            if (preg_match('~^/auth/reset/[0-9a-fA-F]{64}$~', $path) === 1) {
                $path = '/auth/reset/<redacted>';
            }
            $this->db->query(
                'INSERT INTO requests (created_at, method, path, status, duration_ms, ip, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [date('c'), $request->method, $path, $status, $durationMs, $request->ip, $userId]
            );
        } catch (\Throwable $e) {
            error_log('RequestLog write failed: ' . $e->getMessage());
        }
    }

    /** @return list<array<array-key, mixed>> */
    public function recent(int $limit): array
    {
        return $this->db->all('SELECT * FROM requests ORDER BY id DESC LIMIT ?', [$limit]);
    }

    /**
     * One page of audit rows for the admin logs viewer, newest first, read
     * only. Keyset window: 'before' and 'after' are exclusive id bounds, so
     * the plan is an INTEGER PRIMARY KEY range seek for every filter shape
     * (no SCAN, no TEMP B-TREE; pinned by test). With no 'before' cursor the
     * bound is an inclusive `id <= PHP_INT_MAX`, so even a max-id row stays
     * visible on the first page.
     *
     * The caller passes its page size plus one (the probe, browse()'s
     * convention). The returned list holds at most that many rows: the page
     * itself, and, when one exists, exactly one extra row just beyond the
     * page in the direction away from the cursor (older rows for a forward
     * window, newer rows for an 'after' window). That extra row is how the
     * caller proves another page exists without a second query.
     *
     * @param array{method?:string,status?:int,path?:string,user_id?:int,guests?:bool,before?:int,after?:int} $f
     * @return list<array<array-key, mixed>>
     */
    public function page(array $f = [], int $limit = 50): array
    {
        $limit = max(1, $limit);
        $where = [];
        $params = [];
        if (($f['method'] ?? '') !== '') {
            $where[] = 'method = ?';
            $params[] = $f['method'];
        }
        if (isset($f['status'])) {
            $where[] = 'status >= ? AND status < ?';
            $params[] = $f['status'] * 100;
            $params[] = ($f['status'] + 1) * 100;
        }
        if (($f['path'] ?? '') !== '') {
            // Control bytes go first: LIKE stops at an embedded NUL, which would
            // silently shorten the prefix (log() strips the same bytes on write).
            // Then the LIKE metacharacters are escaped so input stays a literal prefix.
            $path = (string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $f['path']);
            $where[] = "path LIKE ? ESCAPE '\\'";
            $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path) . '%';
        }
        if ($f['guests'] ?? false) {
            $where[] = 'user_id IS NULL';
        } elseif (isset($f['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $f['user_id'];
        }
        if (isset($f['after'])) {
            // Older-to-newer fetch, then reverse: rows come back newest first and
            // the page sits flush against the cursor. Slice BEFORE reversing;
            // reversing the probe row too would display the wrong page.
            $where[] = 'id > ?';
            $params[] = $f['after'];
            $rows = $this->db->all(
                'SELECT * FROM requests WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT ?',
                [...$params, $limit]
            );
            $page = array_slice($rows, 0, $limit - 1);
            $newestFirst = array_reverse($page);
            if (isset($rows[$limit - 1])) {
                $newestFirst[] = $rows[$limit - 1]; // the probe: one row newer than the page
            }
            return $newestFirst;
        }
        if (isset($f['before'])) {
            $where[] = 'id < ?';
            $params[] = $f['before'];
        } else {
            $where[] = 'id <= ?';
            $params[] = PHP_INT_MAX;
        }
        return $this->db->all(
            'SELECT * FROM requests WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ?',
            [...$params, $limit]
        );
    }

    /** @return int rows removed */
    public function prune(int $days): int
    {
        // julianday(): instant compare. Date('c') strings carry local offsets that skew lexicographically across DST.
        $stmt = $this->db->query('DELETE FROM requests WHERE julianday(created_at) < julianday(?)', [date('c', time() - $days * 86400)]);
        return $stmt->rowCount();
    }

    /** Prune to the configured retention. @return int rows removed */
    public function pruneToRetention(): int
    {
        return $this->prune($this->retentionDays);
    }
}
