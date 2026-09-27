<?php // src/Mailer.php
namespace Kip;

/**
 * Plain-text transactional mail. Three transports:
 *   log. Append to a file (dev default; tests read it)
 *   mail, PHP's mail()
 *   smtp, minimal SMTP client (STARTTLS + AUTH LOGIN), zero dependencies
 * Header injection: CR/LF (and a leading "Bcc"-forming colon) cannot survive
 * line(). Every header value is flattened to one line.
 */
final class Mailer
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function send(string $to, string $subject, string $body): void
    {
        $to = $this->line($to);
        $subject = $this->line($subject);
        $from = $this->line($this->config['from'] ?? 'noreply@localhost');
        match ($this->config['transport'] ?? 'log') {
            'log'  => $this->toLog($to, $subject, $body, $from),
            'mail' => $this->toMail($to, $subject, $body, $from),
            'smtp' => $this->toSmtp($to, $subject, $body, $from),
            default => throw new \RuntimeException('Unknown mail transport: ' . $this->config['transport']),
        };
    }

    private function line(string $v): string
    {
        return str_replace(["\r", "\n", ':'], ['', ' ', ''], trim($v)); // colon strip kills header-forming payloads after CRLF removal; RFC addr-spec locals with colons are quoted-string exotica we accept losing
    }

    private function headers(string $to, string $subject, string $from): string
    {
        return "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nDate: " . date('r')
            . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";
    }

    private function toLog(string $to, string $subject, string $body, string $from): void
    {
        $path = $this->config['log_path'] ?? throw new \RuntimeException('mail.log_path is required for the log transport');
        if (file_put_contents($path, $this->headers($to, $subject, $from) . "\r\n{$body}\r\n----\r\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Could not write mail to {$path}"); // silent loss is worse than a visible failure
        }
    }

    /** Needs a configured sendmail in the environment. Not unit-testable portably (the SMTP path is the tested one). */
    private function toMail(string $to, string $subject, string $body, string $from): void
    {
        if (!mail($to, $subject, $body, "From: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8")) {
            throw new \RuntimeException('mail() reported failure');
        }
    }

    private function toSmtp(string $to, string $subject, string $body, string $from): void
    {
        $host = $this->config['host'] ?? throw new \RuntimeException('mail.host is required for smtp');
        $port = (int) ($this->config['port'] ?? 587);
        $sock = @fsockopen($host, $port, $errno, $errstr, 5)
            ?: throw new \RuntimeException("SMTP connect to {$host}:{$port} failed: {$errstr}");
        stream_set_timeout($sock, 5);
        try {
            $this->expect($sock, 220);
            $this->say($sock, 'EHLO kip', 250);
            if ($this->config['tls'] ?? true) {
                // Pin the TLS peer to the configured host before the upgrade, an unpinned
                // STARTTLS lets a MITM harvest the AUTH LOGIN credentials that follow.
                stream_context_set_option($sock, 'ssl', 'peer_name', $host);
                stream_context_set_option($sock, 'ssl', 'verify_peer', true);
                stream_context_set_option($sock, 'ssl', 'verify_peer_name', true);
                $this->say($sock, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                $this->say($sock, 'EHLO kip', 250);
            }
            if (isset($this->config['username'])) {
                $this->say($sock, 'AUTH LOGIN', 334);
                $this->say($sock, base64_encode($this->config['username']), 334);
                $this->say($sock, base64_encode($this->config['password'] ?? ''), 235);
            }
            $fromAddr = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
            $this->say($sock, "MAIL FROM:<{$fromAddr}>", 250);
            $this->say($sock, "RCPT TO:<{$to}>", 250);
            $this->say($sock, 'DATA', 354);
            $payload = $this->headers($to, $subject, $from) . "\r\n"
                . preg_replace('/^\./m', '..', $body) . "\r\n.";   // dot-stuffing, RFC 5321 §4.5.2
            $this->say($sock, $payload, 250);
            fwrite($sock, "QUIT\r\n");
        } finally {
            fclose($sock);
        }
    }

    /** @param resource $sock */
    private function say($sock, string $cmd, int $expect): void
    {
        fwrite($sock, $cmd . "\r\n");
        $this->expect($sock, $expect);
    }

    /** @param resource $sock */
    private function expect($sock, int $code): void
    {
        $deadline = time() + 30; // whole-reply budget: trickle-fed continuations must not extend it forever
        do {
            $lineIn = (string) fgets($sock, 1024);
            if (time() > $deadline) {
                throw new \RuntimeException("SMTP reply exceeded the 30s conversation deadline (expected {$code})");
            }
        } while (isset($lineIn[3]) && $lineIn[3] === '-'); // skip multi-line continuations
        if ((int) substr($lineIn, 0, 3) !== $code) {
            throw new \RuntimeException("SMTP expected {$code}, got: " . trim($lineIn));
        }
    }
}
