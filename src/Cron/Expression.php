<?php // src/Cron/Expression.php

declare(strict_types=1);
namespace Kip\Cron;

/**
 * A 5-field crontab expression: minute hour day-of-month month day-of-week.
 *
 * Supported per field: a star, a number, a range a-b, a step on a star or a
 * range, and comma lists of those. Semantics follow the crontab daemon's
 * documented behavior, with the day-field rule exactly as the daemon applies
 * it: a field whose text starts with a star (a plain star or a stepped one)
 * counts as unrestricted, and when BOTH day fields are restricted the day
 * matches when either field matches, otherwise both must match. 0 and 7 are
 * both Sunday. Fields match the clock's own wall time; in a DST gap the
 * skipped wall minutes simply never match, and in a fold a matching wall
 * minute fires once per pass.
 */
final class Expression
{
    /** Field names for error messages, in position order. */
    private const NAMES = ['minute', 'hour', 'day-of-month', 'month', 'day-of-week'];

    /** @var array<list<int>> matching values per field, sorted, deduplicated */
    private readonly array $sets;

    /**
     * @param array<list<int>> $sets
     */
    private function __construct(
        array $sets,
        private readonly bool $domStarred,
        private readonly bool $dowStarred,
    ) {
        $this->sets = $sets;
    }

    public static function parse(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);
        if ($fields === false || count($fields) !== 5) {
            throw new \InvalidArgumentException(sprintf(
                "cron expression '%s': expected exactly five fields (minute hour day-of-month month day-of-week), got %d",
                $expression,
                count($fields ?: [])
            ));
        }
        // min, max per position: minute, hour, day-of-month, month, day-of-week.
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $sets = [];
        foreach ($fields as $i => $field) {
            $sets[$i] = self::field($expression, $field, self::NAMES[$i], $ranges[$i][0], $ranges[$i][1]);
        }
        // Sunday may be written 0 or 7: normalize 7 down so the value set has one spelling.
        if (in_array(7, $sets[4], true)) {
            $sets[4] = array_values(array_unique([...array_diff($sets[4], [7]), 0]));
            sort($sets[4]);
        }
        return new self($sets, str_starts_with($fields[2], '*'), str_starts_with($fields[4], '*'));
    }

    /** True when every field matches the given clock's wall time. */
    public function isDue(\DateTimeImmutable $at): bool
    {
        return in_array((int) $at->format('i'), $this->sets[0], true)
            && in_array((int) $at->format('H'), $this->sets[1], true)
            && $this->dayMatches($at);
    }

    /**
     * The earliest matching wall minute strictly after $from's minute, or
     * null when nothing matches within four years (a Feb 30 entry never
     * does; a Feb 29 entry needs the whole window once every four years).
     * Wall minutes a DST gap removed are skipped, so the answer is always a
     * time that will actually occur.
     */
    public function nextDue(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        $day = $from->modify('today');
        $last = $day->add(new \DateInterval('P4Y'));
        for (; $day < $last; $day = $day->modify('+1 day')) {
            if (!$this->dayMatches($day)) continue;
            foreach ($this->sets[1] as $hour) {
                foreach ($this->sets[0] as $minute) {
                    $wall = sprintf('%s %02d:%02d', $day->format('Y-m-d'), $hour, $minute);
                    $candidate = $day->setTime($hour, $minute);
                    if ($candidate->format('Y-m-d H:i') !== $wall) continue; // gap: that wall minute does not exist
                    if ($candidate > $from) return $candidate;
                }
            }
        }
        return null;
    }

    private function dayMatches(\DateTimeImmutable $day): bool
    {
        if (!in_array((int) $day->format('n'), $this->sets[3], true)) return false;
        $domOk = in_array((int) $day->format('j'), $this->sets[2], true);
        $dowOk = in_array((int) $day->format('w'), $this->sets[4], true);
        // Both day fields restricted: either match is enough (the daemon's
        // documented OR). Either field starred (a plain * or a */n): AND.
        return $this->domStarred || $this->dowStarred ? ($domOk && $dowOk) : ($domOk || $dowOk);
    }

    /**
     * Parse one field into its sorted value list.
     *
     * @return list<int>
     */
    private static function field(string $expression, string $field, string $name, int $min, int $max): array
    {
        if (preg_match('/[A-Za-z]/', $field) === 1) {
            throw new \InvalidArgumentException(sprintf(
                "cron expression '%s': day and month names are not supported in the %s field, use numbers",
                $expression,
                $name
            ));
        }
        $values = [];
        foreach (explode(',', $field) as $item) {
            if ($item === '') {
                throw new \InvalidArgumentException(sprintf(
                    "cron expression '%s': %s field has an empty item",
                    $expression,
                    $name
                ));
            }
            $step = 1;
            $rangePart = $item;
            if (str_contains($item, '/')) {
                [$rangePart, $stepText] = explode('/', $item, 2);
                if (!ctype_digit($stepText) || (int) $stepText < 1) {
                    throw new \InvalidArgumentException(sprintf(
                        "cron expression '%s': %s field step must be a positive integer, got '%s'",
                        $expression,
                        $name,
                        $stepText
                    ));
                }
                $step = (int) $stepText;
                // A step belongs on a range or a star; a bare 5/15 is rejected
                // loudly rather than guessed, the error says what to write.
                if ($rangePart !== '*' && !str_contains($rangePart, '-')) {
                    throw new \InvalidArgumentException(sprintf(
                        "cron expression '%s': %s field step needs a range or a star, write %d-%d/%d or */%d",
                        $expression,
                        $name,
                        $min,
                        $max,
                        $step,
                        $step
                    ));
                }
            }
            [$from, $to] = self::bounds($expression, $rangePart, $name, $min, $max);
            for ($v = $from; $v <= $to; $v += $step) $values[] = $v;
        }
        sort($values);
        return array_values(array_unique($values));
    }

    /**
     * The [from, to] bounds of one field item: a star spans the whole field,
     * a-b spans the range, a single number is a one-value span.
     *
     * @return array{0: int, 1: int}
     */
    private static function bounds(string $expression, string $part, string $name, int $min, int $max): array
    {
        if ($part === '*') return [$min, $max];
        if (str_contains($part, '-')) {
            $ends = explode('-', $part);
            if (count($ends) !== 2 || !ctype_digit($ends[0]) || !ctype_digit($ends[1])) {
                throw new \InvalidArgumentException(sprintf(
                    "cron expression '%s': %s field range '%s' must be two numbers like %d-%d",
                    $expression,
                    $name,
                    $part,
                    $min,
                    $max
                ));
            }
            $from = (int) $ends[0];
            $to = (int) $ends[1];
            self::assertInRange($expression, $name, $from, $min, $max);
            self::assertInRange($expression, $name, $to, $min, $max);
            if ($from > $to) {
                throw new \InvalidArgumentException(sprintf(
                    "cron expression '%s': %s field range %d-%d must be ascending",
                    $expression,
                    $name,
                    $from,
                    $to
                ));
            }
            return [$from, $to];
        }
        if (!ctype_digit($part)) {
            throw new \InvalidArgumentException(sprintf(
                "cron expression '%s': %s field item '%s' is not a number, range, or star",
                $expression,
                $name,
                $part
            ));
        }
        self::assertInRange($expression, $name, (int) $part, $min, $max);
        return [(int) $part, (int) $part];
    }

    private static function assertInRange(string $expression, string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf(
                "cron expression '%s': %s must be %d-%d, got %d",
                $expression,
                $name,
                $min,
                $max,
                $value
            ));
        }
    }
}
