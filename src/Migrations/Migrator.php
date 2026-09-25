<?php // src/Migrations/Migrator.php
namespace Kip\Migrations;
use Kip\Database;

final class Migrator
{
    public const LEDGER_TABLE = '_migrations';

    public function __construct(private Database $db, private string $dir) {}

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
            $this->db->begin();
            try {
                $this->apply($file, 'up');
                $this->db->query('INSERT INTO _migrations (name, batch, run_at) VALUES (?, ?, ?)', [$name, $batch, date('c')]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
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
        $this->db->begin();
        try {
            foreach ($rows as $row) {
                $file = $files[$row['name']] ?? throw new \RuntimeException("Migration file for {$row['name']} not found in {$this->dir}");
                $this->apply($file, 'down');
                $this->db->query('DELETE FROM ' . self::LEDGER_TABLE . ' WHERE name = ?', [$row['name']]);
                $reversed[] = $row['name'];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Rollback of the batch failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }
        return $reversed;
    }

    /** @return array<string,string> name (no extension, ledger-compatible) => absolute path, sorted by name */
    private function files(): array
    {
        // DELIBERATE: no migrations directory means nothing to run, so this stays a no-op
        // and an app without migrations keeps working. Anything else that stops the
        // directory being listed is refused, because treating a failed listing as an
        // empty one would let migrate() report success against a schema it never touched,
        // and one partial listing would apply an incomplete inventory as a complete batch.
        //
        // scandir() rather than glob(): glob() reads the directory path itself as a
        // pattern, so a real path containing [ ] * ? silently listed nothing, and it
        // reported an unreadable directory as empty unless given GLOB_ERR, whose handling
        // of a missing directory differs between C libraries.
        if (!file_exists($this->dir)) return [];
        error_clear_last();
        $entries = is_dir($this->dir) ? @scandir($this->dir) : false;
        if ($entries === false) {
            $why = error_get_last()['message'] ?? 'not a directory';
            throw new \RuntimeException(
                "Cannot list migrations in {$this->dir}: {$why}. "
                . 'Refusing to report an empty migration set, because that would '
                . 'record a partial batch as complete. Check that this path is a '
                . 'directory readable by the PHP process.'
            );
        }

        $map = [];
        foreach ($entries as $entry) {
            if ($entry[0] === '.') continue;                  // dotfiles, . and .., as glob('*.sql') skipped them
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            if ($ext !== 'php' && $ext !== 'sql') continue;
            $file = $this->dir . '/' . $entry;
            if (!is_file($file)) continue;
            $name = pathinfo($entry, PATHINFO_FILENAME);
            if (isset($map[$name])) {
                throw new \RuntimeException("Migration name collision: {$name} exists as both .php and .sql");
            }
            $map[$name] = $file;
        }
        ksort($map, SORT_STRING);
        return $map;
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
