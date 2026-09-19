<?php // app/migrations/002_create_login_attempts.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT NOT NULL,
            attempted_at TEXT NOT NULL
        )');
        $db->query('CREATE INDEX idx_login_attempts_lookup ON login_attempts (email, ip, attempted_at)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE login_attempts'); }
};
