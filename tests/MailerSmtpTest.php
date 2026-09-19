<?php // tests/MailerSmtpTest.php
namespace Kip\Tests;

use Kip\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * The SMTP transport against a scripted fake server (child PHP process on an
 * ephemeral loopback port). Covers the full client conversation: EHLO,
 * optional AUTH LOGIN, envelope, DATA with dot-stuffing, QUIT, plus the
 * failure paths (refused connection, unexpected reply, failed STARTTLS).
 */
final class MailerSmtpTest extends TestCase
{
    private string $dir;
    /** @var list<resource> */
    private array $procs = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-smtp-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) { proc_terminate($p); proc_close($p); }
        set_error_handler(static fn(): bool => true);
        try {
            foreach (glob($this->dir . '/*') ?: [] as $f) unlink($f);
            rmdir($this->dir);
        } finally { restore_error_handler(); }
    }

    /** Reply strings matter: char 4 must not be '-' or expect() waits for a continuation line that never comes. */
    private const SERVER = <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr) or exit("listen-fail");
file_put_contents($argv[1], substr(strrchr(stream_socket_get_name($server, false), ':'), 1));
$conn = stream_socket_accept($server, 10) or exit("accept-fail");
stream_set_timeout($conn, 5);
$read  = static fn(): string => trim((string) fgets($conn, 4096));
$write = static function (string $s) use ($conn): void { fwrite($conn, $s . "\r\n"); };
$log = fopen($argv[3], 'w');
$mode = $argv[2];
$write('220 fake ready');
fwrite($log, $read() . "\n");                       // EHLO
if ($mode === 'auth') {
    $write('250 hello');
    fwrite($log, $read() . "\n");                   // AUTH LOGIN
    $write('334 ' . base64_encode('Username:'));
    fwrite($log, $read() . "\n");
    $write('334 ' . base64_encode('Password:'));
    fwrite($log, $read() . "\n");
    $write('235 ok');
} elseif ($mode === 'starttls') {
    $write('250 hello');
    fwrite($log, $read() . "\n");                   // STARTTLS
    $write('220 go');
    fclose($conn);                                  // plaintext peer: the client's TLS handshake must fail fast
    exit;
} else {
    $write('250 hello');
}
fwrite($log, $read() . "\n");                       // MAIL FROM
if ($mode === 'reject') { $write('554 no'); fclose($conn); exit; }
$write('250 ok');
fwrite($log, $read() . "\n");                       // RCPT TO
$write('250 ok');
fwrite($log, $read() . "\n");                       // DATA
$write('354 go');
$payload = '';
while (($line = fgets($conn, 4096)) !== false) {
    $payload .= $line;
    if (rtrim($line, "\r\n") === '.') break;        // end of DATA
}
fwrite($log, "DATA>>" . $payload);
$write('250 accepted');
fwrite($log, $read() . "\n");                       // QUIT
$write('221 bye');
fclose($conn);
PHP;

    /** @return array{0: string, 1: string} [dsn host:port, log file] */
    private function spawn(string $mode): array
    {
        $script = "{$this->dir}/server.php";
        $portFile = "{$this->dir}/port";
        $logFile = "{$this->dir}/smtp.log";
        file_put_contents($script, self::SERVER);
        $this->procs[] = proc_open(
            [PHP_BINARY, $script, $portFile, $mode, $logFile],
            [2 => ['pipe', 'w']],
            $pipes
        );
        for ($i = 0; $i < 500 && !is_file($portFile); $i++) usleep(10_000);
        $this->assertIsString(($port = @file_get_contents($portFile)) ?: null, 'fake SMTP server did not start');
        return ["127.0.0.1:{$port}", $logFile];
    }

    private function mailer(array $extra): Mailer
    {
        return new Mailer(['transport' => 'smtp', 'from' => 'noreply@kip.test', 'tls' => false, ...$extra]);
    }

    public function test_full_conversation_with_dot_stuffing(): void
    {
        [$addr, $logFile] = $this->spawn('plain');
        [$host, $port] = explode(':', $addr);
        $this->mailer(['host' => $host, 'port' => (int) $port])
            ->send('you@example.com', 'Hello', "Line one\n.dot-line starts with a dot");
        for ($i = 0; $i < 500 && !str_contains((string) ($log = @file_get_contents($logFile) ?: ''), 'QUIT'); $i++) usleep(10_000);
        $log = (string) file_get_contents($logFile);
        $this->assertStringContainsString('EHLO kip', $log);
        $this->assertStringContainsString('MAIL FROM:<noreply@kip.test>', $log);
        $this->assertStringContainsString('RCPT TO:<you@example.com>', $log);
        $this->assertStringContainsString('Subject: Hello', $log);
        $this->assertStringContainsString('..dot-line starts with a dot', $log); // RFC 5321 §4.5.2 dot-stuffing
        $this->assertStringNotContainsString("\n.dot-line", $log);
        $this->assertStringContainsString('QUIT', $log);
    }

    public function test_auth_login_sends_base64_credentials(): void
    {
        [$addr, $logFile] = $this->spawn('auth');
        [$host, $port] = explode(':', $addr);
        $this->mailer(['host' => $host, 'port' => (int) $port, 'username' => 'me@kip.test', 'password' => 's3cret'])
            ->send('you@example.com', 'S', 'B');
        for ($i = 0; $i < 500 && !str_contains((string) ($log = @file_get_contents($logFile) ?: ''), 'QUIT'); $i++) usleep(10_000);
        $log = (string) file_get_contents($logFile);
        $this->assertStringContainsString('AUTH LOGIN', $log);
        $this->assertStringContainsString(base64_encode('me@kip.test'), $log);
        $this->assertStringContainsString(base64_encode('s3cret'), $log);
        $this->assertStringNotContainsString('s3cret', $log); // never in the clear
    }

    public function test_failed_starttls_is_reported(): void
    {
        [$addr] = $this->spawn('starttls');
        [$host, $port] = explode(':', $addr);
        $m = new Mailer(['transport' => 'smtp', 'host' => $host, 'port' => (int) $port, 'tls' => true, 'from' => 'noreply@kip.test']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STARTTLS negotiation failed');
        $m->send('you@example.com', 'S', 'B');
    }

    public function test_unexpected_reply_code_throws(): void
    {
        [$addr] = $this->spawn('reject');
        [$host, $port] = explode(':', $addr);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP expected 250, got: 554 no');
        $this->mailer(['host' => $host, 'port' => (int) $port])->send('you@example.com', 'S', 'B');
    }

    public function test_refused_connection_throws(): void
    {
        // Grab an ephemeral port and close the listener. Nothing is listening there.
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SMTP connect .* failed/');
        $this->mailer(['host' => '127.0.0.1', 'port' => $port])->send('you@example.com', 'S', 'B');
    }
}
