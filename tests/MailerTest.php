<?php // tests/MailerTest.php
namespace Kip\Tests;
use Kip\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'kipmail');
    }

    protected function tearDown(): void { @unlink($this->log); }

    public function test_log_transport_writes_message(): void
    {
        $m = new Mailer(['transport' => 'log', 'log_path' => $this->log, 'from' => 'noreply@kip.test']);
        $m->send('you@example.com', 'Hello', 'Body line');
        $out = file_get_contents($this->log);
        $this->assertStringContainsString('To: you@example.com', $out);
        $this->assertStringContainsString('Subject: Hello', $out);
        $this->assertStringContainsString('Body line', $out);
    }

    public function test_header_injection_is_stripped(): void
    {
        $m = new Mailer(['transport' => 'log', 'log_path' => $this->log, 'from' => 'noreply@kip.test']);
        $m->send("you@example.com\r\nBcc: victim@example.com", "Hi\nX-Evil: 1", 'B');
        $out = file_get_contents($this->log);
        // line() maps \r→'', \n→' ', ':'→''. The payload survives only as inert
        // words INSIDE the To/Subject values, never as new header lines.
        $this->assertStringNotContainsString('Bcc:', $out);
        $this->assertStringNotContainsString("\nX-Evil", $out);
        $this->assertStringContainsString('To: you@example.com Bcc victim@example.com', $out);
    }

    public function test_unknown_transport_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Mailer(['transport' => 'pigeon']))->send('a@b.c', 's', 'b');
    }

    public function test_default_transport_is_log(): void
    {
        $m = new Mailer(['log_path' => $this->log, 'from' => 'noreply@kip.test']);
        $m->send('you@example.com', 'S', 'B');
        $this->assertStringContainsString('To: you@example.com', (string) file_get_contents($this->log));
    }

    public function test_unwritable_log_path_throws(): void // silent mail loss is worse than a visible failure
    {
        $m = new Mailer(['transport' => 'log', 'log_path' => '/nonexistent-dir/kip-mail.log', 'from' => 'noreply@kip.test']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not write mail');
        @$m->send('you@example.com', 'S', 'B'); // the stream warning is expected; the throw is the contract
    }
}
