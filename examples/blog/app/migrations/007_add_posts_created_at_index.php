<?php // app/migrations/007_add_posts_created_at_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The listing orders by created_at DESC, id DESC. SQLite appends the rowid to
        // every index entry, so this one column covers the id tie-break too.
        $db->query('CREATE INDEX idx_posts_created_at ON posts (created_at)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP INDEX idx_posts_created_at'); }
};
