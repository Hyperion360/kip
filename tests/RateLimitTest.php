<?php // tests/RateLimitTest.php
namespace Kip\Tests;

use Kip\Database;
use Kip\RateLimit;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * The fixed-window rate limiter (src/RateLimit.php). The schema comes from
 * the REAL skeleton migration, the way JobsTest loads the jobs tables, so
 * the tests can never drift from what apps actually get from kip migrate.
 * Times are injected through check()'s $now, so every window edge is exact
 * and nothing sleeps.
 */
final class RateLimitTest extends TestCase
{
    private Database $db;
    private string $fileDb = '';

    protected function setUp(): void
    {
        $this->db = $this->freshDb();
    }

    protected function tearDown(): void
    {
        foreach ([$this->fileDb, $this->fileDb . '-wal', $this->fileDb . '-shm'] as $f) {
            if ($f !== '' && is_file($f)) @unlink($f);
        }
    }

    private function freshDb(): Database
    {
        $db = new Database($this->dsn());
        (new Migrator($db, dirname(__DIR__) . '/skeleton/app/migrations'))->migrate();
        return $db;
    }

    /** A second connection to the same file, for cross-process tests. */
    private function secondDb(): Database
    {
        return new Database($this->dsn());
    }

    private function dsn(): string
    {
        if ($this->fileDb === '') {
            $this->fileDb = sys_get_temp_dir() . '/kip-ratelimit-' . bin2hex(random_bytes(6)) . '.sqlite';
            if (is_file($this->fileDb)) unlink($this->fileDb);
        }
        return 'sqlite:' . $this->fileDb;
    }

    /** The hits recorded for one (prefix, ip), keyed by window_start. @return array<int, int> */
    private function hits(string $prefix, string $ip): array
    {
        $out = [];
        foreach ($this->db->all('SELECT window_start, hits FROM rate_limits WHERE prefix = ? AND ip = ? ORDER BY window_start', [$prefix, $ip]) as $row) {
            $out[(int) $row['window_start']] = (int) $row['hits'];
        }
        return $out;
    }

    /** @param array<string, mixed> $extra */
    private function make(array $limits, array $extra = []): RateLimit
    {
        return new RateLimit($this->db, $extra + $limits);
    }

    // ------------------------------------------------------ counting windows

    public function test_under_the_limit_passes_and_over_returns_retry_after(): void
    {
        $rl = $this->make(['auth' => ['max' => 2, 'window' => 60]]);
        // now=1000 sits at offset 40 of the 960..1020 window, so a blocked
        // request waits exactly 20 seconds.
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1000));
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/auth/login', '1.2.3.4', 1000), 'the third hit is over max=2');
        $this->assertSame(15, $rl->check('/auth/login', '1.2.3.4', 1005), 'still blocked, 15 seconds left');
        $this->assertSame([960 => 4], $this->hits('Auth', '1.2.3.4'), 'every hit is persisted');
    }

    public function test_the_window_edge_is_the_first_second_of_the_next_bucket(): void
    {
        $rl = $this->make(['auth' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 959)); // window 900..960
        $this->assertSame(1, $rl->check('/auth/x', '1.2.3.4', 959), 'one second left in the old bucket');
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 960), '960 is the first second of the 960..1020 bucket');
        $this->assertSame(60, $rl->check('/auth/x', '1.2.3.4', 960), 'a full window to wait from its first second');
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 1020), 'the rollover resets the count');
        // The prune retired the 900 bucket when 1020 opened (cutoff 1020-60):
        // each surviving row is one still-remembered window.
        $this->assertSame([960 => 2, 1020 => 1], $this->hits('Auth', '1.2.3.4'));
    }

    public function test_negative_injected_time_is_rejected(): void
    {
        $rl = $this->make(['auth' => ['max' => 1, 'window' => 60]]);
        $this->expectException(\InvalidArgumentException::class);
        $rl->check('/auth/x', '1.2.3.4', -1);
    }

    public function test_max_zero_is_a_kill_switch_for_the_prefix(): void
    {
        $rl = $this->make(['auth' => ['max' => 0, 'window' => 60]]);
        $this->assertSame(20, $rl->check('/auth/x', '1.2.3.4', 1000), 'the very first request is over max=0');
    }

    // ------------------------------------------------------------ the prune

    public function test_a_new_window_prunes_expired_rows_but_never_live_buckets(): void
    {
        // Two configured windows: 60s for auth, 30s for api. At now=1000 the
        // auth bucket 960..1020 is LIVE, the api bucket 900..930 is long
        // expired. A naive prune keyed to the requester's own window start
        // (990) would delete the live auth row; the cutoff must be
        // now - largest configured window (940).
        $rl = $this->make([
            'auth' => ['max' => 5, 'window' => 60],
            'api' => ['max' => 5, 'window' => 30],
        ]);
        $this->db->query('INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?, ?, ?, 3)',
            ['Auth', '1.2.3.4', 960]);
        $this->db->query('INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?, ?, ?, 1)',
            ['Api', '9.9.9.9', 900]);

        $this->assertNull($rl->check('/api/x', '9.9.9.9', 1000)); // 990..1020 opens, prune runs

        $this->assertSame([990 => 1], $this->hits('Api', '9.9.9.9'), 'the fresh window row, a bucket of its own');
        $this->assertSame([960 => 3], $this->hits('Auth', '1.2.3.4'), 'the LIVE shorter-history bucket survives the prune');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM rate_limits WHERE window_start < 940')['c'],
            'expired rows are gone');
    }

    public function test_the_prune_fires_only_when_a_window_opens(): void
    {
        $deletes = 0;
        $this->db->onQuery(function (string $sql) use (&$deletes): void {
            if (str_starts_with(ltrim($sql), 'DELETE')) $deletes++;
        });
        $rl = $this->make(['auth' => ['max' => 10, 'window' => 60]]);
        foreach ([1000, 1001, 1002, 1003, 1004] as $now) {
            $this->assertNull($rl->check('/auth/x', '1.2.3.4', $now));
        }
        $this->assertSame(1, $deletes, 'one prune when the 960 window opened');
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 1020), 'the 1020 window opens');
        $this->assertSame(2, $deletes);
        $this->db->onQuery(static fn () => null);
    }

    // -------------------------------------------------------------- the key

    public function test_equivalent_ipv6_spellings_share_one_bucket(): void
    {
        $rl = $this->make(['auth' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/auth/x', '2001:db8::1', 1000));
        $this->assertSame(20, $rl->check('/auth/x', '2001:0DB8:0:0:0:0:0:1', 1000),
            'the expanded spelling is the same address, the same bucket');
        $this->assertNull($rl->check('/auth/x', '2001:db8::2', 1000), 'a different address is a different bucket');
        $this->assertNull($rl->check('/auth/x', 'not-an-ip', 1000), 'unparseable keys never collapse together');
        $this->assertNull($rl->check('/auth/x', '', 1000), 'the no-IP bucket is its own');
    }

    public function test_dashed_and_underscored_prefixes_share_one_bucket(): void
    {
        // The router studly-cases both separators to one controller
        // (MyBillingController); the limiter must not leave the second
        // spelling unlimited.
        $rl = $this->make(['my-billing' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/my-billing/pay', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/my_billing/pay', '1.2.3.4', 1000));
        $this->assertSame(['MyBilling' => '1.2.3.4'], array_column(
            $this->db->all('SELECT DISTINCT prefix, ip FROM rate_limits'),
            'ip', 'prefix'
        ), 'the stored key is the router-canonical spelling');
    }

    public function test_the_root_path_counts_against_the_empty_prefix(): void
    {
        $rl = $this->make(['' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/', '1.2.3.4', 1000));
    }

    public function test_an_unconfigured_prefix_costs_no_queries_at_all(): void
    {
        $queries = 0;
        $this->db->onQuery(function () use (&$queries): void { $queries++; });
        $rl = $this->make(['auth' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/posts/show/1', '1.2.3.4', 1000));
        $this->assertNull($rl->check('/admin/sql', '1.2.3.4', 1000));
        $this->assertSame(0, $queries, 'prefix resolution is pure PHP, the database is never asked');
        $this->db->onQuery(static fn () => null);
    }

    // ------------------------------------------------------ the '*' fallback

    public function test_the_star_fallback_limits_every_unconfigured_prefix(): void
    {
        $rl = $this->make(['*' => ['max' => 2, 'window' => 60]]);
        $this->assertNull($rl->check('/posts/store', '1.2.3.4', 1000));
        $this->assertNull($rl->check('/posts/store', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/posts/store', '1.2.3.4', 1000),
            'the third hit on an unconfigured prefix is over the fallback max');
        $this->assertSame([960 => 3], $this->hits('Posts', '1.2.3.4'),
            'the row is keyed by the ACTUAL canonical prefix, so per-surface quotas stay independent');
        // With no explicit '' entry, POST / falls back like any other
        // unconfigured prefix and keys its row on the empty prefix.
        $this->assertNull($rl->check('/', '5.6.7.8', 1000));
        $this->assertSame([960 => 1], $this->hits('', '5.6.7.8'));
    }

    public function test_fallback_retry_after_uses_the_fallback_window(): void
    {
        $rl = $this->make(['*' => ['max' => 0, 'window' => 120]]);
        // now=1000 sits 40 seconds into the 960..1080 fallback window.
        $this->assertSame(80, $rl->check('/posts/store', '1.2.3.4', 1000));
    }

    public function test_an_explicit_prefix_beats_the_star_fallback(): void
    {
        // Different windows on purpose: a bug that took the explicit max with
        // the fallback window would open the Auth bucket at 990, not 960.
        $rl = $this->make(['auth' => ['max' => 1, 'window' => 60], '*' => ['max' => 5, 'window' => 90]]);
        $this->assertNull($rl->check('/auth/login', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/auth/login', '1.2.3.4', 1000),
            'the explicit tighter max wins, the fallback never loosens it');
        $this->assertSame([960 => 2], $this->hits('Auth', '1.2.3.4'),
            'the bucket start pins the explicit window');
        $this->assertNull($rl->check('/kudos/add', '1.2.3.4', 1000),
            'an unconfigured prefix still falls back');
        $this->assertSame([990 => 1], $this->hits('Kudos', '1.2.3.4'),
            'the fallback supplies its own window for the unconfigured surface');
    }

    public function test_the_empty_root_prefix_still_beats_the_star_fallback(): void
    {
        $rl = $this->make(['' => ['max' => 1, 'window' => 60], '*' => ['max' => 5, 'window' => 90]]);
        $this->assertNull($rl->check('/', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/', '1.2.3.4', 1000),
            'POST / with an explicit empty prefix is not the fallback');
        $this->assertSame([960 => 2], $this->hits('', '1.2.3.4'),
            'the bucket start pins the explicit window, not the fallback 90');
    }

    public function test_two_unconfigured_prefixes_hold_independent_fallback_buckets(): void
    {
        $rl = $this->make(['*' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/kudos/add', '1.2.3.4', 1000));
        $this->assertNull($rl->check('/review/save', '1.2.3.4', 1000),
            'spending the kudos budget does not touch the review bucket');
        $this->assertSame(20, $rl->check('/kudos/add', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/review/save', '1.2.3.4', 1000));
    }

    public function test_the_fallback_window_joins_the_prune_grace(): void
    {
        // The fallback window (120) is the largest configured. A new explicit
        // 60s bucket opens at now=1030 (bucket 1020) and runs the prune: a
        // buggy grace keyed to the explicit window alone would cut at
        // 1030-60=970 and delete the LIVE fallback bucket at 960; the correct
        // cutoff 1030-120=910 keeps it.
        $rl = $this->make(['auth' => ['max' => 5, 'window' => 60], '*' => ['max' => 5, 'window' => 120]]);
        $this->db->query('INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES (?, ?, ?, 3)',
            ['Posts', '9.9.9.9', 960]);
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 1030));
        $this->assertSame([960 => 3], $this->hits('Posts', '9.9.9.9'),
            'the LIVE fallback bucket survives the explicit-bucket prune');
        $this->assertSame([1020 => 1], $this->hits('Auth', '1.2.3.4'), 'the new explicit bucket is recorded');
    }

    public function test_a_fallback_hit_inside_a_transaction_refuses_to_count(): void
    {
        // The Jobs::claim() contract applies to fallback-served hits too: the
        // guard sits after limit resolution, so a '*' hit inside a caller's
        // transaction must throw, never silently skip counting.
        $rl = $this->make(['*' => ['max' => 5, 'window' => 60]]);
        $this->db->begin();
        try {
            $rl->check('/posts/store', '1.2.3.4', 1000);
            $this->fail('a fallback-served hit inside a transaction must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autocommit', $e->getMessage());
        } finally {
            $this->db->rollBack();
        }
    }

    public function test_a_malformed_star_entry_is_a_boot_error(): void
    {
        try {
            $this->make(['*' => ['max' => 1, 'window' => 0]]);
            $this->fail('a zero window under the fallback must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('rate_limit.*.window', $e->getMessage());
        }
        try {
            $this->make(['*' => 10]);
            $this->fail('a non-array fallback must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('rate_limit.*', $e->getMessage());
        }
    }

    public function test_the_fallback_never_counts_spellings_the_router_could_not_route(): void
    {
        // The gate runs BEFORE the counting upsert: a spelling that fails the
        // router's grammar costs zero queries and writes no row, so junk that
        // is guaranteed to 404 cannot grow the table (every rotation would
        // otherwise be a fresh bucket the cap can never trip). Grammatical
        // spellings that route nowhere are still charged, by design; that is
        // the next test's pin.
        $queries = 0;
        $this->db->onQuery(function () use (&$queries): void { $queries++; });
        $rl = $this->make(['*' => ['max' => 5, 'window' => 60]]);
        $this->assertNull($rl->check('/AUTH/x', '1.2.3.4', 1000), 'uppercase cannot route, so it cannot count');
        $this->assertNull($rl->check('/po--sts/x', '1.2.3.4', 1000), 'a doubled separator cannot route');
        $this->assertNull($rl->check('/posts-/x', '1.2.3.4', 1000), 'a trailing separator cannot route');
        $this->assertNull($rl->check('/' . str_repeat('a', 65) . '/x', '1.2.3.4', 1000),
            'a segment longer than 64 bytes cannot be a real controller name');
        $this->assertSame(0, $queries, 'the gate sits before the database');
        $this->assertSame([], $this->db->all('SELECT * FROM rate_limits'), 'no rows were written');
        $this->db->onQuery(static fn () => null);
    }

    public function test_the_fallback_still_counts_grammatical_segments_that_route_nowhere(): void
    {
        // The gate is the router's own grammar, not route existence: a
        // well-formed segment the app never defined is still counted, the
        // same way a POST to a nonexistent path under an explicit prefix is.
        $rl = $this->make(['*' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/wibble/wobble', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/wibble/wobble', '1.2.3.4', 1000));
    }

    public function test_an_explicit_prefix_still_counts_a_spelling_the_fallback_would_refuse(): void
    {
        // Explicit prefixes keep today's semantics untouched: 'auth-' fails
        // the segment grammar but studly-canonicalizes to a configured key,
        // and an explicitly protected prefix counts every spelling of itself.
        $rl = $this->make(['auth' => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/auth-/x', '1.2.3.4', 1000));
        $this->assertSame([960 => 1], $this->hits('Auth', '1.2.3.4'));
    }

    // ---------------------------------------------------- config validation

    public function test_window_below_one_second_is_a_boot_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_limit.auth.window');
        $this->make(['auth' => ['max' => 1, 'window' => 0]]);
    }

    public function test_a_missing_or_negative_max_is_a_boot_error(): void
    {
        try {
            $this->make(['auth' => ['window' => 60]]);
            $this->fail('missing max must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('rate_limit.auth.max', $e->getMessage());
        }
        try {
            $this->make(['auth' => ['max' => -1, 'window' => 60]]);
            $this->fail('negative max must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('rate_limit.auth.max', $e->getMessage());
        }
    }

    public function test_a_non_numeric_max_is_a_boot_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_limit.auth.max');
        $this->make(['auth' => ['max' => 'ten', 'window' => 60]]);
    }

    public function test_a_non_array_entry_is_a_boot_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_limit.auth');
        $this->make(['auth' => 10]);
    }

    public function test_prefixes_that_cannot_match_a_route_are_boot_errors(): void
    {
        foreach (['Auth', '/auth', 'auth/login', 'auth-'] as $bad) {
            try {
                $this->make([$bad => ['max' => 1, 'window' => 60]]);
                $this->fail("prefix '{$bad}' must be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("rate_limit.{$bad}", $e->getMessage());
            }
        }
    }

    public function test_numeric_string_values_and_the_integer_zero_key_are_accepted(): void
    {
        $rl = $this->make(['auth' => ['max' => '2', 'window' => '60'], 0 => ['max' => 1, 'window' => 60]]);
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 1000));
        $this->assertNull($rl->check('/auth/x', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/auth/x', '1.2.3.4', 1000), 'numeric strings mean the same as ints');
        // PHP folds the array key '0' to the integer 0; the segment "0" is a
        // legal first segment, so it must still configure a prefix.
        $this->assertNull($rl->check('/0/x', '1.2.3.4', 1000));
        $this->assertSame(20, $rl->check('/0/x', '1.2.3.4', 1000));
    }

    public function test_two_spellings_of_one_prefix_are_a_boot_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MyBilling');
        $this->make(['my-billing' => ['max' => 1, 'window' => 60], 'my_billing' => ['max' => 2, 'window' => 60]]);
    }

    // ------------------------------------------------------------ drivers

    public function test_an_old_sqlite_is_a_loud_boot_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('3.35');
        RateLimit::assertDriverSupport('sqlite:/tmp/app.sqlite', '3.26.0');
    }

    public function test_supported_drivers_pass_the_boot_check(): void
    {
        RateLimit::assertDriverSupport('sqlite:/tmp/app.sqlite', '3.53.4');
        RateLimit::assertDriverSupport('pgsql:host=x', '9.5');
        $this->addToAssertionCount(1); // no exception is the assertion
    }

    public function test_an_unsupported_driver_is_a_loud_boot_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mysql');
        RateLimit::assertDriverSupport('mysql:host=x', '8.0.36');
    }

    // -------------------------------------------------------- concurrency

    public function test_the_upsert_is_atomic_across_two_connections(): void
    {
        // Deterministic interleaving, the jobs claim-test pattern: two
        // connections on one file take turns, and the counter never loses a
        // hit, never splits the key across rows.
        $limits = ['auth' => ['max' => 3, 'window' => 1_000_000_000]];
        $a = new RateLimit($this->db, $limits);
        $b = new RateLimit($this->secondDb(), $limits);
        $now = 1_790_000_000; // one window, never crossed

        $this->assertNull($a->check('/auth/x', '1.2.3.4', $now));
        $this->assertNull($b->check('/auth/x', '1.2.3.4', $now));
        $this->assertNull($a->check('/auth/x', '1.2.3.4', $now));
        $this->assertSame(210_000_000, $b->check('/auth/x', '1.2.3.4', $now),
            'the fourth hit across BOTH connections is over max=3, and waits out the window');

        $this->assertSame([1_000_000_000 => 4], $this->hits('Auth', '1.2.3.4'),
            'one row, all four hits counted');
    }

    public function test_an_ambient_transaction_refuses_to_count(): void
    {
        // The Jobs::claim() contract: a hit inside a caller's transaction
        // would roll back with it, and the spent quota would never count.
        $rl = $this->make(['auth' => ['max' => 5, 'window' => 60]]);
        $this->db->begin();
        try {
            $rl->check('/auth/x', '1.2.3.4', 1000);
            $this->fail('a configured-prefix check inside a transaction must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autocommit', $e->getMessage());
        } finally {
            $this->db->rollBack();
        }
        // The guard sits after prefix resolution: unconfigured prefixes stay
        // free even under an open transaction.
        $this->db->begin();
        $this->assertNull($rl->check('/posts/x', '1.2.3.4', 1000));
        $this->db->rollBack();
    }

    public function test_a_held_write_lock_fails_loudly_within_the_timeout(): void
    {
        $holder = $this->secondDb();
        $holder->begin();
        $holder->query("INSERT INTO rate_limits (prefix, ip, window_start, hits) VALUES ('Auth', '1.2.3.4', 960, 1)");

        $this->db->query('PRAGMA busy_timeout = 100'); // bounded, so the test is fast
        $rl = $this->make(['auth' => ['max' => 5, 'window' => 60]]);
        $start = microtime(true);
        try {
            $rl->check('/auth/x', '5.6.7.8', 1000);
            $this->fail('the upsert must surface the lock, not silently pass');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        } finally {
            $holder->rollBack();
        }
        $this->assertLessThan(5.0, microtime(true) - $start, 'the busy timeout bounds the failure');
    }
}
