<?php // app/src/Text.php
namespace App;

/**
 * Presentational formatting for stored plain text: reading-friendly dates and
 * a first-paragraph excerpt for list pages. Pure functions; views escape at
 * print time, never here. preg with /u only: the framework contract is php +
 * ext-pdo, so no mb_* and no other extensions.
 */
final class Text
{
    /** "2026-09-29T18:20:00+00:00" -> "September 29, 2026" (UTC). */
    public static function dateLine(string $created): string
    {
        return gmdate('F j, Y', strtotime($created) ?: 0);
    }

    /** -> "September 29, 2026 · 18:20" (24-hour clock, as the design shows). */
    public static function dateTimeLine(string $created): string
    {
        return gmdate('F j, Y · H:i', strtotime($created) ?: 0);
    }

    /**
     * The first paragraph of a plain-text body, folded to one line and cut on
     * a word boundary near $chars characters; an ellipsis marks a cut.
     */
    public static function excerpt(string $body, int $chars = 160): string
    {
        // /u everywhere, matching the framework's own posture: valid multibyte
        // text is parsed as codepoints (never split mid-character), and text
        // with invalid UTF-8 degenerates to an empty excerpt exactly the way
        // View::e() empties it at print time. Byte-mode splits were rejected in
        // review: \R matches byte 0x85, which is the tail of many multibyte
        // characters, and would corrupt valid text like "Zaą".
        $paragraph = (string) (preg_split('/\R{2,}/u', trim($body))[0] ?? '');
        $text = (string) preg_replace('/\s+/u', ' ', $paragraph);
        if ($text === '' || preg_match('/^.{0,' . max(1, $chars) . '}/us', $text, $m) !== 1) {
            return $text;
        }
        $head = $m[0];
        if ($head === $text) {
            return $text; // shorter than the cap: nothing to cut
        }
        // Back up to the last full word; an unbroken run takes the hard cut.
        $cut = preg_match('/^(.*) /us', $head, $s) === 1 ? $s[1] : $head;
        return $cut . '…';
    }
}
