<?php // tests/Cli/KipCliBuildTest.php
namespace Kip\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * The `build` arm of bin/kip: manual, local, opt-in (guide ch. 8 and ch. 10
 * own the contract). These cases exercise the wiring deterministically and
 * never invoke Docker: the usage line lists the command, and a build that
 * cannot even prepare follows the shared failure contract (kip: prefix,
 * exit 1, no trace). The full prepare-compile-smoke flow only ever runs when
 * a human types the command.
 */
final class KipCliBuildTest extends TestCase
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

    public function test_usage_lists_build(): void
    {
        [$out, $code] = $this->cli([]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('build', $out);
    }

    public function test_a_build_that_cannot_prepare_follows_the_failure_contract(): void
    {
        // No composer.json (the harness ships none): the prepare step refuses
        // before anything runs, so the arm's whole chain is proven wired
        // without Docker ever appearing.
        if (is_file($this->cliApp . '/composer.json')) unlink($this->cliApp . '/composer.json');
        [$out, $code] = $this->cli(['build']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringContainsString('composer.json', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
    }
}
