<?php // app/migrations/002_create_posts.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE posts'); }
};
