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
        $map = [];
        // glob() is array|false, and array_merge(false, ...) is a fatal TypeError.
        // Refuse rather than coerce: treating a failed listing as an empty one
        // would let migrate() report success against a schema it never touched,
        // and a single failed listing would apply a partial inventory as a
        // complete batch (outside review 2).
        $php = glob($this->dir . '/*.php');
        $sql = glob($this->dir . '/*.sql');
        if ($php === false || $sql === false) {
            throw new \RuntimeException(
                "Cannot list migrations in {$this->dir}: directory enumeration failed. "
                . 'Refusing to report an empty migration set, because that would '
                . 'record a partial batch as complete. The path above is exactly '
                . "what Migrator received: check that config.php's app_dir key "
                . 'resolves to a real directory (is_dir() must be true on it).'
            );
        }
        foreach (array_merge($php, $sql) as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
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
