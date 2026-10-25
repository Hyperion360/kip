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
    public function test_an_unknown_session_id_is_replaced_not_adopted(): void // pentest: fixation hygiene
    {
        ini_set('session.use_strict_mode', '0');           // PHP's own default
        $planted = 'planted' . bin2hex(random_bytes(8));   // an id no server ever issued
        $_COOKIE[session_name()] = $planted;
        (new SessionStarter())->start();
        $this->assertNotSame($planted, session_id());
        session_destroy();
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_samesite_is_configurable(): void
    {
        (new SessionStarter(samesite: 'Strict'))->start();
        $this->assertSame('Strict', session_get_cookie_params()['samesite']);
        session_destroy();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_samesite_none_forces_secure(): void
    {
        // SameSite=None without Secure is rejected by every browser, so the
        // starter forces the flag regardless of the https detection.
        (new SessionStarter(secureCookie: false, samesite: 'None'))->start();
        $params = session_get_cookie_params();
        $this->assertSame('None', $params['samesite']);
        $this->assertTrue($params['secure']);
        session_destroy();
    }

    public function test_an_unknown_samesite_value_is_a_startup_refusal(): void
    {
        try {
            new SessionStarter(samesite: 'lax'); // case-exact: junk is junk
            $this->fail('expected the junk SameSite value to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Lax, Strict, None', $e->getMessage());
        }
    }
}
