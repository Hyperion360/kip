<?php // src/Http/Request.php
namespace Kip\Http;

final class Request
{
    public readonly string $path;

    /**
     * @param array<array-key, mixed>  $get     ?0=x yields an integer key
     * @param array<array-key, mixed>  $post
     * @param array<array-key, mixed>  $cookies a name like a[b] yields an array value
     * @param array<string, string>    $headers lowercase keys, built by fromGlobals (v0.2 T2)
     * @param array<array-key, mixed>  $files   $_FILES-shaped (v0.3 T8)
     */
    public function __construct(
        public readonly string $method,
        string $path,
        public readonly array $get,
        public readonly array $post,
        public readonly array $cookies,
        public readonly string $ip = '',
        public readonly array $headers = [],
        public readonly array $files = [],
    ) {
        $p = rtrim($path, '/');
        $this->path = $p === '' ? '/' : $p;
    }

    /** @param array<array-key, mixed>|null $server */
    public static function fromGlobals(?array $server = null, bool $trustedProxy = false): self
    {
        $server ??= $_SERVER;
        $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $ip = $server['REMOTE_ADDR'] ?? '';
        if ($trustedProxy && isset($server['HTTP_X_FORWARDED_FOR'])) {
            // One trusted hop: the proxy APPENDS the true client IP, so the LAST element
            // is the only value the client cannot control (review D3, first-element
            // trust would let attackers rotate fake IPs past the login throttle).
            $parts = array_map('trim', explode(',', $server['HTTP_X_FORWARDED_FOR']));
            $candidate = end($parts);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidate; // only a syntactically valid IP may replace REMOTE_ADDR
            }
        }
        $headers = [];
        foreach ($server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        return new self($server['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $_POST, $_COOKIE, $ip, $headers, $_FILES);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    /** Form-field convenience: fetch, cast, trim (review 3A). */
    public function str(string $key): string
    {
        return trim((string) $this->input($key));
    }

    /** POST-only variant of str(), for credentials and CSRF tokens (QA T6). */
    public function postStr(string $key): string
    {
        $raw = $this->post[$key] ?? '';
        return is_string($raw) ? trim($raw) : '';
    }

    /** Case-insensitive header lookup; lowercase keys internally (v0.2 T2). */
    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? null;
        return is_string($v) ? $v : null;
    }

    /**
     * One successfully-uploaded $_FILES entry, or null (absent / errored / malformed).
     *
     * @return array<array-key, mixed>|null
     */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null)) {
            return null;
        }
        return $f;
    }
}
