<?php // src/Http/Request.php

declare(strict_types=1);
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
     * @param string                   $body    raw request body (JSON battery); '' when none
     * @param bool                     $secure  the request arrived over https (HTTPS/REQUEST_SCHEME, or X-Forwarded-Proto behind a trusted proxy)
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
        public readonly string $body = '',
        public readonly bool $secure = false,
    ) {
        // Stored as given: a trailing slash must reach the router and 404 there,
        // not be silently aliased onto the canonical URL. Only the empty path
        // normalizes to '/'.
        $this->path = $path === '' ? '/' : $path;
    }

    /** @param array<array-key, mixed>|null $server */
    public static function fromGlobals(?array $server = null, bool $trustedProxy = false): self
    {
        $server ??= $_SERVER;
        // Not parse_url(): it reads '//x' as a protocol-relative host and returns
        // no path, which served the home page for '//browse'. Strip the query
        // string by hand; the router's whitelist judges the rest.
        $uri = $server['REQUEST_URI'] ?? '/';
        $q = strpos($uri, '?');
        $path = $q === false ? $uri : substr($uri, 0, $q); // '' normalizes in the constructor below
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
        // CGI and FPM expose the content type as the bare CONTENT_TYPE key, not
        // HTTP_CONTENT_TYPE; without this fill a JSON POST's guard would never
        // see its media type. An HTTP_CONTENT_TYPE sweep result always wins.
        if (!isset($headers['content-type']) && isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }
        // The raw body only exists in a web SAPI; CLI (phpunit, bin/kip) has no
        // request body, and reading php://input there depends on stdin wiring
        // that varies across builds. Tests inject the body explicitly.
        $body = PHP_SAPI === 'cli' ? '' : (string) file_get_contents('php://input');
        return new self($server['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $_POST, $_COOKIE, $ip, $headers, $_FILES, $body, self::secureFromServer($server, $trustedProxy));
    }

    /** The https fact, one rule for every consumer: the session cookie's
     *  Secure flag (the front controllers, before a Request exists) and
     *  Request::$secure. HTTPS or REQUEST_SCHEME decides; X-Forwarded-Proto
     *  counts only behind a trusted proxy, and only its LAST comma-separated
     *  element, because proxies append and an attacker-supplied first
     *  element must not decide (the same D3 rule as X-Forwarded-For).
     *
     * @param array<array-key, mixed>|null $server
     */
    public static function secureFromServer(?array $server = null, bool $trustedProxy = false): bool
    {
        $server ??= $_SERVER;
        $https = (string) ($server['HTTPS'] ?? '');
        if ($https !== '' && $https !== 'off') return true;
        if (strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https') return true;
        if (!$trustedProxy) return false;
        $proto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === '') return false;
        $parts = array_map('trim', explode(',', $proto));
        return end($parts) === 'https';
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
     * The request body parsed as JSON: an array for object and array
     * documents, null for an empty body, malformed JSON, invalid UTF-8, or a
     * valid scalar/null document (the body contract is an object or array;
     * decode is strict, no substitution, so a broken body can be answered
     * with a 400 instead of silently repaired).
     *
     * @return array<array-key, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
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
