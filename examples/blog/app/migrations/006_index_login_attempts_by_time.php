<?php // app/migrations/006_index_login_attempts_by_time.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Kip\Auth compares julianday(attempted_at), because attempted_at carries a local
        // UTC offset and only instants compare correctly across DST. Plain column indexes
        // cannot serve that expression, and the throttle's (email = ? OR ip = ?) needs an
        // index leading on each column, so every throttle check and prune scanned the
        // table. These make each of them a SEARCH (SQLite 3.20+ for expression indexes).
        $db->query('DROP INDEX IF EXISTS idx_login_attempts_lookup');
        $db->query('CREATE INDEX idx_login_attempts_email_time ON login_attempts (email, julianday(attempted_at))');
        $db->query('CREATE INDEX idx_login_attempts_ip_time ON login_attempts (ip, julianday(attempted_at))');
        $db->query('CREATE INDEX idx_login_attempts_time ON login_attempts (julianday(attempted_at))');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX idx_login_attempts_time');
        $db->query('DROP INDEX idx_login_attempts_ip_time');
        $db->query('DROP INDEX idx_login_attempts_email_time');
        $db->query('CREATE INDEX idx_login_attempts_lookup ON login_attempts (email, ip, attempted_at)');
    }
};
