<?php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE oauth_identities (
            provider TEXT NOT NULL,
            provider_uid TEXT NOT NULL,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            PRIMARY KEY (provider, provider_uid)
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE oauth_identities'); }
};
