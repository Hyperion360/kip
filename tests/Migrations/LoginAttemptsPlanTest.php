<?php // tests/Migrations/LoginAttemptsPlanTest.php
namespace Kip\Tests\Migrations;
use Kip\{Auth, Database, Session};
use Kip\Migrations\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every login_attempts query Kip\Auth issues must plan as an index SEARCH, never a
 * SCAN, once an app's own migrations have run. The SQL is captured from Auth itself
 * through the onQuery tap, so a query rewritten in Auth is checked as written.
 */
final class LoginAttemptsPlanTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function apps(): array
    {
        $root = dirname(__DIR__, 2);
        return ['skeleton' => [$root . '/skeleton/app/migrations'], 'blog' => [$root . '/examples/blog/app/migrations']];
    }

    #[DataProvider('apps')]
    public function test_throttle_queries_never_scan_login_attempts(string $migrations): void
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, $migrations))->migrate();
        $store = [];
        $auth = new Auth($db, new Session($store), static function (): void {});
        $auth->register('known@x.y', 'right-password');

        $sql = [];
        $db->onQuery(function (string $q) use (&$sql): void { $sql[] = $q; });
        $auth->attempt('ghost@x.y', 'whatever', '10.0.0.1');        // unknown email: throttle check + record
        $auth->attempt('known@x.y', 'right-password', '10.0.0.1');  // success: clears the email's rows
        $auth->resetThrottled('known@x.y', '10.0.0.1');
        $db->onQuery(static fn () => null);

        $checked = array_unique(array_filter($sql, static fn (string $q): bool =>
            str_contains($q, 'login_attempts') && !str_starts_with(ltrim($q), 'INSERT')));
        $this->assertNotEmpty($checked);
        foreach ($checked as $q) {
            // The kind-column probe reads one row by design, and fails to plan on an
            // app without the column (which is how Auth detects that).
            if (str_contains($q, 'LIMIT 1')) continue;
            $plan = array_column($db->all('EXPLAIN QUERY PLAN ' . $q), 'detail');
            foreach ($plan as $line) {
                $this->assertStringStartsNotWith('SCAN login_attempts', $line, "{$q}\n" . implode("\n", $plan));
            }
        }
    }
}
