<?php // tests/Skeleton/LayoutPinsTest.php
namespace Kip\Tests\Skeleton;

use PHPUnit\Framework\TestCase;

// Regression: ISSUE-001: every page triggered a /favicon.ico request that
// 404'd (a console error per visit plus an audit-log row per visit).
// Found by /qa on 2026-09-30, headed Chromium against the skeleton.
// Report: .gstack/qa-reports/run-20260930T051523Z/qa-report-127-0-0-1-8095-2026-09-30.md
final class LayoutPinsTest extends TestCase
{
    /** Both shipped apps declare an empty data icon so browsers never request /favicon.ico. */
    public function test_both_layouts_suppress_the_favicon_request(): void
    {
        foreach (['skeleton', 'examples/blog'] as $app) {
            $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $app . '/app/views/layout.php');
            $this->assertStringContainsString(
                '<link rel="icon" href="data:,">',
                $layout,
                "{$app} layout must keep the empty data icon, or every page view logs a favicon 404"
            );
        }
    }
}
