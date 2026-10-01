<?php // tests/RedirectsTest.php
declare(strict_types=1);
namespace Kip\Tests;

use Kip\Redirects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectsTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function unsafe(): array
    {
        return [
            ['', '/'],
            ['no-slash', '/'],
            ['https://evil.test/x', '/'],
            ['//evil.test/x', '/'],
            ["/safe\r\nX-Evil: 1", '/'],
            ['/a\\b', '/'],
            ['/a/../b', '/'],
            ['/..', '/'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_values_fall_back(string $raw, string $fallback): void
    {
        $this->assertSame($fallback, Redirects::safeReturn($raw));
    }

    public function test_safe_paths_pass_with_query(): void
    {
        $this->assertSame('/story/view/x?y=1#z', Redirects::safeReturn('/story/view/x?y=1#z'));
        $this->assertSame('/admin?status=2', Redirects::safeReturn('/admin?status=2', '/admin'));
    }
}
