<?php // app/migrations/009_create_rate_limits.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Fixed-window rate limiting (Kip\RateLimit, guide ch. 6). One row per
        // (prefix, ip) per window: the counting upsert's ON CONFLICT target is
        // the primary key itself, so the lookup and the write are one seek.
        // window_start is a unix INTEGER, never a date string: the prune
        // compares it with a plain range seek (the login throttle's
        // julianday-on-column lesson, which defeated every index).
        $db->query("CREATE TABLE rate_limits (
            prefix TEXT NOT NULL,
            ip TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            hits INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (prefix, ip, window_start)
        )");
        // The prune retires expired windows across every key at once
        // (WHERE window_start < ?); the PK leads on prefix, so the range
        // needs this one to seek.
        $db->query('CREATE INDEX idx_rate_limits_window ON rate_limits (window_start)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE rate_limits'); }
};
