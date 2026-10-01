<?php // src/Theme.php

declare(strict_types=1);
namespace Kip;

use Kip\Http\Request;

/** Theme-cookie mechanics shared by the admin panel and the blog example.
 *  The word list is policy: callers pass their own allowed values, so an
 *  app with reading themes (paper/sepia/night) reuses this unchanged. */
final class Theme
{
    /** The whitelisted cookie value, or null when absent/garbage (auto).
     *  @param list<string> $allowed */
    public static function current(Request $request, string $cookie = 'kip_theme', array $allowed = ['auto', 'light', 'dark']): ?string
    {
        $value = $request->cookies[$cookie] ?? '';
        return in_array($value, $allowed, true) ? $value : null;
    }

    /** The Set-Cookie wire value for a theme choice. Empty value + zero
     *  Max-Age is the "auto" deletion both shipped apps use. */
    public static function cookie(string $cookie, string $value, int $maxAge = 31536000): string
    {
        return sprintf('%s=%s; Path=/; Max-Age=%d; SameSite=Lax', $cookie, $value, $maxAge);
    }

    public static function clearCookie(string $cookie): string
    {
        return self::cookie($cookie, '', 0);
    }
}
