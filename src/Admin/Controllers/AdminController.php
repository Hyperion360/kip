<?php // src/Admin/Controllers/AdminController.php

declare(strict_types=1);
namespace Kip\Admin\Controllers;

use Kip\Admin\ReadOnlyConnection;
use Kip\Admin\Schema;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use Kip\Routing\Auth;
use Kip\Routing\Post;
use Kip\Session;
use Kip\View;

final class AdminController
{
    private const PER_PAGE = 50;
    private View $view;
    private Schema $schema;

    public function __construct(private Database $db, private Session $session, private Request $request)
    {
        // Framework-shipped views, deliberately NOT the app's container View,
        // whose root is the app's own views directory.
        $this->view = new View(dirname(__DIR__) . '/views');
        $this->schema = new Schema($db);
    }

    /**
     * Fail-closed, DEFAULT-DENY admin gate (eng-review D4: the single home for
     * all guard logic. Actions never inline these checks). #[Auth] guarantees
     * a logged-in session; this adds: is_admin must be EXPLICITLY 1, a missing
     * users table, missing column, missing row, or 0 all deny. When a table
     * name is given it must also pass the Schema whitelist.
     */
    private function deny(?string $table = null): ?Response
    {
        try {
            $row = $this->db->one('SELECT is_admin FROM users WHERE id = ?', [$this->session->get('user_id')]);
        } catch (\PDOException) {
            return new Response('Admin requires a users table with an is_admin column: see the admin guide chapter.', 403);
        }
        if ((int) ($row['is_admin'] ?? 0) !== 1) return new Response('Forbidden', 403);
        if ($table !== null && !$this->schema->has($table)) return new Response('Unknown table', 404);
        return null;
    }

    /**
     * Fetch one row by SQLite rowid (eng-review D2: admin URLs route by rowid,
     * never by PK value. An email or other non-slug PK would fail the router's
     * [a-z0-9_-] whitelist). WITHOUT ROWID tables are unsupported (documented).
     * @return array<array-key, mixed>|null
     */
    private function row(string $table, string $rid): ?array
    {
        return $this->db->one("SELECT rowid AS __rid, * FROM \"{$table}\" WHERE rowid = ?", [$rid]);
    }

    #[Auth]
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $tables = [];
        foreach ($this->schema->tables() as $t) {
            $tables[$t] = $this->schema->count($t);
        }
        return $this->view->render('index', ['tables' => $tables, 'title' => 'Tables']);
    }

    #[Auth]
    public function browse(string $table): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        $page = max(1, (int) $this->request->str('page'));
        $rows = $this->db->all(
            "SELECT rowid AS __rid, * FROM \"{$table}\" ORDER BY rowid DESC LIMIT ? OFFSET ?",   // n+1 probe, PostsController convention
            [self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE]
        );
        $hasNext = count($rows) > self::PER_PAGE;
        return $this->view->render('browse', [
            'table' => $table,
            'columns' => $this->schema->columns($table),
            'rows' => array_slice($rows, 0, self::PER_PAGE),
            'page' => $page,
            'hasNext' => $hasNext,
            'csrf' => $this->session->csrfToken(),
            'title' => "Browse {$table}",
        ]);
    }

    /**
     * SQL browser section (read-only by database enforcement, not by parsing):
     * every content read below goes through a SECOND PDO handle SQLite opens
     * read-only (ReadOnlyConnection). deny()/Schema stay the gate, exactly as
     * for the CRUD routes above; only the reading surface differs.
     */

    /** The browser's read-only connection, or null when the app DB is not a file-backed SQLite database. */
    private function browser(): ?ReadOnlyConnection
    {
        return ReadOnlyConnection::fromDsn($this->db->dsn());
    }

    private static function needsFileBackedDb(): Response
    {
        return new Response('The SQL browser needs a file-backed SQLite database (config db.dsn).', 501);
    }

    #[Auth]
    public function sql(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $ro = $this->browser();
        if ($ro === null) return self::needsFileBackedDb();
        $tables = [];
        // The panel view of the world: the migration ledger is machinery, not content.
        foreach (array_diff($ro->tables(), [Migrator::LEDGER_TABLE]) as $t) {
            $tables[$t] = $ro->count($t);
        }
        return $this->view->render('sql', ['tables' => $tables, 'title' => 'SQL browser']);
    }

    #[Auth]
    public function schema(string $table): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        $ro = $this->browser();
        if ($ro === null) return self::needsFileBackedDb();
        return $this->view->render('schema', [
            'table' => $table,
            'columns' => $ro->columns($table),
            'create' => $ro->createSql($table),
            'count' => $ro->count($table),
            'title' => "Schema {$table}",
        ]);
    }

    #[Auth]
    public function data(string $table): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        $ro = $this->browser();
        if ($ro === null) return self::needsFileBackedDb();
        $page = min(max(1, (int) $this->request->str('page')), 1000000); // clamp: (PHP_INT_MAX-1)*50 would bind a float
        $col = $this->request->str('col');
        $op = $this->request->str('op');
        $val = $this->request->str('val');
        try {
            $rows = $ro->rows($table, $col === '' ? null : $col, $op, $val, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);
        } catch (\InvalidArgumentException $e) {
            return new Response($e->getMessage(), 422);
        }
        $hasNext = count($rows) > self::PER_PAGE;
        // password_hash never appears as a filter option (a hash filter would
        // turn row visibility into a hash-extraction oracle).
        $filterColumns = array_values(array_filter(
            $ro->columns($table),
            static fn(array $c): bool => $c['name'] !== 'password_hash'
        ));
        return $this->view->render('data', [
            'table' => $table,
            'columns' => $ro->columns($table),
            'rows' => array_slice($rows, 0, self::PER_PAGE),
            'page' => $page,
            'hasNext' => $hasNext,
            'col' => $col,
            'op' => $op,
            'val' => $val,
            'filterColumns' => $filterColumns,
            'operators' => ReadOnlyConnection::OPERATORS,
            'title' => "Data {$table}",
        ]);
    }

    /** Zero-JS two-step delete: the browse link lands here, the confirm form re-POSTs to delete(). */
    #[Auth]
    public function confirmdelete(string $table, string $rid): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        if ($this->row($table, $rid) === null) return new Response('Row not found', 404);
        return $this->view->render('confirm', [
            'table' => $table, 'rid' => $rid,
            'csrf' => $this->session->csrfToken(),
            'title' => "Delete {$table} row {$rid}?",
        ]);
    }

    #[Auth]
    public function create(string $table): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        return $this->view->render('form', [
            'table' => $table, 'record' => [], 'columns' => $this->editable($table),
            'action' => "/admin/store/{$table}", 'title' => "New {$table} row",
            'csrf' => $this->session->csrfToken(),
        ]);
    }

    #[Auth] #[Post]
    public function store(string $table): Response
    {
        if ($r = $this->deny($table)) return $r;
        [$data, $error] = $this->formData($table, creating: true);
        if ($error !== null) return new Response($error, 422);
        if ($data === []) return new Response("Table {$table} has no admin-editable columns", 422); // INSERT () () is invalid SQL
        $cols = array_keys($data);
        $quoted = implode(', ', array_map(static fn(string $c): string => "\"{$c}\"", $cols));
        $marks  = implode(', ', array_fill(0, count($cols), '?'));
        $this->db->query("INSERT INTO \"{$table}\" ({$quoted}) VALUES ({$marks})", array_values($data));
        return Response::redirect("/admin/browse/{$table}");
    }

    #[Auth]
    public function edit(string $table, string $rid): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        $record = $this->row($table, $rid);
        if ($record === null) return new Response('Row not found', 404);
        return $this->view->render('form', [
            'table' => $table, 'record' => $record, 'columns' => $this->editable($table),
            'action' => "/admin/update/{$table}/{$rid}", 'title' => "Edit {$table} row {$rid}",
            'csrf' => $this->session->csrfToken(),
        ]);
    }

    #[Auth] #[Post]
    public function update(string $table, string $rid): Response
    {
        if ($r = $this->deny($table)) return $r;
        if ($this->row($table, $rid) === null) return new Response('Row not found', 404);
        [$data, $error] = $this->formData($table, creating: false);
        if ($error !== null) return new Response($error, 422);
        if ($data !== []) {
            $sets = implode(', ', array_map(static fn(string $c): string => "\"{$c}\" = ?", array_keys($data)));
            $this->db->query("UPDATE \"{$table}\" SET {$sets} WHERE rowid = ?", [...array_values($data), $rid]);
        }
        return Response::redirect("/admin/browse/{$table}");
    }

    #[Auth] #[Post]
    public function delete(string $table, string $rid): Response
    {
        if ($r = $this->deny($table)) return $r;
        $this->db->query("DELETE FROM \"{$table}\" WHERE rowid = ?", [$rid]);
        return Response::redirect("/admin/browse/{$table}");
    }

    /**
     * Columns an admin may write: pk, *_at timestamps, and BLOBs excluded
     * (binary data cannot round-trip through trimmed text inputs).
     *
     * @return list<array<array-key, mixed>>
     */
    private function editable(string $table): array
    {
        return array_values(array_filter($this->schema->columns($table), static function (array $c): bool {
            return (int) $c['pk'] !== 1
                && !str_ends_with($c['name'], '_at')
                && strtoupper((string) $c['type']) !== 'BLOB';
        }));
    }

    /**
     * Collect + validate form input against PRAGMA metadata.
     * Conventions: is_* INTEGER columns are checkboxes (absent = 0);
     * password_hash is write-only (blank on update = keep, non-blank = password_hash());
     * NOT NULL columns without a default are required on create;
     * created_at is auto-filled on create when the column exists.
     * @return array{0: array<string,mixed>, 1: ?string} [data, error]
     */
    private function formData(string $table, bool $creating): array
    {
        $data = [];
        foreach ($this->editable($table) as $col) {
            $name = $col['name'];
            if (str_starts_with($name, 'is_')) {
                $data[$name] = $this->request->postStr($name) !== '' ? 1 : 0;
                continue;
            }
            if ($name === 'password_hash') {
                $plain = $this->request->postStr($name);
                if (str_contains($plain, "\0")) {
                    return [[], 'password cannot contain a NUL byte']; // password_hash() would throw
                }
                if ($plain !== '') {
                    $data[$name] = password_hash($plain, PASSWORD_DEFAULT);
                } elseif ($creating) {
                    return [[], 'password is required'];
                }
                continue; // blank on update: keep the existing hash
            }
            $value = $this->request->str($name);
            $required = (int) $col['notnull'] === 1 && $col['dflt_value'] === null;
            if ($value === '' && $required) {
                return [[], "{$name} is required"];
            }
            $data[$name] = $value === '' ? null : $value;
        }
        if ($creating) {
            foreach ($this->schema->columns($table) as $col) {
                if ($col['name'] === 'created_at') $data['created_at'] = date('c');
            }
        }
        return [$data, null];
    }
}
