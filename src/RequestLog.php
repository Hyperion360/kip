<?php // src/RequestLog.php
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
    }

    /** Best-effort: a logging failure must never break the response. */
    public function log(Request $request, int $status, ?int $userId, float $durationMs, ?string $pathOverride = null): void
    {
        try {
            // Strips full OSC escape sequences (ESC ] ... BEL) as a unit, then any
            // remaining stray control bytes (incl. an unterminated ESC, NUL, DEL).
            $path = preg_replace('/\x1b\][^\x07]*\x07|[\x00-\x1F\x7F]/', '', $pathOverride ?? $request->path);
            $this->db->query(
                'INSERT INTO requests (created_at, method, path, status, duration_ms, ip, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [date('c'), $request->method, $path, $status, $durationMs, $request->ip, $userId]
            );
        } catch (\Throwable $e) {
            error_log('RequestLog write failed: ' . $e->getMessage());
        }
    }

    public function recent(int $limit): array
    {
        return $this->db->all('SELECT * FROM requests ORDER BY id DESC LIMIT ?', [$limit]);
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
