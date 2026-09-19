<?php // skeleton/app/migrations/004_create_password_resets.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE password_resets (
            email TEXT PRIMARY KEY,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE password_resets'); }
};
