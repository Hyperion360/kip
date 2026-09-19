<?php // src/Session.php
namespace Kip;

final class Session
{
    private bool $isLazy = false;           // review D4: explicit mode, not a nulled reference
    private ?array $eager;
    private SessionStarter|\Closure|null $starter = null;
    private ?array $lazyData = null;
    private bool $started = false;
    private int $touches = 0;

    public function __construct(array &$data)
    {
        $this->eager = &$data;
    }

    /** Deferred session: $starter runs session_start() (or a test stand-in) on first access and returns the live store. */
    public static function lazy(SessionStarter|callable $starter): self
    {
        $throwaway = [];
        $s = new self($throwaway);          // eager stays bound to a throwaway array, never read in lazy mode
        $s->isLazy = true;
        $s->starter = $starter instanceof SessionStarter ? $starter : \Closure::fromCallable($starter);
        return $s;
    }

    /** @return array the live session store (starting it if lazy and untouched) */
    private function &data(): array
    {
        $this->touches++;
        if (!$this->isLazy) {
            return $this->eager;
        }
        if (!$this->started) {
            if ($this->starter instanceof SessionStarter) {
                $this->lazyData = &$this->starter->start();
            } else {
                $this->lazyData = ($this->starter)();
            }
            $this->started = true;
        }
        return $this->lazyData;
    }

    public function get(string $key, mixed $default = null): mixed { return $this->data()[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data()[$key] = $value; }
    public function forget(string $key): void { unset($this->data()[$key]); }
    public function csrfToken(): string { return $this->data()['_csrf'] ??= bin2hex(random_bytes(32)); }
    public function validateCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->data()['_csrf'] ?? '', $token);
    }
    public function rotateCsrf(): void { $this->data()['_csrf'] = bin2hex(random_bytes(32)); }

    /** Non-starting read: returns null on a lazy session that was never opened.
     *  The audit logger MUST use this. A plain get() would start a session
     *  (and set a cookie) for every guest, defeating lazy sessions entirely. */
    public function peek(string $key): mixed
    {
        if ($this->isLazy) {
            return $this->started ? ($this->lazyData[$key] ?? null) : null;
        }
        return $this->eager[$key] ?? null;   // direct read: no data(), no touch, no start
    }

    /** Monotonic count of data() accesses. The kernel snapshots it around process()
     *  to decide "did this render touch the session?" (works for eager AND lazy,
     *  and for one App serving many requests, unlike a boolean flag). */
    public function touchCount(): int { return $this->touches; }
}
