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
            || self::hasDotSegment($raw)
            // One decode, matching the browser: %2e%2e normalizes to .. on the
            // client, so the validator must judge the decoded spelling too.
            // A second decode would judge spellings no browser produces.
            // The CRLF/backslash checks above deliberately judge the raw
            // spelling only: Location carries the raw bytes on the wire, and
            // Response validates that value at construction; browsers treat
            // %0d%0a as path bytes, never header separators.
            || self::hasDotSegment(rawurldecode($raw))) {
            return $fallback;
        }
        return $raw;
    }

    /** A `..` path segment at any position that would climb a directory. */
    private static function hasDotSegment(string $path): bool
    {
        return preg_match('~(?:^|/)\.\.(?:/|\?|#|$)~', $path) === 1;
    }
}
