<?php // tests/SessionStarterTest.php
namespace Kip\Tests;

use Kip\SessionStarter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * SessionStarter wraps the native session. Each test runs in its own process
 * because a PHP session, once active, cannot be restarted in-process.
 */
final class SessionStarterTest extends TestCase
{
    public function test_start_activates_session_and_returns_session_by_reference(): void
    {
        $starter = new SessionStarter();
        $store = &$starter->start();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $store['probe'] = 'x';
        $this->assertSame('x', $_SESSION['probe']); // true reference, not a copy
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cookie_flags_are_httponly_lax(): void
    {
        (new SessionStarter())->start();
        $params = session_get_cookie_params();
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertFalse($params['secure']); // default: plain HTTP dev server
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_secure_cookie_when_configured(): void
    {
        (new SessionStarter(secureCookie: true))->start();
        $this->assertTrue(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_start_is_idempotent_when_session_already_active(): void
    {
        $one = &(new SessionStarter())->start();
        $one['marker'] = 1;
        $two = &(new SessionStarter())->start(); // second start must not wipe state
        $this->assertSame(1, $two['marker'] ?? null);
    }
}
