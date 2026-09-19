<?php // src/Backup.php
namespace Kip;

/**
 * Online-safe SQLite backups: VACUUM INTO snapshots each database without
 * blocking writers (safe under WAL), then zips the copies. The "$5 VPS
 * appliance" battery. Restoring is unzipping files back into place.
 */
final class Backup
{
    /** @param array<string,string> $dsns label => DSN (non-sqlite entries are skipped) */
    public function __construct(private array $dsns, private string $dir, private int $keepDays = 14) {}

    /** @return string path to the produced archive (zip, or a directory when ext-zip is absent) */
    public function run(string $stamp): string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create backup directory {$this->dir}");
        }
        $copies = [];
        foreach ($this->dsns as $label => $dsn) {
            if (!str_starts_with($dsn, 'sqlite:') || str_contains($dsn, ':memory:')) continue;
            $tmp = "{$this->dir}/{$label}-{$stamp}.sqlite";
            // Path comes from config, never request input; quote for the SQL literal anyway.
            (new Database($dsn))->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
            $copies[$label] = $tmp;
        }
        if ($copies === []) {
            throw new \RuntimeException('No SQLite databases configured, nothing to back up.');
        }
        if (!class_exists(\ZipArchive::class)) {
            $this->pruneOldArchives(); // loose copies too. No-zip hosts must not accumulate full DB snapshots
            return $this->dir; // plain copies left in place, documented fallback
        }
        $zipPath = "{$this->dir}/kip-backup-{$stamp}.zip";
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create {$zipPath}");
        }
        foreach ($copies as $label => $file) {
            // addFile defers reading to close(): both returns must be checked or a corrupt
            // archive can be reported as success (red-team review).
            if (!$zip->addFile($file, "{$label}.sqlite")) {
                throw new \RuntimeException("Cannot add {$file} to the backup archive");
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException("Could not finalize {$zipPath}. The loose .sqlite copies were left in place for manual recovery");
        }
        foreach ($copies as $file) unlink($file);
        $this->pruneOldArchives();
        return $zipPath;
    }

    /** Nightly-cron safety: archives (and no-zip loose copies) older than keepDays are deleted so the disk cannot fill. */
    private function pruneOldArchives(): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (array_merge(glob($this->dir . '/kip-backup-*.zip') ?: [], glob($this->dir . '/*-*.sqlite') ?: []) as $old) {
            if (is_file($old) && filemtime($old) < $cutoff) @unlink($old);
        }
    }
}
