<?php // src/Admin/Controllers/AdminController.php

declare(strict_types=1);
namespace Kip\Admin\Controllers;

use Kip\Admin\ReadOnlyConnection;
use Kip\Admin\Schema;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\RequestLog;
use Kip\Routing\Auth;
use Kip\Routing\Post;
use Kip\Session;
use Kip\View;

final class AdminController
{
    private const PER_PAGE = 50;
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private View $view;
    private Schema $schema;
    private string $adminEmail = '';

    public function __construct(private Database $db, private Session $session, private Request $request, private App $app)
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
            $row = $this->db->one('SELECT is_admin, email FROM users WHERE id = ?', [$this->session->get('user_id')]);
        } catch (\PDOException) {
            // An email-less users table (or a genuinely missing table/column) falls
            // back to the minimal gate query; only its failure denies. The broad
            // catch is inherited from the original gate: a real database failure
            // denies with the same message, it never passes.
            try {
                $row = $this->db->one('SELECT is_admin FROM users WHERE id = ?', [$this->session->get('user_id')]);
            } catch (\PDOException) {
                return new Response('Admin requires a users table with an is_admin column: see the admin guide chapter.', 403);
            }
        }
        if ((int) ($row['is_admin'] ?? 0) !== 1) return new Response('Forbidden', 403);
        $this->adminEmail = (string) ($row['email'] ?? '');
        if ($table !== null && !$this->schema->has($table)) return new Response('Unknown table', 404);
        return null;
    }

    /**
     * Render an admin view with the frame every page shares: sidebar tables,
     * signed-in identity, theme, CSRF token, return path. Actions pass only
     * what is theirs. activeTable comes from the action's own 'table' key so
     * the sidebar can mark the current table; inspect marks SQL browser / log.
     *
     * @param array<string, mixed> $data
     */
    private function render(string $template, array $data = []): string
    {
        $tables = [];
        foreach ($this->schema->tables() as $t) {
            $tables[$t] = $this->schema->count($t);
        }
        $back = $this->request->path;
        if ($this->request->get !== []) $back .= '?' . http_build_query($this->request->get);
        return $this->view->render($template, $data + [
            'tables' => $tables,
            'email' => $this->adminEmail,
            'theme' => $this->currentTheme(),
            'csrf' => $this->session->csrfToken(),
            'back' => $back,
            'activeTable' => $data['table'] ?? null,
            'inspect' => $data['inspect'] ?? '',
            'perPage' => self::PER_PAGE,
        ]);
    }

    /** '' = follow the OS (prefers-color-scheme); anything else is a forced theme. */
    private function currentTheme(): string
    {
        $v = $this->request->cookies['kip_theme'] ?? null;
        return in_array($v, ['light', 'dark'], true) ? $v : '';
    }

    /**
     * Zero-JS appearance switch: the sidebar's three submit buttons land here,
     * the answer is a cookie plus a redirect back to the page that posted.
     * back is a path whitelist, not an open redirect: it must start with /admin,
     * and CR/LF/backslash fall back (Response rejects header injection anyway).
     */
    #[Auth] #[Post]
    public function theme(): Response
    {
        if ($r = $this->deny()) return $r;
        $value = $this->request->postStr('theme');
        if (!in_array($value, ['auto', 'light', 'dark'], true)) $value = 'auto';
        $back = $this->request->postStr('back');
        // /admin exactly (with or without a query string), or /admin/... : the
        // alternation must bless "?", or a back value like /admin?status=2 loses
        // its query to the fallback. A strict prefix would bless /administrator,
        // and dot segments would let /admin/../x normalize outside the panel's
        // URL space, so both are rejected. Same-origin is guaranteed by the
        // leading slash either way; this is tidiness plus defense.
        if (preg_match('#^/admin(/|\?|$)#', $back) !== 1
            || preg_match('/[\r\n\\\\]/', $back) === 1
            || preg_match('#(?:^|/)\.\.(?:/|\?|$)#', $back) === 1) {
            $back = '/admin';
        }
        // Path=/ on both public and admin surfaces (Round 4): the theme applies
        // to every page of an app, so one cookie carries one preference. A
        // cookie-bearing request is personal for the page cache (App marks it
        // BYPASS), which is correct: a visitor who picked light/dark renders
        // different HTML; auto deletes the cookie and restores caching.
        $cookie = $value === 'auto'
            ? 'kip_theme=; Path=/; Max-Age=0; SameSite=Lax'
            : 'kip_theme=' . $value . '; Path=/; Max-Age=31536000; SameSite=Lax';
        return new Response('', 302, ['Location' => $back, 'Set-Cookie' => $cookie]);
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
        return $this->render('index', ['title' => 'Tables']);
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
        return $this->render('browse', [
            'table' => $table,
            'columns' => $this->schema->columns($table),
            'rows' => array_slice($rows, 0, self::PER_PAGE),
            'page' => $page,
            'hasNext' => $hasNext,
            'total' => $this->schema->count($table),
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
        if ($this->browser() === null) return self::needsFileBackedDb();
        // The frame's Schema list is the same membership the browser would list
        // (ledger excluded on both sides), so the home page reuses it instead of
        // counting every table twice.
        return $this->render('sql', ['title' => 'SQL browser', 'inspect' => 'sql']);
    }

    #[Auth]
    public function schema(string $table): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        $ro = $this->browser();
        if ($ro === null) return self::needsFileBackedDb();
        return $this->render('schema', [
            'table' => $table,
            'columns' => $ro->columns($table),
            'create' => $ro->createSql($table),
            'count' => $ro->count($table),
            'title' => "Schema {$table}",
            'inspect' => 'sql',
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
        return $this->render('data', [
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
            'inspect' => 'sql',
        ]);
    }

    /** Zero-JS two-step delete: the browse link lands here, the confirm form re-POSTs to delete(). */
    #[Auth]
    public function confirmdelete(string $table, string $rid): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        $row = $this->row($table, $rid);
        if ($row === null) return new Response('Row not found', 404);
        return $this->render('confirm', [
            'table' => $table, 'rid' => $rid,
            'preview' => $this->rowPreview($table, $row),
            'title' => "Delete {$table} row {$rid}?",
        ]);
    }

    #[Auth]
    public function create(string $table): Response|string
    {
        if ($r = $this->deny($table)) return $r;
        return $this->render('form', [
            'table' => $table, 'record' => [], 'columns' => $this->editable($table),
            'action' => "/admin/store/{$table}", 'title' => "New {$table} row",
            'creating' => true,
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
        return $this->render('form', [
            'table' => $table, 'record' => $record, 'columns' => $this->editable($table),
            'action' => "/admin/update/{$table}/{$rid}", 'title' => "Edit {$table} row {$rid}",
            'creating' => false,
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
     * Read-only viewer over the audit log (ch. 9's logs.sqlite requests
     * table): one keyset-paginated SELECT per view, newest first, with
     * method / status-class / path-prefix / user filters. The row IS the
     * data, so there is no detail view. Retention owns deletion; this action
     * never writes, and a POST here is a 405 (no verb attribute).
     */
    #[Auth]
    public function logs(): Response|string
    {
        if ($r = $this->deny()) return $r;
        // Guard BEFORE any container lookup: an app without log_db has no bound
        // RequestLog, and autowiring one would build it against the CONTENT
        // database, creating a requests table there. The deny() precedent for
        // an environment gap is a 403 that names the missing piece.
        $logDb = $this->app->config('log_db');
        if (!is_array($logDb) || !isset($logDb['dsn'])) {
            return new Response('The logs viewer requires a log_db database: see the admin guide chapter.', 403);
        }
        $f = $this->logFilters();
        $rows = $this->app->container->make(RequestLog::class)->page($f, self::PER_PAGE + 1);
        $shown = array_slice($rows, 0, self::PER_PAGE);
        $beyond = count($rows) > self::PER_PAGE; // one row exists past the page, away from the cursor
        $query = [];
        if (isset($f['method'])) $query['method'] = $f['method'];
        if (isset($f['status'])) $query['status'] = (string) $f['status'];
        if (isset($f['path'])) $query['path'] = $f['path'];
        if (isset($f['user_id'])) $query['user_id'] = (string) $f['user_id'];
        if (isset($f['guests'])) $query['guests'] = '1';
        $url = static function (array $extra) use ($query): string {
            $q = http_build_query($query + $extra);
            return '/admin/logs' . ($q === '' ? '' : '?' . $q);
        };
        // Rows are newest first, so $shown[0] is the page's newest row and the
        // last element its oldest. Next always paginates older (before = the
        // oldest shown id), Prev newer (after = the newest shown id). On a
        // forward window the probe row proves an older page; on an after
        // window it proves a newer one. The other side is optimistic: a link
        // whose target retention removed lands on an empty window with a
        // Newest reset, never an error.
        $isAfter = isset($f['after']);
        $first = $shown === [] ? null : (int) $shown[count($shown) - 1]['id'];
        $last = $shown === [] ? null : (int) $shown[0]['id'];
        return $this->render('logs', [
            'rows' => $shown,
            'title' => 'Requests',
            'filters' => [
                'method' => $f['method'] ?? '',
                'status' => isset($f['status']) ? (string) $f['status'] : '',
                'path' => $f['path'] ?? '',
                'user_id' => isset($f['user_id']) ? (string) $f['user_id'] : '',
                'guests' => isset($f['guests']),
            ],
            'links' => [
                'next' => $shown !== [] && ($isAfter ? $first > 1 : $beyond) ? $url(['before' => (string) $first]) : null,
                'prev' => $shown !== [] && ($isAfter ? $beyond : isset($f['before'])) ? $url(['after' => (string) $last]) : null,
                'newest' => ($isAfter || isset($f['before']) || $shown === []) ? $url([]) : null,
            ],
            'inspect' => 'logs',
        ]);
    }

    /**
     * Validate raw query input into RequestLog::page()'s shape. Nothing is
     * coerced: a value that is not a decimal-string scalar (arrays, floats,
     * garbage, overflow) is dropped, and cursors must be positive. `before`
     * wins over `after`; both together would be a contradictory window.
     *
     * @return array{method?:string,status?:int,path?:string,user_id?:int,guests?:bool,before?:int,after?:int}
     */
    private function logFilters(): array
    {
        $uint = static function (mixed $v): ?int {
            if (!is_string($v) || preg_match('/^\d+$/', $v) !== 1) return null;
            $int = filter_var($v, FILTER_VALIDATE_INT);
            return $int === false ? null : $int;
        };
        $f = [];
        $m = $this->request->input('method');
        if (is_string($m)) {
            $m = strtoupper(trim($m));
            if (in_array($m, self::METHODS, true)) $f['method'] = $m;
        }
        $s = $uint($this->request->input('status'));
        if ($s !== null && $s >= 2 && $s <= 5) $f['status'] = $s;
        $p = $this->request->input('path');
        if (is_string($p) && trim($p) !== '') $f['path'] = trim($p);
        $u = $uint($this->request->input('user_id'));
        if ($u !== null) $f['user_id'] = $u;
        if ($uint($this->request->input('guests')) === 1) $f['guests'] = true; // 0 is a real user id, not a guest sentinel
        $before = $uint($this->request->input('before'));
        if ($before !== null && $before > 0) {
            $f['before'] = $before;
        } else {
            $after = $uint($this->request->input('after'));
            if ($after !== null && $after > 0) $f['after'] = $after;
        }
        return $f;
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
     * The confirm page's dl: the row's first three human-meaningful columns,
     * the ones the panel never edits (pk, password, BLOB) excluded. Values are
     * display strings; null stays null so the view can render its em dash.
     *
     * @param array<array-key, mixed> $row
     * @return list<array{label:string,value:?string}>
     */
    private function rowPreview(string $table, array $row): array
    {
        $out = [];
        foreach ($this->schema->columns($table) as $c) {
            $name = (string) $c['name'];
            if ((int) $c['pk'] === 1 || $name === 'password_hash' || strtoupper((string) $c['type']) === 'BLOB') continue;
            if (!array_key_exists($name, $row)) continue;
            $out[] = ['label' => $name, 'value' => $row[$name] === null ? null : (string) $row[$name]];
            if (count($out) === 3) break;
        }
        return $out;
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
