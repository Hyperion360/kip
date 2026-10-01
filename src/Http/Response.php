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
            self::assertHeaderSafe((string) $name, $value);
        }
        $this->headers = [...self::DEFAULT_HEADERS, ...$headers];
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
        return new self($this->body, $this->status, [...$this->headers, $name => $value]);
    }

    /** Append a second value under the same header name (Set-Cookie is the
     *  canonical case: one Response, several cookies). The existing value,
     *  scalar or list, becomes the first leaf/leaves. */
    public function withAddedHeader(string $name, string $value): self
    {
        self::assertHeaderSafe($name, $value);
        $existing = $this->headers[$name] ?? [];
        $merged = is_array($existing) ? [...$existing, $value] : [$existing, $value];
        return new self($this->body, $this->status, [...$this->headers, $name => $merged]);
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
                header("$n: $v");
            }
        }
        echo $this->body;
    }
}
