<?php // tests/Migrations/JobsPlanTest.php
namespace Kip\Tests\Migrations;

use Kip\Database;
use Kip\Jobs;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every jobs query Kip\Jobs issues must plan as an index seek, never a SCAN
 * and never a TEMP B-TREE sort, once an app's own migrations have run
 * (the /db-optimize standard). The SQL is captured from Jobs itself through
 * the onQuery tap, so a query rewritten in Jobs is checked as written, and
 * the inventory is asserted non-vacuous so a silently-changed code path
 * cannot pass an empty gate (codex fold F15).
 */
final class JobsPlanTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function apps(): array
    {
        $root = dirname(__DIR__, 2);
        return ['skeleton' => [$root . '/skeleton/app/migrations'], 'blog' => [$root . '/examples/blog/app/migrations']];
    }

    #[DataProvider('apps')]
    public function test_jobs_queries_never_scan_or_sort(string $migrations): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, $migrations))->migrate();

        $sql = [];
        $db->onQuery(function (string $q) use (&$sql): void { $sql[] = $q; });
        $jobs = new Jobs($db);
        $jobs->enqueue(PlanQuietJob::class, ['n' => 1]);
        $jobs->enqueue(PlanBoomJob::class, ['n' => 2]);
        $this->assertSame('done', $jobs->runNext()['status']);
        $this->assertSame('failed', $jobs->runNext()['status']);
        $this->assertNull($jobs->runNext()); // empty-queue read path captured too
        $db->onQuery(static fn () => null);

        // EXPLAIN covers the read and claim/mark shapes; the INSERT is pinned by
        // presence below (EXPLAIN QUERY PLAN rejects nothing here, but the write
        // is not the interesting plan).
        $checked = array_values(array_unique(array_filter($sql, static fn (string $q): bool =>
            (str_starts_with(ltrim($q), 'SELECT') || str_starts_with(ltrim($q), 'UPDATE')) && str_contains($q, 'jobs'))));
        $this->assertNotEmpty($checked);
        // The gate is non-vacuous: each statement shape Jobs owns is present.
        $all = implode("\n", $sql);
        $this->assertStringContainsString('INSERT INTO jobs', $all);
        $this->assertStringContainsString('FROM jobs WHERE status', $all);
        $this->assertStringContainsString('UPDATE jobs SET status', $all);
        $this->assertStringContainsString('WHERE id = ?', $all);

        foreach ($checked as $q) {
            $plan = array_column($db->all('EXPLAIN QUERY PLAN ' . $q), 'detail');
            foreach ($plan as $line) {
                $this->assertStringStartsNotWith('SCAN jobs', $line, "{$q}\n" . implode("\n", $plan));
                $this->assertStringNotContainsString('TEMP B-TREE', $line, "{$q}\n" . implode("\n", $plan));
            }
        }
    }
}

final class PlanQuietJob
{
    public function handle(array $payload): void {}
}

final class PlanBoomJob
{
    public function handle(array $payload): void
    {
        throw new \RuntimeException('plan test failure path');
    }
}
