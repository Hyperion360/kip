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

    /** @var array<string, string> */
    public readonly array $headers;

    /** @param array<string, string> $headers */
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

    public function withHeader(string $name, string $value): self
    {
        self::assertHeaderSafe($name, $value);
        return new self($this->body, $this->status, [...$this->headers, $name => $value]);
    }

    /** Response splitting dies here, at construction: PHP's header() would only reject the value at send time. */
    private static function assertHeaderSafe(string $name, mixed $value): void
    {
        if (!is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException("Header {$name} must be a string without CR/LF");
        }
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $n => $v) { header("$n: $v"); }
        echo $this->body;
    }
}
