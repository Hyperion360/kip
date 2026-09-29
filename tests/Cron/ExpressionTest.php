<?php // tests/Cron/ExpressionTest.php
namespace Kip\Tests\Cron;

use Kip\Cron\Expression;
use PHPUnit\Framework\TestCase;

/**
 * The 5-field cron expression evaluator: field syntax, the day-of-month /
 * day-of-week interaction, and forward search for the next due minute.
 * Semantics follow the crontab daemon: a field starting with a star
 * (including a stepped star) counts as unrestricted for the day-field rule,
 * and both day fields restricted means EITHER may match.
 */
final class ExpressionTest extends TestCase
{
    /** @param array{expression: string, at: string, due: bool} $case */
    public static function assertDue(array $case): void
    {
        $at = new \DateTimeImmutable($case['at']);
        self::assertSame(
            $case['due'],
            Expression::parse($case['expression'])->isDue($at),
            "{$case['expression']} at {$case['at']}"
        );
    }

    /** @param list<array{expression: string, at: string, due: bool}> $cases */
    public static function assertAllDue(array $cases): void
    {
        foreach ($cases as $case) self::assertDue($case);
    }

    public function test_matches_plain_fields(): void
    {
        self::assertAllDue([
            ['expression' => '* * * * *', 'at' => '2026-09-29 14:05:37', 'due' => true],
            ['expression' => '*/15 * * * *', 'at' => '2026-09-29 14:00:00', 'due' => true],
            ['expression' => '*/15 * * * *', 'at' => '2026-09-29 14:45:59', 'due' => true],
            ['expression' => '*/15 * * * *', 'at' => '2026-09-29 14:05:00', 'due' => false],
            ['expression' => '0 3 * * *', 'at' => '2026-09-29 03:00:00', 'due' => true],
            ['expression' => '0 3 * * *', 'at' => '2026-09-29 03:01:00', 'due' => false],
            ['expression' => '0 3 * * *', 'at' => '2026-09-29 04:00:00', 'due' => false],
            ['expression' => '1,15,30 * * * *', 'at' => '2026-09-29 14:15:00', 'due' => true],
            ['expression' => '1,15,30 * * * *', 'at' => '2026-09-29 14:16:00', 'due' => false],
            ['expression' => '10-40/15 * * * *', 'at' => '2026-09-29 14:10:00', 'due' => true],
            ['expression' => '10-40/15 * * * *', 'at' => '2026-09-29 14:25:00', 'due' => true],
            ['expression' => '10-40/15 * * * *', 'at' => '2026-09-29 14:40:00', 'due' => true],
            ['expression' => '10-40/15 * * * *', 'at' => '2026-09-29 14:32:00', 'due' => false],
            ['expression' => '0   3 * * *', 'at' => '2026-09-29 03:00:00', 'due' => true], // extra spaces
            ['expression' => '0 0 1 12 *', 'at' => '2026-12-01 00:00:00', 'due' => true],
            ['expression' => '0 0 1 12 *', 'at' => '2026-11-01 00:00:00', 'due' => false],
            ['expression' => '0 0 31 * *', 'at' => '2026-10-31 00:00:00', 'due' => true],
        ]);
    }

    /** Sunday is 0 or 7, like the daemon reads it. */
    public function test_day_of_week_zero_and_seven_are_both_sunday(): void
    {
        self::assertAllDue([
            ['expression' => '0 0 * * 0', 'at' => '2026-10-04 00:00:00', 'due' => true],
            ['expression' => '0 0 * * 7', 'at' => '2026-10-04 00:00:00', 'due' => true],
            ['expression' => '0 0 * * 0', 'at' => '2026-10-05 00:00:00', 'due' => false],
        ]);
    }

    /**
     * The day-field rule: when BOTH day-of-month and day-of-week are
     * restricted (neither field starts with a star), either match suffices;
     * when either field starts with a star (including a stepped star), both
     * are required.
     */
    public function test_day_field_or_rule_and_its_star_exception(): void
    {
        self::assertAllDue([
            // Both restricted: OR. 2026-09-28 is a Monday but not the 1st.
            ['expression' => '0 0 1 * 1', 'at' => '2026-09-28 00:00:00', 'due' => true],
            // 2026-10-01 is the 1st but a Thursday.
            ['expression' => '0 0 1 * 1', 'at' => '2026-10-01 00:00:00', 'due' => true],
            // 2026-09-29 is a Tuesday the 29th: neither.
            ['expression' => '0 0 1 * 1', 'at' => '2026-09-29 00:00:00', 'due' => false],
            // Only dom restricted: AND with an unrestricted dow.
            ['expression' => '0 0 1 * *', 'at' => '2026-09-28 00:00:00', 'due' => false],
            // Only dow restricted: AND with an unrestricted dom.
            ['expression' => '0 0 * * 1', 'at' => '2026-10-01 00:00:00', 'due' => false],
            // dom is */2, a stepped wildcard: it counts as unrestricted, so AND.
            // */2 on day-of-month steps from 1, so it means ODD days.
            // 2026-10-05 is an odd Monday: matches.
            ['expression' => '0 0 */2 * 1', 'at' => '2026-10-05 00:00:00', 'due' => true],
            // 2026-09-28 is an even Monday: dom misses, so AND fails.
            ['expression' => '0 0 */2 * 1', 'at' => '2026-09-28 00:00:00', 'due' => false],
            // 2026-09-30 is an even Wednesday: both miss anyway.
            ['expression' => '0 0 */2 * 1', 'at' => '2026-09-30 00:00:00', 'due' => false],
        ]);
    }

    /**
     * Fields match the clock's own wall time, never a fixed zone: 03:00 in
     * New York is 07:00 UTC, and a 3 o'clock entry is due at the former.
     */
    public function test_matching_uses_the_clocks_own_wall_time_not_utc(): void
    {
        $at = new \DateTimeImmutable('2026-09-29 03:00:00 America/New_York');
        self::assertTrue(Expression::parse('0 3 * * *')->isDue($at));
        self::assertFalse(Expression::parse('0 7 * * *')->isDue($at));
    }

    /** @param array{expression: string, from: string, expect: string} $case */
    private function assertNext(array $case): void
    {
        $from = new \DateTimeImmutable($case['from']);
        $next = Expression::parse($case['expression'])->nextDue($from);
        self::assertNotNull($next, "{$case['expression']} from {$case['from']}");
        self::assertSame(
            $case['expect'],
            $next->format('Y-m-d H:i'),
            "{$case['expression']} from {$case['from']}"
        );
    }

    public function test_next_due_walks_forward_to_the_next_matching_minute(): void
    {
        $this->assertNext(['expression' => '* * * * *', 'from' => '2026-09-29 14:05:30', 'expect' => '2026-09-29 14:06']);
        $this->assertNext(['expression' => '0 3 * * *', 'from' => '2026-09-29 14:00:00', 'expect' => '2026-09-30 03:00']);
        $this->assertNext(['expression' => '0 3 * * *', 'from' => '2026-09-29 02:00:00', 'expect' => '2026-09-29 03:00']);
        // The current minute itself is not "next": strictly after the from minute.
        $this->assertNext(['expression' => '5 * * * *', 'from' => '2026-09-29 14:05:00', 'expect' => '2026-09-29 15:05']);
        // Day-of-week only: 2026-09-30 is a Wednesday, the next Monday is the 5th.
        $this->assertNext(['expression' => '0 0 * * 1', 'from' => '2026-09-30 12:00:00', 'expect' => '2026-10-05 00:00']);
        // Month wrap across the year boundary.
        $this->assertNext(['expression' => '0 0 1 1 *', 'from' => '2026-12-05 00:00:00', 'expect' => '2027-01-01 00:00']);
        // The OR rule in forward search: from the 29th, the 1st (Thursday) precedes the next Monday.
        $this->assertNext(['expression' => '0 0 1 * 1', 'from' => '2026-09-29 00:00:00', 'expect' => '2026-10-01 00:00']);
        // Short months: the 31st skips months without one.
        $this->assertNext(['expression' => '0 0 31 * *', 'from' => '2026-09-29 00:00:00', 'expect' => '2026-10-31 00:00']);
    }

    /** A leap-day entry needs the full 4-year scan window: 2026 -> 2028. */
    public function test_next_due_finds_a_leap_day_within_four_years(): void
    {
        $this->assertNext(['expression' => '0 0 29 2 *', 'from' => '2026-03-01 00:00:00', 'expect' => '2028-02-29 00:00']);
    }

    /** Entries that can never match (Feb 30, Feb 31) return null, not a loop. */
    public function test_next_due_is_null_for_never_matching_entries(): void
    {
        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        self::assertNull(Expression::parse('0 0 30 2 *')->nextDue($from));
        self::assertNull(Expression::parse('0 0 31 2 *')->nextDue($from));
    }

    /**
     * DST: the wall minute 02:30 does not exist on 2027-03-14 in New York
     * (clocks jump 02:00 -> 03:00), so a 02:30 entry is next due in 2028,
     * not on the normalized 03:30 time the constructor would silently produce.
     */
    public function test_next_due_skips_a_dst_gap_minute(): void
    {
        $from = new \DateTimeImmutable('2027-03-13 12:00:00 America/New_York');
        $next = Expression::parse('30 2 14 3 *')->nextDue($from);
        self::assertNotNull($next);
        self::assertSame('2028-03-14 02:30', $next->format('Y-m-d H:i'));
    }

    /** The repeated hour in a DST fall-back still lists the wall time once. */
    public function test_next_due_survives_a_dst_fold(): void
    {
        $from = new \DateTimeImmutable('2027-10-01 12:00:00 America/New_York');
        $next = Expression::parse('30 1 7 11 *')->nextDue($from);
        self::assertNotNull($next);
        self::assertSame('2027-11-07 01:30', $next->format('Y-m-d H:i'));
    }

    /** @param array{expression: string, message: string} $case */
    private function assertRejected(array $case): void
    {
        try {
            Expression::parse($case['expression']);
            self::fail("{$case['expression']} was accepted");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(
                $case['message'],
                $e->getMessage(),
                "{$case['expression']}: {$e->getMessage()}"
            );
        }
    }

    public function test_rejects_malformed_expressions_with_field_specific_errors(): void
    {
        $this->assertRejected(['expression' => '', 'message' => 'exactly five fields']);
        $this->assertRejected(['expression' => '* * * *', 'message' => 'exactly five fields']);
        $this->assertRejected(['expression' => '* * * * * *', 'message' => 'exactly five fields']);
        $this->assertRejected(['expression' => '60 * * * *', 'message' => 'minute must be 0-59']);
        $this->assertRejected(['expression' => '* 24 * * *', 'message' => 'hour must be 0-23']);
        $this->assertRejected(['expression' => '* * 32 * *', 'message' => 'day-of-month must be 1-31']);
        $this->assertRejected(['expression' => '* * 0 * *', 'message' => 'day-of-month must be 1-31']);
        $this->assertRejected(['expression' => '* * * 13 *', 'message' => 'month must be 1-12']);
        $this->assertRejected(['expression' => '* * * * 8', 'message' => 'day-of-week must be 0-7']);
        $this->assertRejected(['expression' => 'a * * * *', 'message' => 'names are not supported']);
        $this->assertRejected(['expression' => '* * * * mon', 'message' => 'names are not supported']);
        $this->assertRejected(['expression' => '* * * jan *', 'message' => 'names are not supported']);
        $this->assertRejected(['expression' => '5/15 * * * *', 'message' => 'step needs a range or a star']);
        $this->assertRejected(['expression' => '50-10 * * * *', 'message' => 'must be ascending']);
        $this->assertRejected(['expression' => '* * 10-5/2 * *', 'message' => 'must be ascending']);
        $this->assertRejected(['expression' => '*/0 * * * *', 'message' => 'step must be a positive integer']);
        $this->assertRejected(['expression' => '10-20/0 * * * *', 'message' => 'step must be a positive integer']);
        $this->assertRejected(['expression' => '1,,2 * * * *', 'message' => 'empty']);
        $this->assertRejected(['expression' => '* * * * ,5', 'message' => 'empty']);
        $this->assertRejected(['expression' => '1-2-3 * * * *', 'message' => 'range']);
    }
}
