<?php // skeleton/app/migrations/005_add_password_resets_token_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // resetPassword() looks rows up by token_hash on an unauthenticated endpoint.
        // give it an index, and let uniqueness enforce one-live-token at the storage layer.
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets (token_hash)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP INDEX IF EXISTS idx_password_resets_token');
    }
};
