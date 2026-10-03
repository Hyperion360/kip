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
        $this->assertSame('kip_theme=light; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly', Theme::cookie('kip_theme', 'light'));
        $this->assertSame('kip_theme=; Path=/; Max-Age=0; SameSite=Lax; HttpOnly', Theme::clearCookie('kip_theme'));
    }

    public function test_cookie_wire_string_carries_httponly(): void
    {
        $this->assertSame('kip_theme=dark; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly', Theme::cookie('kip_theme', 'dark'));
    }

    public function test_secure_request_flag_appends_secure(): void
    {
        $this->assertSame('kip_theme=dark; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly; Secure', Theme::cookie('kip_theme', 'dark', secure: true));
        $this->assertSame('kip_theme=; Path=/; Max-Age=0; SameSite=Lax; HttpOnly; Secure', Theme::clearCookie('kip_theme', true));
    }

    public function test_hostile_cookie_names_and_values_are_refused(): void
    {
        foreach (
            [
                ['kip_theme', 'dark; Domain=.evil.com'],
                ['kip_theme', "dark\r\nSet-Cookie: x=y"],
                ['kip theme', 'dark'],
                ['kip_theme', 'da rk'],
            ] as [$name, $value]
        ) {
            try {
                Theme::cookie($name, $value);
                $this->fail("cookie '$name'='$value' passed validation");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }
}
