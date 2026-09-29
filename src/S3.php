<?php // src/S3.php

declare(strict_types=1);
namespace Kip;

/**
 * Minimal S3-compatible uploader (AWS S3, Backblaze B2's S3 endpoint, MinIO,
 * R2): Signature Version 4 with a hashed payload, path-style requests, zero
 * dependencies. Transport is curl when the extension is loaded, else PHP
 * streams, else a clear exception at construction.
 */
final class S3
{
    /** @param array{endpoint:string,region:string,bucket:string,key:string,secret:string,prefix?:string} $config */
    public function __construct(private array $config)
    {
        foreach (['endpoint', 'region', 'bucket', 'key', 'secret'] as $required) {
            if (!array_key_exists($required, $this->config) || $this->config[$required] === '') {
                throw new \InvalidArgumentException("S3 config key '{$required}' is required");
            }
        }
        self::assertTransport(extension_loaded('curl'), extension_loaded('openssl'), (int) ini_get('allow_url_fopen') === 1);
    }

    /**
     * Transport gate, a pure function of the transport facts so the
     * neither-transport-exists branch stays testable on hosts that ship curl
     * (a loaded extension cannot be unloaded at runtime). The constructor
     * passes the real facts in.
     */
    public static function assertTransport(bool $curl, bool $openssl, bool $allowUrlFopen): void
    {
        if (!$curl && !$openssl) {
            throw new \RuntimeException('S3 upload needs ext-curl or ext-openssl (streams) for HTTPS');
        }
        if (!$curl && !$allowUrlFopen) {
            throw new \RuntimeException('S3 upload without ext-curl needs allow_url_fopen enabled for stream transports');
        }
    }

    /**
     * Streams fallback guard: the fallback buffers the whole archive in
     * memory, so an archive that cannot fit under memory_limit is refused
     * before any bytes move, naming both remedies. curl streams from disk and
     * needs no such check.
     */
    private function assertStreamsCanCarry(string $file): void
    {
        if (extension_loaded('curl')) return;
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1') return; // unlimited
        $bytes = (int) $limit;
        if (preg_match('/^(\d+)([KMG])/i', $limit, $m) === 1) {
            $bytes = (int) $m[1] * match (strtoupper($m[2])) {
                'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1,
            };
        }
        $size = filesize($file);
        if ($size === false || $size > $bytes - memory_get_usage() - 1048576) {
            throw new \RuntimeException(sprintf(
                'Backup archive is %d bytes; the streams transport (no ext-curl) buffers it in memory under a %s limit. '
                . 'Install ext-curl or raise memory_limit for off-site backups of this size.',
                $size === false ? 0 : $size,
                $limit
            ));
        }
    }

    /** Upload one file, returning the object key stored. */
    public function put(string $file, ?string $object = null): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException("Cannot read {$file}");
        }
        $object ??= basename($file);
        $key = $this->key($object);
        $this->assertStreamsCanCarry($file);
        $status = $this->send('PUT', $key, $file);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("S3 PUT failed with HTTP {$status} for {$key}");
        }
        return $key;
    }

    /** Does the object exist? (backup verification) */
    public function head(string $object): bool
    {
        return $this->send('HEAD', $this->key($object), null) === 200;
    }

    private function key(string $object): string
    {
        return trim(($this->config['prefix'] ?? '') . '/' . $object, '/');
    }

    /** The canonical URI for a full key: every segment percent-encoded with uppercase hex, slashes kept. */
    private function canonicalPath(string $key): string
    {
        return '/' . $this->config['bucket'] . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /** The request path an object will use on the wire (prefix included). */
    public function objectPath(string $object): string
    {
        return $this->canonicalPath($this->key($object));
    }

    private function send(string $method, string $key, ?string $file): int
    {
        $host = parse_url($this->config['endpoint'], PHP_URL_HOST);
        if (!is_string($host)) {
            throw new \RuntimeException("S3 endpoint '{$this->config['endpoint']}' has no usable host");
        }
        $port = parse_url($this->config['endpoint'], PHP_URL_PORT);
        if (is_int($port)) {
            $host .= ":{$port}"; // MinIO on :9000 and friends: the port signs too
        }
        $path = $this->canonicalPath($key);
        $payload = $file === null ? hash('sha256', '') : (string) hash_file('sha256', $file);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        // Exactly these three headers are signed, so a server can rebuild the
        // canonical request from what actually arrived.
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payload}\nx-amz-date:{$amzDate}\n";
        $signed = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "{$method}\n{$path}\n\n{$canonicalHeaders}\n{$signed}\n{$payload}";
        $scope = "{$date}/{$this->config['region']}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->config['secret'], true);
        $kRegion = hash_hmac('sha256', $this->config['region'], $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->config['key']}/{$scope}, "
            . "SignedHeaders={$signed}, Signature={$signature}";
        $headers = [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: {$payload}",
            "Authorization: {$authorization}",
        ];
        return $this->transport($method, $this->config['endpoint'] . $path, $headers, $file);
    }

    /** @param list<string> $headers */
    private function transport(string $method, string $url, array $headers, ?string $file): int
    {
        if (extension_loaded('curl')) {
            return $this->curlTransport($method, $url, $headers, $file);
        }
        return $this->streamTransport($method, $url, $headers, $file);
    }

    /** @param list<string> $headers */
    private function curlTransport(string $method, string $url, array $headers, ?string $file): int
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException("S3 request failed: cannot initialize curl for {$url}");
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        $body = null;
        if ($file !== null) {
            $body = @fopen($file, 'rb');
            if ($body === false) {
                curl_close($handle);
                throw new \RuntimeException("Cannot read {$file}");
            }
            $opts[CURLOPT_PUT] = true;
            $opts[CURLOPT_INFILE] = $body;
            $opts[CURLOPT_INFILESIZE] = (int) filesize($file);
        }
        curl_setopt_array($handle, $opts);
        try {
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
        } finally {
            if (is_resource($body)) {
                fclose($body); // ext-curl does not close the handle we gave it
            }
            curl_close($handle);
        }
        if ($status === 0) {
            throw new \RuntimeException("S3 request failed: {$error}");
        }
        return $status;
    }

    /** @param list<string> $headers */
    private function streamTransport(string $method, string $url, array $headers, ?string $file): int
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $file === null ? '' : (string) file_get_contents($file),
            'ignore_errors' => true, // a 4xx/5xx still carries a status to inspect
            'timeout' => 120,
        ]]);
        $result = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                $status = (int) $m[1]; // keep the last status line seen
            }
        }
        if ($result === false && $status === 0) {
            throw new \RuntimeException('S3 request failed: no HTTP response (streams transport)');
        }
        return $status;
    }
}
