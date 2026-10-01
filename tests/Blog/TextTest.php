<?php // tests/Blog/TextTest.php
namespace Kip\Tests\Blog;

use App\Text;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/examples/blog/app/src/Text.php';

// Presentational formatting for stored plain text. Dates render in UTC on
// purpose: gmdate() is deterministic regardless of the machine timezone.
final class TextTest extends TestCase
{
    public function test_excerpt_returns_the_first_paragraph(): void
    {
        $this->assertSame('First paragraph here', Text::excerpt("First paragraph here\n\nSecond one"));
    }

    public function test_excerpt_collapses_whitespace_and_newlines(): void
    {
        $this->assertSame('a b c', Text::excerpt("  a\n\tb\r\n  c  "));
    }

    public function test_excerpt_leaves_short_bodies_alone(): void
    {
        $this->assertSame('Short', Text::excerpt('Short'));
    }

    public function test_excerpt_cuts_on_a_word_boundary_with_an_ellipsis(): void
    {
        $body = str_repeat('word ', 50); // 250 chars, all one paragraph
        $excerpt = Text::excerpt($body);
        $this->assertTrue(str_ends_with($excerpt, '…'), $excerpt);
        $this->assertLessThanOrEqual(161, strlen($excerpt) - strlen('…'));
        $this->assertStringStartsWith('word word', $excerpt);
        $this->assertTrue(!str_ends_with($excerpt, ' '), 'no trailing space before the ellipsis');
    }

    public function test_excerpt_hard_cuts_text_with_no_spaces(): void
    {
        $excerpt = Text::excerpt(str_repeat('x', 300));
        $this->assertSame(str_repeat('x', 160) . '…', $excerpt);
    }

    public function test_excerpt_of_empty_body_is_empty(): void
    {
        $this->assertSame('', Text::excerpt(''));
    }

    public function test_excerpt_passes_a_multibyte_paragraph_boundary_intact(): void
    {
        // \R must not match byte 0x85 inside a multibyte character: "ą" ends
        // with 0x85, and a byte-mode split would corrupt it (review F3).
        $excerpt = Text::excerpt("Załącznie\n\nNext paragraph with enough text after it to be clearly separate and long enough to matter here.");
        $this->assertSame('Załącznie', $excerpt);
    }

    public function test_excerpt_of_invalid_utf8_degenerates_to_empty(): void
    {
        // The framework posture: View::e() returns "" for invalid UTF-8, so the
        // excerpt degrades the same way instead of emitting broken bytes.
        $this->assertSame('', Text::excerpt("Bad \xff\xfe body"));
    }

    public function test_date_line_formats_month_day_year(): void
    {
        $this->assertSame('September 29, 2026', Text::dateLine('2026-09-29T18:20:00+00:00'));
    }

    public function test_date_time_line_formats_with_24h_clock(): void
    {
        $this->assertSame('September 29, 2026 · 18:20', Text::dateTimeLine('2026-09-29T18:20:00+00:00'));
    }
}
