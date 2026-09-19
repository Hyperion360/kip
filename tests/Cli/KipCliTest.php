<?php // tests/Cli/KipCliTest.php
namespace Kip\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * bin/kip CLI contract (guide ch. 8): exit codes and usage errors. Runs against
 * the shared throwaway-app harness, no skeleton/vendor dependency, so these
 * never silently skip on a fresh clone.
 */
final class KipCliTest extends TestCase
{
    use CliAppHarness;

    protected function setUp(): void
    {
        $this->buildCliApp(withMigrations: false);
    }

    protected function tearDown(): void
    {
        $this->tearDownCliApp();
    }

    public function test_no_arguments_prints_usage_and_exits_zero(): void
    {
        [$out, $code] = $this->cli([]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage: kip [', $out);
    }

    public function test_user_create_without_email_is_usage_error(): void
    {
        [$out, $code] = $this->cli(['user:create']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip user:create', $out);
    }

    public function test_logs_prune_rejects_non_numeric_days(): void
    {
        [$out, $code] = $this->cli(['logs:prune', '--days=abc']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip logs:prune', $out);
    }

    public function test_logs_prune_rejects_zero_days(): void
    {
        [, $code] = $this->cli(['logs:prune', '--days=0']);
        $this->assertSame(1, $code);
    }

    public function test_unknown_command_prints_usage(): void
    {
        [$out, $code] = $this->cli(['frobnicate']);
        $this->assertSame(0, $code); // default arm: help, not an error
        $this->assertStringContainsString('Usage: kip [', $out);
    }
}
