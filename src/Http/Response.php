<?php // src/Http/Response.php
namespace Kip\Http;

final class Response
{
    private const DEFAULT_HEADERS = [
        'Content-Type'           => 'text/html; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
    ];

    /** @var array<string, string> */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        array $headers = [],
    ) {
        $this->headers = [...self::DEFAULT_HEADERS, ...$headers];
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, [...$this->headers, $name => $value]);
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
