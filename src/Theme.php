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
     *  Max-Age is the "auto" deletion both shipped apps use. HttpOnly always:
     *  the preference never needs script access. Secure rides the request's
     *  scheme fact, so an http dev server and an https deployment both get the
     *  right cookie. Name and value are validated here, not just at the
     *  callers: this class is the shared safe path, and the first future caller
     *  that feeds it request data must fail loud instead of injecting cookie
     *  attributes. */
    public static function cookie(string $cookie, string $value, int $maxAge = 31536000, bool $secure = false): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $cookie) !== 1
            || preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1) {
            throw new \InvalidArgumentException('Theme cookie name and value must be cookie tokens (letters, digits, underscore, dash)');
        }
        return sprintf('%s=%s; Path=/; Max-Age=%d; SameSite=Lax; HttpOnly%s', $cookie, $value, $maxAge, $secure ? '; Secure' : '');
    }

    public static function clearCookie(string $cookie, bool $secure = false): string
    {
        return self::cookie($cookie, '', 0, $secure);
    }
}
