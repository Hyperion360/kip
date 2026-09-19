<?php // skeleton/app/migrations/006_add_login_attempts_kind.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Split throttle buckets: login failures and password-reset requests count
        // separately once this column exists (see Auth::resetThrottled).
        $db->query("ALTER TABLE login_attempts ADD COLUMN kind TEXT NOT NULL DEFAULT 'login'");
    }
    public function down(Kip\Database $db): void
    {
        if (version_compare($db->one('SELECT sqlite_version() AS v')['v'] ?? '0', '3.35', '<')) {
            throw new RuntimeException('DROP COLUMN needs SQLite >= 3.35, remove login_attempts.kind manually on this host');
        }
        $db->query('ALTER TABLE login_attempts DROP COLUMN kind');
    }
};
