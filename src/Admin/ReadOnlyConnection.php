<?php // src/Admin/ReadOnlyConnection.php

declare(strict_types=1);
namespace Kip\Admin;

/**
 * A second, engine-enforced read-only PDO handle to the app's SQLite file.
 * This is the only database surface the SQL browser pages and `kip db` read
 * through. Read-only-ness is NOT parser-enforced: the open carries both the
 * URI form (?mode=ro) and the SQLITE_OPEN_READONLY flag, so SQLite itself
 * refuses every write no matter what statement is issued.
 *
 * Why both: a filesystem path is also URI syntax, so a path containing '#'
 * or '%' would otherwise change what SQLite opens (a '#' starts a fragment
 * and silently drops ?mode=ro, reopening the file WRITABLE; verified). The
 * path is percent-encoded for the URI half, and the open flag holds the
 * read-only guarantee even where URI parsing would fail. php-src adds
 * SQLITE_OPEN_URI on every build except under open_basedir, where the driver
 * rejects the open outright; either way no writable handle can appear.
 *
 * No canary write runs here: a same-value PRAGMA user_version assignment is
 * still a real write transaction (it bumps the header change counter), so
 * probing writability at runtime would risk the very writes this class
 * exists to prevent. The refusals are pinned by tests instead.
 */
final class ReadOnlyConnection
{
    /** Binary operators a data-view filter may use; anything else is rejected before SQL runs. */
    public const OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE'];

    private \PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new \PDO('sqlite:file:' . self::uriPath($path) . '?mode=ro', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::SQLITE_ATTR_OPEN_FLAGS  => \PDO::SQLITE_OPEN_READONLY,
        ]);
        // A checkpoint-time BUSY on a RO handle is rare, but the pragma is one
        // line: this raw open stays aligned with Database's bounded wait
        // instead of drifting behind driver defaults. busy_timeout is
        // per-connection state, so setting it needs no write.
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    /**
     * URI-encode the three bytes that are also URI syntax in a filesystem
     * path. Slashes, dots, and multibyte UTF-8 stay literal: SQLite passes
     * them through to the VFS unchanged.
     */
    private static function uriPath(string $path): string
    {
        return str_replace(['%', '#', '?'], ['%25', '%23', '%3F'], $path);
    }

    /** The plain file path of a file-backed sqlite DSN, or null (memory, other drivers, URI-parameter DSNs). */
    public static function pathFromDsn(string $dsn): ?string
    {
        if (!str_starts_with($dsn, 'sqlite:')) return null;
        $path = substr($dsn, strlen('sqlite:'));
        if ($path === '' || $path === ':memory:' || str_contains($path, '?')) return null;
        return $path;
    }

    /** A connection for the given DSN, or null when the DSN is not a file-backed SQLite database. */
    public static function fromDsn(string $dsn): ?self
    {
        $path = self::pathFromDsn($dsn);
        return $path === null ? null : new self($path);
    }

    /** @return list<string> every table except SQLite internals; the migration ledger stays visible */
    public function tables(): array
    {
        return array_column($this->select(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
            . " AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"
        ), 'name');
    }

    /** @return list<array<array-key, mixed>> PRAGMA table_info rows */
    public function columns(string $table): array
    {
        return $this->select("PRAGMA table_info(\"{$table}\")");
    }

    public function count(string $table): int
    {
        return (int) $this->select("SELECT COUNT(*) c FROM \"{$table}\"")[0]['c'];
    }

    /** The CREATE statement from sqlite_master, or null when the table has none recorded. */
    public function createSql(string $table): ?string
    {
        $rows = $this->select('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = ?', [$table]);
        $sql = $rows[0]['sql'] ?? null;
        return is_string($sql) ? $sql : null;
    }

    /**
     * Run one statement through prepare()+execute() only: PDO sqlite executes
     * exactly the first statement of a string and never the tail (pinned by
     * test), and prepare() is where that guarantee lives. The kip db surface.
     *
     * @param array<array-key, mixed> $params
     * @return list<array<array-key, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * The data view's page query. Every input is structurally validated, so
     * no request text ever reaches the SQL string: the column must be one of
     * the table's own PRAGMA-reported names (password_hash excluded, it
     * would turn row visibility into a hash-extraction oracle), the operator
     * must be one of OPERATORS, and the value rides a bound parameter. The
     * table name comes through AdminController::deny(), whose Schema
     * whitelist already proved it is a real table.
     *
     * @return list<array<array-key, mixed>>
     */
    public function rows(string $table, ?string $col, string $op, string $val, int $limit, int $offset): array
    {
        $filtered = false;
        if ($col !== null) {
            $known = [];
            foreach ($this->columns($table) as $c) {
                if ($c['name'] !== 'password_hash') $known[] = (string) $c['name'];
            }
            if (!in_array($col, $known, true)) {
                // Deliberately no input in the message: the data view returns it
                // in a bare 422 body, outside any escaped template.
                throw new \InvalidArgumentException('Unknown filter column (not a column of this table, or excluded from filtering)');
            }
            if (!in_array($op, self::OPERATORS, true)) {
                throw new \InvalidArgumentException('Unknown filter operator (allowed: ' . implode(', ', self::OPERATORS) . ')');
            }
            $filtered = $val !== '';
        }
        $sql = 'SELECT rowid AS __rid, * FROM "' . $table . '"'
            . ($filtered ? " WHERE \"{$col}\" {$op} ?" : '')
            . ' ORDER BY rowid DESC LIMIT ? OFFSET ?';
        $params = $filtered ? [$val, $limit, $offset] : [$limit, $offset];
        return $this->select($sql, $params);
    }

    /**
     * The kip db output block: a header of column names, tab-separated rows,
     * every control byte escaped so a stored value can neither break the TSV
     * shape nor inject terminal controls. NULL stays distinguishable from an
     * empty string.
     *
     * @param list<array<array-key, mixed>> $rows
     */
    public static function tsv(array $rows): string
    {
        if ($rows === []) return "(0 rows)\n";
        $out = implode("\t", array_map(self::tsvCell(...), array_keys($rows[0]))) . "\n";
        foreach ($rows as $row) {
            $out .= implode("\t", array_map(self::tsvCell(...), $row)) . "\n";
        }
        return $out;
    }

    /** @param array-key|mixed $value */
    private static function tsvCell(mixed $value): string
    {
        $s = match (true) {
            $value === null => 'NULL',
            is_string($value) => $value,
            is_int($value) || is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => '(binary)', // BLOBs and anything a driver handed back as a non-scalar
        };
        $escaped = preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            static function (array $m): string {
                return match ($m[0]) {
                    "\t" => '\\t',
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\x00" => '\\0',
                    default => sprintf('\\x%02x', ord($m[0])),
                };
            },
            $s
        );
        return $escaped ?? $s;
    }
}
