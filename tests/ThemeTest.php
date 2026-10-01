<?php // tests/ThemeTest.php
declare(strict_types=1);
namespace Kip\Tests;

use Kip\Theme;
use Kip\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function test_current_returns_the_whitelisted_value(): void
    {
        $this->assertSame('light', Theme::current(new Request('GET', '/', [], [], ['kip_theme' => 'light'])));
        $this->assertSame('sepia', Theme::current(new Request('GET', '/', [], [], ['reader_theme' => 'sepia']), 'reader_theme', ['paper', 'sepia', 'night']));
    }

    /** @return list<array{0: string}> */
    public static function absent(): array { return [[''], ['garbage'], ['AUTO'], ['light ']]; }

    #[DataProvider('absent')]
    public function test_absent_or_garbage_is_null(string $cookie): void
    {
        $this->assertNull(Theme::current(new Request('GET', '/', [], [], ['kip_theme' => $cookie])));
        $this->assertNull(Theme::current(new Request('GET', '/', [], [], [])));
    }

    public function test_cookie_wire_string_matches_the_shipped_format(): void
    {
        $this->assertSame('kip_theme=light; Path=/; Max-Age=31536000; SameSite=Lax', Theme::cookie('kip_theme', 'light'));
        $this->assertSame('kip_theme=; Path=/; Max-Age=0; SameSite=Lax', Theme::clearCookie('kip_theme'));
    }
}
