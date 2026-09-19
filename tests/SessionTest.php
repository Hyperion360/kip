<?php // tests/SessionTest.php
namespace Kip\Tests;
use Kip\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_get_set_and_forget(): void
    {
        $store = [];
        $s = new Session($store);
        $s->set('user_id', 7);
        $this->assertSame(7, $s->get('user_id'));
        $s->forget('user_id');
        $this->assertNull($s->get('user_id'));
    }

    public function test_csrf_token_is_stable_and_validates(): void
    {
        $store = [];
        $s = new Session($store);
        $t = $s->csrfToken();
        $this->assertSame($t, $s->csrfToken());       // same token within session
        $this->assertTrue($s->validateCsrf($t));
        $this->assertFalse($s->validateCsrf('forged'));
        $this->assertFalse($s->validateCsrf(null));
    }

    public function test_rotate_csrf_issues_a_new_token(): void // v0.1.1 T4
    {
        $store = [];
        $s = new Session($store);
        $old = $s->csrfToken();
        $s->rotateCsrf();
        $this->assertNotSame($old, $s->csrfToken());
        $this->assertFalse($s->validateCsrf($old));
    }

    public function test_lazy_session_does_not_start_until_touched(): void // v0.2 T1
    {
        $started = 0;
        $store = [];
        $s = Session::lazy(function () use (&$started, &$store): array {
            $started++;
            return $store; // stands in for session_start() + $_SESSION
        });
        $this->assertSame(0, $started);          // construction is free
        $this->assertNull($s->get('user_id'));   // first touch...
        $this->assertSame(1, $started);          // ...starts exactly once
        $s->set('k', 'v');
        $this->assertSame(1, $started);
    }

    public function test_lazy_session_reads_and_writes_the_started_store(): void
    {
        $store = ['user_id' => 7];
        $s = Session::lazy(function () use (&$store): array { return $store; });
        $this->assertSame(7, $s->get('user_id'));
        $s->set('x', 1);
        $this->assertSame(1, $s->get('x'));
    }

    public function test_peek_never_starts_a_lazy_session(): void
    {
        $started = 0;
        $s = Session::lazy(function () use (&$started): array { $started++; static $x = []; return $x; });
        $this->assertNull($s->peek('user_id'));
        $this->assertSame(0, $started);
    }

    public function test_touch_count_increments_on_access_only(): void
    {
        $store = [];
        $s = new Session($store);
        $this->assertSame(0, $s->touchCount());
        $s->get('a');
        $s->set('b', 1);
        $this->assertSame(2, $s->touchCount());
        $s->peek('b'); // peek is tap-free by design (direct read, no data()). Audit reads never mark a page personal
        $this->assertSame(2, $s->touchCount());
    }
}
