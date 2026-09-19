<?php // app/migrations/005_add_comments_post_id_index.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE INDEX idx_comments_post_id ON comments (post_id, created_at, id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP INDEX idx_comments_post_id'); }
};
