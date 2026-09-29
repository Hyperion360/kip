<?php // tests/Admin/ReadOnlyConnectionTest.php
namespace Kip\Tests\Admin;
use Kip\Admin\ReadOnlyConnection;
use PHPUnit\Framework\TestCase;

/**
 * The read-only handle behind the SQL browser and kip db. Read-only-ness is
 * engine-enforced (SQLITE_OPEN_READONLY open flag on a mode=ro URI DSN), and
 * every layer of that open is pinned here: writes refused, weird filenames
 * still target the exact file, missing files never materialize, and a
 * multi-statement string executes only its first statement.
 */
final class ReadOnlyConnectionTest extends TestCase
{
    /** @var list<string> files to unlink in tearDown (plus -wal/-shm siblings) */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            foreach ([$f, $f . '-wal', $f . '-shm'] as $candidate) @unlink($candidate);
        }
    }

    /** Creates a writable SQLite database at $path with t(i) holding one row. */
    private function makeDb(string $path): \PDO
    {
        $this->files[] = $path;
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE t (i INTEGER)');
        $pdo->exec("INSERT INTO t VALUES (1), (2), (3)");
        return $pdo;
    }

    private function tempPath(string $suffix = '.sqlite'): string
    {
        $path = sys_get_temp_dir() . '/kip-ro-' . bin2hex(random_bytes(4)) . $suffix;
        $this->files[] = $path;
        return $path;
    }

    public function test_select_reads_rows(): void
    {
        $path = $this->tempPath();
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        $this->assertSame([[ 'i' => 1 ], [ 'i' => 2 ], [ 'i' => 3 ]], $ro->select('SELECT i FROM t ORDER BY i'));
    }

    public function test_write_statements_are_refused_by_the_database(): void
    {
        $path = $this->tempPath();
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        foreach (
            [
                'CREATE TABLE x(i)' => 'CREATE TABLE x (i INTEGER)',
                'INSERT' => 'INSERT INTO t VALUES (9)',
                'UPDATE' => 'UPDATE t SET i = 9',
                'DELETE' => 'DELETE FROM t',
                'DROP' => 'DROP TABLE t',
            ] as $label => $sql
        ) {
            try {
                $ro->select($sql);
                $this->fail("{$label} was allowed through the read-only handle");
            } catch (\PDOException $e) {
                $this->assertStringContainsString('readonly', $e->getMessage(), $label);
            }
        }
        $check = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(3, (int) $check->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    /** A '#' in the path would make SQLite drop the ?mode=ro fragment; encoding plus the open flag must both hold. */
    public function test_fragment_named_database_opens_exact_file_read_only(): void
    {
        $path = $this->tempPath('#report.sqlite');
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        $this->assertSame([[ 'i' => 2 ]], $ro->select('SELECT i FROM t WHERE i = 2'));
        $this->expectException(\PDOException::class);
        $ro->select('INSERT INTO t VALUES (9)');
    }

    public function test_percent_named_database_opens_exact_file_read_only(): void
    {
        $path = $this->tempPath('100%done.sqlite');
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        $this->assertSame(3, (int) $ro->select('SELECT COUNT(*) c FROM t')[0]['c']);
        $this->expectException(\PDOException::class);
        $ro->select('DELETE FROM t');
    }

    public function test_missing_file_refuses_to_open_and_is_never_created(): void
    {
        $path = $this->tempPath();
        try {
            new ReadOnlyConnection($path);
            $this->fail('a missing database must refuse to open');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('unable to open', $e->getMessage());
        }
        $this->assertFileDoesNotExist($path);
    }

    public function test_wal_database_reads_through_the_read_only_handle(): void
    {
        $path = $this->tempPath();
        $writer = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $writer->exec('PRAGMA journal_mode = WAL');
        $writer->exec('CREATE TABLE t (i INTEGER)');
        $writer->exec("INSERT INTO t VALUES (1)");

        $ro = new ReadOnlyConnection($path);
        $writer->exec('INSERT INTO t VALUES (2)'); // committed after the read-only open
        $this->assertSame(2, (int) $ro->select('SELECT COUNT(*) c FROM t')[0]['c']);
    }

    public function test_path_from_dsn_accepts_only_file_backed_sqlite(): void
    {
        $abs = '/tmp/app/data.sqlite';
        $this->assertSame($abs, ReadOnlyConnection::pathFromDsn('sqlite:' . $abs));
        $this->assertSame('app/data.sqlite', ReadOnlyConnection::pathFromDsn('sqlite:app/data.sqlite'));
        $this->assertNull(ReadOnlyConnection::pathFromDsn('sqlite::memory:'));
        $this->assertNull(ReadOnlyConnection::pathFromDsn('mysql:host=localhost;dbname=x'));
        $this->assertNull(ReadOnlyConnection::pathFromDsn('sqlite:/tmp/x.sqlite?mode=ro'), 'a DSN already carrying URI parameters is not a plain file-backed DSN');
        $this->assertNull(ReadOnlyConnection::pathFromDsn('sqlite:'));
    }

    public function test_from_dsn_builds_a_connection_or_null(): void
    {
        $path = $this->tempPath();
        $this->makeDb($path);
        $ro = ReadOnlyConnection::fromDsn('sqlite:' . $path);
        $this->assertNotNull($ro);
        $this->assertSame(3, (int) $ro->count('t'));
        $this->assertNull(ReadOnlyConnection::fromDsn('sqlite::memory:'));
    }

    public function test_tables_keeps_the_ledger_and_excludes_internals(): void
    {
        $path = $this->tempPath();
        $pdo = $this->makeDb($path);
        $pdo->exec('CREATE TABLE _migrations (name TEXT)');
        $ro = new ReadOnlyConnection($path);
        // The operator view: the migration ledger stays visible, sqlite internals do not.
        $this->assertSame(['_migrations', 't'], $ro->tables());
    }

    public function test_columns_count_and_create_sql(): void
    {
        $path = $this->tempPath();
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        $cols = $ro->columns('t');
        $this->assertSame('i', $cols[0]['name']);
        $this->assertSame('INTEGER', strtoupper((string) $cols[0]['type']));
        $this->assertSame(3, $ro->count('t'));
        $this->assertStringContainsString('CREATE TABLE t', (string) $ro->createSql('t'));
    }

    public function test_rows_applies_a_fixed_filter_with_a_bound_value(): void
    {
        $path = $this->tempPath();
        $pdo = $this->makeDb($path);
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, password_hash TEXT)');
        $pdo->exec("INSERT INTO posts (title, password_hash) VALUES ('Alpha', 'h'), ('Beta', 'h2')");
        $ro = new ReadOnlyConnection($path);

        $rows = $ro->rows('posts', 'title', '=', 'Alpha', 51, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Alpha', $rows[0]['title']);
        $this->assertArrayHasKey('__rid', $rows[0]);

        $like = $ro->rows('posts', 'title', 'LIKE', 'A%', 51, 0); // the admin types the wildcard
        $this->assertCount(1, $like);

        $noFilter = $ro->rows('posts', null, '', '', 51, 0);
        $this->assertCount(2, $noFilter);

        $emptyValue = $ro->rows('posts', 'title', '=', '', 51, 0); // empty value means no filter
        $this->assertCount(2, $emptyValue);
    }

    public function test_rows_rejects_invalid_filter_parts_before_sql_runs(): void
    {
        $path = $this->tempPath();
        $pdo = $this->makeDb($path);
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, password_hash TEXT)');
        $ro = new ReadOnlyConnection($path);

        try {
            $ro->rows('posts', 'title; DROP TABLE posts', '=', 'x', 51, 0); // not a column of the table
            $this->fail('an unknown column must be rejected before SQL runs');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $ro->rows('posts', 'title', '= ; DROP', 'x', 51, 0); // not an operator of the enum
    }

    public function test_rows_rejects_password_hash_as_a_filter_column(): void
    {
        $path = $this->tempPath();
        $pdo = $this->makeDb($path);
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, password_hash TEXT)');
        $ro = new ReadOnlyConnection($path);
        // Hash-filtering would turn row visibility into a hash-extraction oracle.
        try {
            $ro->rows('posts', 'password_hash', 'LIKE', 'a%', 51, 0);
            $this->fail('password_hash must not be a filter column');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $ro->rows('posts', 'nosuch', '=', 'x', 51, 0);
            $this->fail('an unknown column must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_filter_value_is_data_not_sql(): void // SQLi pin at the query-builder seam
    {
        $path = $this->tempPath();
        $pdo = $this->makeDb($path);
        $pdo->exec("INSERT INTO t VALUES (4)");
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO posts (title) VALUES ('plain'), ('other')");
        $ro = new ReadOnlyConnection($path);
        // The classic payload matches nothing literally; it can never widen the predicate.
        $this->assertSame([], $ro->rows('posts', 'title', '=', "x' OR 1=1 --", 51, 0));
        $this->assertSame([], $ro->rows('posts', 'title', '=', "plain' --", 51, 0));
        $this->assertCount(1, $ro->rows('posts', 'title', '=', 'plain', 51, 0));
    }

    public function test_only_the_first_statement_of_a_string_executes(): void
    {
        $path = $this->tempPath();
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        // A trailing DROP would throw 'readonly' if it were ever attempted; no
        // error and an intact table prove PDO sqlite ran only the first statement.
        $rows = $ro->select('SELECT COUNT(*) c FROM t; DROP TABLE t');
        $this->assertSame(3, (int) $rows[0]['c']);
        $this->assertSame(3, (int) $ro->count('t'));
    }

    public function test_load_extension_is_not_authorized(): void
    {
        $path = $this->tempPath();
        $this->makeDb($path);
        $ro = new ReadOnlyConnection($path);
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('not authorized');
        $ro->select("SELECT load_extension('/tmp/fake')");
    }

    public function test_tsv_renders_escaped_cells(): void
    {
        $this->assertSame("(0 rows)\n", ReadOnlyConnection::tsv([]));
        $out = ReadOnlyConnection::tsv([
            ['na' . "\t" . 'me' => "a\tb", 'n' => null, 'i' => 7, 'esc' => "\x1b[31m", 'nul' => "x\0y"],
        ]);
        $this->assertSame("na\\tme\tn\ti\tesc\tnul\na\\tb\tNULL\t7\t\\x1b[31m\tx\\0y\n", $out);
    }
}
