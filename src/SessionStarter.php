<?php

declare(strict_types=1);
namespace Kip;

/** Starts the native PHP session on demand and exposes $_SESSION by reference. */
final class SessionStarter
{
    private const SAMESITE_VALUES = ['Lax', 'Strict', 'None'];

    /** The cookie's SameSite attribute, config-driven, Lax by default. */
    private string $samesite;

    public function __construct(private bool $secureCookie = false, string $samesite = 'Lax')
    {
        if (!in_array($samesite, self::SAMESITE_VALUES, true)) {
            throw new \RuntimeException(
                'session_cookie_samesite must be one of Lax, Strict, None; got ' . $samesite
            );
        }
        $this->samesite = $samesite;
    }

    /** @return array<string, mixed> the live session store */
    public function &start(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent($file, $line)) {
                throw new \RuntimeException("Cannot start session: headers already sent at {$file}:{$line}");
            }
            // Reject a session id the server never issued: without this, PHP adopts an
            // id planted in the cookie (login still regenerates, but guest state is shared).
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => $this->samesite,
                // SameSite=None without the Secure flag is rejected by every
                // browser, so None forces Secure regardless of the https
                // detection: a host that opts in has accepted the https
                // requirement that comes with it.
                'secure'   => $this->samesite === 'None' ? true : $this->secureCookie,
            ]);
            session_start();
        }
        return $_SESSION;
    }
}
