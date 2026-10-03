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
        // The cascade delete (Auth::logoutOtherBrowserSessions and account
        // deletion) seeks by user_id; without this index each delete scans.
        $db->query('CREATE INDEX IF NOT EXISTS idx_oauth_identities_user ON oauth_identities (user_id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE oauth_identities'); }
};
