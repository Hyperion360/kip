<?php // tests/DatabaseTest.php
namespace Kip\Tests;
use Kip\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->query('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function test_query_binds_parameters(): void // threat model: SQLi
    {
        $this->db->query('INSERT INTO t (name) VALUES (?)', ["Robert'); DROP TABLE t;--"]);
        $rows = $this->db->all('SELECT * FROM t');
        $this->assertCount(1, $rows);
        $this->assertSame("Robert'); DROP TABLE t;--", $rows[0]['name']);
    }

    public function test_one_returns_single_row_or_null(): void
    {
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->assertSame('a', $this->db->one('SELECT * FROM t WHERE id = ?', [1])['name']);
        $this->assertNull($this->db->one('SELECT * FROM t WHERE id = ?', [999]));
    }

    public function test_last_insert_id(): void
    {
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->assertSame('1', $this->db->lastInsertId());
    }

    public function test_on_query_tap_reports_sql(): void // v0.2 T3
    {
        $seen = [];
        $this->db->onQuery(function (string $sql) use (&$seen): void { $seen[] = $sql; });
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->db->all('SELECT * FROM t');
        $this->assertCount(2, $seen);
        $this->assertStringStartsWith('INSERT', $seen[0]);
    }

    public function test_separate_credentials_are_accepted(): void // mysql-style DSNs cannot embed user/pass
    {
        // The sqlite driver ignores username/password, so this proves the
        // constructor plumbs them to PDO without needing a mysql server.
        $db = new Database('sqlite::memory:', 'someuser', 'somepass');
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $db->query('INSERT INTO t (id) VALUES (1)');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM t')['c']);
    }

    /**
     * lastInsertId() declares string. PDO::lastInsertId() is string|false, so
     * the declared type must be enforced rather than assumed.
     */
    public function test_last_insert_id_is_always_a_string(): void
    {
        $db = new \Kip\Database('sqlite::memory:');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $db->query('INSERT INTO t (v) VALUES (?)', ['x']);

        $id = $db->lastInsertId();
        $this->assertIsString($id);
        $this->assertSame('1', $id);
    }

    public function test_nested_begin_opens_a_savepoint_instead_of_throwing(): void
    {
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['outer']);
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['inner']);
        $this->db->commit(); // releases the savepoint
        $this->db->commit(); // commits the outer transaction
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM t')['c']);
    }

    public function test_inner_rollback_undoes_only_the_inner_work(): void
    {
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['outer']);
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['inner']);
        $this->db->rollBack(); // back to the savepoint, outer work intact
        $this->db->commit();
        $this->assertSame([['name' => 'outer']], $this->db->all('SELECT name FROM t'));
    }

    public function test_savepoint_statements_do_not_fire_the_on_query_tap(): void
    {
        $seen = [];
        $this->db->onQuery(function (string $sql) use (&$seen): void { $seen[] = $sql; });
        $this->db->begin();
        $this->db->begin();
        $this->db->commit();
        $this->db->commit();
        $this->db->onQuery(static fn () => null);
        $this->assertSame([], $seen, 'SAVEPOINT plumbing is not table traffic');
    }

    public function test_three_deep_nesting_undoes_only_the_innermost_level(): void
    {
        // Depth 3 is reachable (app transaction > Auth::createReset() > its own
        // begin()) and is the first depth where the savepoint index arithmetic
        // differs from depth 2: the inner rollback must target kip_sp2, not the
        // savepoint of an outer layer.
        $this->db->begin();                                  // real transaction
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['outer']);
        $this->db->begin();                                  // SAVEPOINT kip_sp1
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['mid']);
        $this->db->begin();                                  // SAVEPOINT kip_sp2
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['inner']);
        $this->db->rollBack();                               // ROLLBACK TO kip_sp2: inner gone
        $this->db->commit();                                 // RELEASE kip_sp1
        $this->db->commit();                                 // real COMMIT
        $this->assertSame([['name' => 'outer'], ['name' => 'mid']], $this->db->all('SELECT name FROM t'));
    }

    public function test_a_middle_layer_rollback_takes_the_committed_inner_layer_with_it(): void
    {
        // Releasing the inner savepoint merges its work into the middle layer,
        // so a later middle rollback must discard both: this is the release-then-
        // rollback-to-outer-savepoint ordering, not just the plain nest of the
        // tests above.
        $this->db->begin();                                  // real transaction
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['outer']);
        $this->db->begin();                                  // SAVEPOINT kip_sp1
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['mid']);
        $this->db->begin();                                  // SAVEPOINT kip_sp2
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['inner']);
        $this->db->commit();                                 // RELEASE kip_sp2: inner joins the middle level
        $this->db->rollBack();                               // ROLLBACK TO kip_sp1: mid AND inner gone
        $this->db->commit();                                 // real COMMIT
        $this->assertSame([['name' => 'outer']], $this->db->all('SELECT name FROM t'));
    }

    public function test_unmatched_commit_errors_and_unmatched_rollback_stays_a_noop(): void
    {
        // Guide ch. 5 contract: an unmatched rollBack() is a no-op, an unmatched
        // commit() is PDO's own error. The no-op must also leave the depth
        // counter clean, so the next begin()/commit() pair still works.
        $this->db->rollBack(); // no transaction open: silently ignored
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['x']);
        $this->db->commit();
        try {
            $this->db->commit(); // unmatched: PDO throws, txDepth must stay 0
            $this->fail('an unmatched commit() must surface the PDO error');
        } catch (\PDOException) {
            // expected: no active transaction
        }
        $this->db->rollBack(); // still a no-op after the failed commit
        $this->db->begin();
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['y']);
        $this->db->commit();
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM t')['c']);
    }

    public function test_begin_reconciles_depth_when_the_driver_already_ended_the_transaction(): void
    {
        // Driver seam: a SQL-level ROLLBACK through exec() ends the transaction
        // at the driver level while txDepth still counts it, the state a
        // driver-side implicit commit leaves behind. SQLite has no implicit
        // commits, so the seam stands in for one.
        $this->db->begin();
        $this->db->exec('ROLLBACK');
        $this->assertSame(1, $this->db->transactionDepth()); // the framework still counts a level

        $this->db->begin(); // must start a real transaction, not a bare SAVEPOINT
        $this->db->query('INSERT INTO t (name) VALUES (?)', ['after']);
        $this->db->commit();
        $this->assertSame(0, $this->db->transactionDepth());
        $this->assertSame([['name' => 'after']], $this->db->all('SELECT name FROM t'));
    }
}
