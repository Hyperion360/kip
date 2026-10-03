<?php // tests/Blog/ConfigTest.php
namespace Kip\Tests\Blog;
use PHPUnit\Framework\TestCase;

// The shipped example config is documentation by other means: this pin keeps
// the guest-comment flood cap from silently disappearing (the limiter's own
// mechanics are covered by tests/App/RateLimitFlowTest.php).
final class ConfigTest extends TestCase
{
    public function test_the_example_blog_caps_guest_comment_floods(): void
    {
        $config = require dirname(__DIR__, 2) . '/examples/blog/config.php';
        $this->assertSame(['max' => 30, 'window' => 60], $config['rate_limit']['comments'] ?? null);
        $this->assertFileExists(
            dirname(__DIR__, 2) . '/examples/blog/app/migrations/010_create_rate_limits.php',
            'the limiter counts in the rate_limits table, which the app must ship'
        );
    }
}
