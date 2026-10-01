<?php // src/Redirects.php

declare(strict_types=1);
namespace Kip;

/** Same-site redirect targets. One guarded validator instead of one copy
 *  per controller: kiption's App\Redirects, the admin theme endpoint, and
 *  the blog theme endpoint all enforced these rules by hand. */
final class Redirects
{
    /** A safe return path: leading slash (same-origin by construction), no
     *  protocol-relative spelling, no backslash, no CR/LF/NUL, no dot
     *  segments that could normalize outside the app. Query strings and
     *  fragments pass through. Anything else falls back. */
    public static function safeReturn(string $raw, string $fallback = '/'): string
    {
        if ($raw === '' || $raw[0] !== '/'
            || str_starts_with($raw, '//')
            || preg_match('/[\r\n\0\\\\]/', $raw) === 1
            || preg_match('~(?:^|/)\.\.(?:/|\?|#|$)~', $raw) === 1) {
            return $fallback;
        }
        return $raw;
    }
}
