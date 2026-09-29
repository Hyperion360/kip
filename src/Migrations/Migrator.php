<?php // src/Migrations/Migrator.php

declare(strict_types=1);
namespace Kip\Migrations;
use Kip\Database;

final class Migrator
{
    public const LEDGER_TABLE = '_migrations';

    /** @var list<string> every migrations directory, normalized at the boundary so consumers never cast */
    private array $dir;

    /** @param string|list<string> $dir one migrations directory, or several merged into ONE global ledger */
    public function __construct(private Database $db, string|array $dir)
    {
        $this->dir = is_array($dir) ? $dir : [$dir];
    }

    /** @return string[] names of migrations run */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = array_column($this->db->all('SELECT name FROM _migrations'), 'name');
        $batch = (int) ($this->db->one('SELECT MAX(batch) b FROM _migrations')['b'] ?? 0) + 1;
        $ran = [];
        foreach ($this->files() as $name => $file) {
            if (in_array($name, $done, true)) continue;
            // Transactional migration (SQLite DDL is transactional): a failure leaves
            // NOTHING applied and NOTHING recorded, no half-migrated schema.
            $entryDepth = $this->db->transactionDepth();
            $this->db->begin();
            try {
                $this->apply($file, 'up');
                $this->db->query('INSERT INTO _migrations (name, batch, run_at) VALUES (?, ?, ?)', [$name, $batch, date('c')]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBackToDepth($entryDepth); // mirrors App::process(): unwind nested savepoints too
                throw new \RuntimeException("Migration {$name} failed and was rolled back: {$e->getMessage()}", 0, $e);
            }
            $ran[] = $name;
        }
        return $ran;
    }

    /** Reverse the most recent batch, newest file first (review 4A). The WHOLE batch is
     *  atomic (red-team/data-migration review): a failure partway leaves the batch fully
     *  applied and fully recorded, no half-rolled-back state.
     *  @return string[] */
    public function rollback(): array
    {
        $this->ensureTable();
        $batch = $this->db->one('SELECT MAX(batch) b FROM ' . self::LEDGER_TABLE)['b'] ?? null;
        if ($batch === null) return [];
        $files = $this->files();
        $rows = $this->db->all('SELECT name FROM ' . self::LEDGER_TABLE . ' WHERE batch = ? ORDER BY name DESC', [$batch]);
        $reversed = [];
        $entryDepth = $this->db->transactionDepth();
        $this->db->begin();
        try {
            foreach ($rows as $row) {
                $file = $files[$row['name']] ?? throw new \RuntimeException("Migration file for {$row['name']} not found in {$this->dirsLabel()}");
                $this->apply($file, 'down');
                $this->db->query('DELETE FROM ' . self::LEDGER_TABLE . ' WHERE name = ?', [$row['name']]);
                $reversed[] = $row['name'];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBackToDepth($entryDepth); // mirrors App::process(): unwind nested savepoints too
            throw new \RuntimeException('Rollback of the batch failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }
        return $reversed;
    }

    /** @return array<string,string> name (no extension, ledger-compatible) => absolute path, sorted by name */
    private function files(): array
    {
        // DELIBERATE, per directory: no migrations directory means nothing to run from
        // it, so it is skipped and an app (or a deleted feature folder) without
        // migrations keeps working. Anything else that stops a directory being listed
        // is refused, because treating a failed listing as an empty one would let
        // migrate() report success against a schema it never touched, and one partial
        // listing would apply an incomplete inventory as a complete batch.
        //
        // scandir() rather than glob(): glob() reads the directory path itself as a
        // pattern, so a real path containing [ ] * ? silently listed nothing, and it
        // reported an unreadable directory as empty unless given GLOB_ERR, whose handling
        // of a missing directory differs between C libraries.
        $map = [];
        foreach ($this->dir as $dir) {
            if (!file_exists($dir) && !is_link($dir)) continue; // a dangling link is broken config, not absence
            error_clear_last();
            $entries = is_dir($dir) ? @scandir($dir) : false;
            if ($entries === false) {
                $why = error_get_last()['message'] ?? 'not a directory';
                throw new \RuntimeException(
                    "Cannot list migrations in {$dir}: {$why}. "
                    . 'Refusing to report an empty migration set, because that would '
                    . 'record a partial batch as complete. Check that this path is a '
                    . 'directory readable by the PHP process.'
                );
            }

            foreach ($entries as $entry) {
                if ($entry[0] === '.') continue;                  // dotfiles, . and .., as glob('*.sql') skipped them
                $ext = pathinfo($entry, PATHINFO_EXTENSION);
                if ($ext !== 'php' && $ext !== 'sql') continue;
                $file = $dir . '/' . $entry;
                if (!is_file($file)) {
                    // A directory or a dangling symlink named like a migration: skipping it
                    // would apply an incomplete batch and report success.
                    throw new \RuntimeException(
                        "{$entry} in {$dir} is named like a migration but is not a regular "
                        . 'file (a directory, or a symlink whose target is missing). Rename or remove it.'
                    );
                }
                $name = pathinfo($entry, PATHINFO_FILENAME);
                // The ledger keys on names, not paths: the same name twice (a .php and a
                // .sql, or two directories, feature folders included) is a collision.
                if (isset($map[$name])) {
                    throw new \RuntimeException("Migration name collision: {$name} exists as both {$map[$name]} and {$file}");
                }
                $map[$name] = $file;
            }
        }
        // The NNN prefixes define ONE global order across all directories, so one
        // ksort over the merged map, never a per-directory sort.
        ksort($map, SORT_STRING);
        return $map;
    }

    /** Every migration directory in one label, so no message interpolates the array property (review P2-3). */
    private function dirsLabel(): string
    {
        return implode(', ', $this->dir);
    }

    private function apply(string $file, string $direction): void
    {
        if (str_ends_with($file, '.php')) {
            (require $file)->{$direction}($this->db);
            return;
        }
        $sql = $this->sqlSection($file, $direction);
        if ($sql === '') {
            throw new \RuntimeException(basename($file) . " has no \"-- {$direction}\" section");
        }
        $this->db->exec($sql);
    }

    /** Split "-- up" / "-- down" sections; "-- up" is mandatory, "-- down" optional (but required to roll back).
     *  Only blank lines and `--` comments may precede the marker, stray SQL above it is an
     *  error, not silently skipped (data-migration review). */
    private function sqlSection(string $file, string $direction): string
    {
        $raw = (string) file_get_contents($file);
        if (!preg_match('/\A\s*(?:--[^\n]*\n\s*)*^--\s*up\s*$(?<up>.*?)(?:^--\s*down\s*$(?<down>.*))?\z/msi', $raw, $m)) {
            throw new \RuntimeException(basename($file) . ': expected an "-- up" marker (and optionally "-- down"); only comments may precede it');
        }
        return trim($m[$direction] ?? '');
    }

    private function ensureTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . self::LEDGER_TABLE . ' (name TEXT PRIMARY KEY, batch INTEGER NOT NULL, run_at TEXT NOT NULL)');
    }
}
