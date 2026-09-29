<?php // app/migrations/008_create_jobs.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Durable background jobs (Kip\Jobs, guide ch. 8 and 14). AUTOINCREMENT
        // keeps ids monotonic across pruning, so "oldest pending" (ORDER BY id)
        // stays a true FIFO even after old rows are deleted. payload is
        // nullable on purpose: a done job's payload is cleared, and the NOT NULL
        // would turn every successful acknowledgment into a constraint failure.
        $db->query("CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_class TEXT NOT NULL,
            payload TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            error TEXT,
            created_at TEXT NOT NULL,
            ran_at TEXT
        )");
        // The claim reads WHERE status = 'pending' ORDER BY id LIMIT 1; the
        // index leads on exactly that pair.
        $db->query('CREATE INDEX idx_jobs_status_id ON jobs (status, id)');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE jobs'); }
};
