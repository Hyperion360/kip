<?php // tests/DevErrorPageTest.php
namespace Kip\Tests;
use Kip\DevErrorPage;
use PHPUnit\Framework\TestCase;

final class DevErrorPageTest extends TestCase
{
    public function test_renders_a_self_contained_document_with_every_detail(): void
    {
        $e = new \RuntimeException('boom happened');
        $html = (new DevErrorPage())->render($e, 'POST', '/things/store');
        $this->assertStringStartsWith('<!DOCTYPE html>', $html, 'a real document, not a fragment');
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('boom happened', $html);
        $this->assertStringContainsString('DevErrorPageTest.php', $html); // thrown-at file:line and the trace both name it
        $this->assertStringContainsString('POST /things/store', $html);   // the request that triggered it
        $this->assertStringContainsString('<style>', $html);              // styles ship inline: no external requests
        $this->assertStringNotContainsString('src="', $html);             // nothing is fetched, offline-safe
        $this->assertStringNotContainsString('<script', $html);           // zero JavaScript
    }

    public function test_every_dynamic_value_is_escaped(): void // exception text can carry user-influenced input even in dev
    {
        $e = new \RuntimeException('<script>alert(1)</script> " onclick="x');
        $html = (new DevErrorPage())->render($e, 'GET', '/x?a=<b>');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot; onclick=&quot;x', $html);
        $this->assertStringContainsString('/x?a=&lt;b&gt;', $html);
    }

    public function test_invalid_utf8_is_substituted_not_erased(): void
    {
        // Without ENT_SUBSTITUTE, htmlspecialchars() returns an empty string
        // for invalid UTF-8 input. The escaped value must keep its readable
        // part and carry U+FFFD in place of the invalid byte.
        $e = new \RuntimeException("boom \xB1\x31");
        $html = (new DevErrorPage())->render($e, 'GET', '/x');
        $this->assertStringContainsString('boom', $html);
        $this->assertStringContainsString("\u{FFFD}1", $html);
    }
}
