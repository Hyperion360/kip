<?php
namespace Kip;

/** Starts the native PHP session on demand and exposes $_SESSION by reference. */
final class SessionStarter
{
    public function __construct(private bool $secureCookie = false) {}

    public function &start(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent($file, $line)) {
                throw new \RuntimeException("Cannot start session: headers already sent at {$file}:{$line}");
            }
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $this->secureCookie,
            ]);
            session_start();
        }
        return $_SESSION;
    }
}
