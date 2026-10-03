<?php // src/Http/Response.php

declare(strict_types=1);
namespace Kip\Http;

final class Response
{
    private const DEFAULT_HEADERS = [
        'Content-Type'           => 'text/html; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        // Pinned rather than left to the browser: a page with a token in its URL
        // (/auth/reset/...) must never send that path to another site.
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        // Only directives no Kip page needs to loosen: no <base> hijack, no plugins.
        // No script-src, no frame-ancestors (it would override an app's X-Frame-Options: DENY).
        'Content-Security-Policy' => "base-uri 'self'; object-src 'none'",
    ];

    /** @var array<string, string|list<string>> */
    public readonly array $headers;

    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                // PHP turns numeric-string keys into ints, so an int key here means
                // someone passed a flat list where the map shape was required.
                throw new \InvalidArgumentException('Header names must be strings: pass a name => value map, not a list');
            }
            // Double-quoted on purpose: the token alphabet includes an apostrophe,
            // and a single-quoted pattern would need an escaping backslash.
            if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/", $name) !== 1) {
                throw new \InvalidArgumentException("Header name {$name} is not a valid field name (RFC 9110 token)");
            }
            self::assertHeaderSafe((string) $name, $value);
            self::assertSingleValued((string) $name, $value);
        }
        $merged = [...self::DEFAULT_HEADERS, ...$headers];
        // Field names are case-insensitive (RFC 9110 5.1), PHP map keys are not:
        // two spellings of one field would both reach the wire ('content-type'
        // beside the default 'Content-Type', say). Fail loud at the boundary,
        // the assertHeaderSafe doctrine, with defaults included after the merge.
        $spellings = [];
        foreach ($merged as $name => $_) {
            $lower = strtolower((string) $name);
            if (isset($spellings[$lower])) {
                throw new \InvalidArgumentException("Header names {$spellings[$lower]} and {$name} differ only by case");
            }
            $spellings[$lower] = (string) $name;
        }
        $this->headers = $merged;
    }

    /**
     * The headers every Response carries unless the app overrides them. PageCache
     * strips these at store time, so a cache HIT re-derives them from the framework
     * version reading it, not from whatever was current when the page was stored.
     *
     * @return array<string, string>
     */
    public static function defaultHeaders(): array
    {
        return self::DEFAULT_HEADERS;
    }

    /** @param string|list<string> $value */
    public function withHeader(string $name, string|array $value): self
    {
        self::assertHeaderSafe($name, $value);
        self::assertSingleValued($name, $value);
        // Field names are case-insensitive (RFC 9110 5.1): the replace lands on
        // the existing key's spelling, never beside it.
        $key = $this->keyFor($name) ?? $name;
        return new self($this->body, $this->status, [...$this->headers, $key => $value]);
    }

    /** Append a second value under the same header name (Set-Cookie is the
     *  canonical case: one Response, several cookies). The existing value,
     *  scalar or list, becomes the first leaf/leaves. */
    public function withAddedHeader(string $name, string $value): self
    {
        self::assertHeaderSafe($name, $value);
        if (strcasecmp($name, 'Location') === 0) {
            throw new \InvalidArgumentException("Header {$name} must stay single-valued: Location cannot carry added values");
        }
        $key = $this->keyFor($name) ?? $name;
        $existing = $this->headers[$key] ?? [];
        $merged = is_array($existing) ? [...$existing, $value] : [$existing, $value];
        return new self($this->body, $this->status, [...$this->headers, $key => $merged]);
    }

    /** Response splitting dies here, at construction: PHP's header() would
     *  only reject the value at send time. A value may be one string or a
     *  FLAT list of strings; a nested list or any non-string leaf, or CR/LF
     *  in any leaf, throws. One level only: recursion here would silently
     *  flatten nested arrays instead of rejecting them (codex review). */
    private static function assertHeaderSafe(string $name, mixed $value): void
    {
        if (is_string($value)) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException("Header {$name} must be a string without CR/LF");
            }
            return;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Header {$name} must be a string or a list of strings");
        }
        foreach ($value as $leaf) {
            if (!is_string($leaf)) {
                throw new \InvalidArgumentException("Header {$name} must be a flat list of strings");
            }
            if (preg_match('/[\r\n]/', $leaf) === 1) {
                throw new \InvalidArgumentException("Header {$name} must be a string without CR/LF");
            }
        }
    }

    /** RFC 9110 single-valued fields: Location names exactly one target, and a
     *  list would emit two redirect targets. Fail loud at the boundary. */
    private static function assertSingleValued(string $name, mixed $value): void
    {
        if (!is_array($value)) return;
        if (strcasecmp($name, 'Location') === 0) {
            throw new \InvalidArgumentException("Header {$name} must be a single string: Location does not accept a list");
        }
    }

    /** The existing map key this field name refers to, case-insensitively, or
     *  null when the response carries no such field yet. */
    private function keyFor(string $name): ?string
    {
        foreach ($this->headers as $key => $_) {
            if (strcasecmp((string) $key, $name) === 0) return (string) $key;
        }
        return null;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $n => $v) {
            if (is_array($v)) {
                foreach ($v as $leaf) { header("$n: $leaf", false); }
            } else {
                // Set-Cookie always appends, a scalar value included: replace
                // mode would drop every earlier Set-Cookie header, one queued
                // by a session_start() included, losing the session cookie.
                header("$n: $v", strcasecmp($n, 'Set-Cookie') !== 0);
            }
        }
        echo $this->body;
    }
}
