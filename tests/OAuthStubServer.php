<?php // tests/OAuthStubServer.php
namespace Kip\Tests;

/**
 * Starts the PHP-native OAuth stub IdP (tests/Fixtures/oauth-stub.php) as a
 * php -S subprocess on a throwaway 8093+ port and kills it afterwards. No
 * Docker, no network beyond 127.0.0.1. Use from a TestCase: startOAuthStub()
 * inside a try, stopOAuthStub() in the finally (or tearDown). The same trait
 * carries fetch(), a redirect-refusing dual-transport HTTP helper the tests
 * use as the "browser" side of the dance (curl when loaded, else streams,
 * mirroring the transports the client itself supports).
 */
trait OAuthStubServer
{
    /** @var array{process: resource, store: string, log: string}|null */
    private ?array $oauthStub = null;

    /**
     * Start the stub. $env overrides any KIP_OAUTH_STUB_* variable (booleans
     * as '0'/'1'). Returns the client config base: the registered client
     * credentials, the registered redirect URI, the endpoint base, and the
     * extra 'store' key pointing at the directory the request log lands in.
     *
     * @param array<string, string> $env
     * @return array{client_id:string,client_secret:string,redirect_uri:string,base:string,store:string}
     */
    protected function startOAuthStub(int $port, array $env = []): array
    {
        $router = __DIR__ . '/Fixtures/oauth-stub.php';
        $store = sys_get_temp_dir() . '/kip-oauth-stub-' . bin2hex(random_bytes(4));
        mkdir($store . '/codes', 0777, true);
        $log = (string) tempnam(sys_get_temp_dir(), 'kip-oauth-stub-log-');
        $client = $env['KIP_OAUTH_STUB_CLIENT'] ?? 'stub-client';
        $secret = $env['KIP_OAUTH_STUB_SECRET'] ?? 'stub-secret';
        $redirect = $env['KIP_OAUTH_STUB_REDIRECT'] ?? "http://127.0.0.1:{$port}/oauth/callback/test";
        $defaults = [
            'KIP_OAUTH_STUB_CLIENT' => $client,
            'KIP_OAUTH_STUB_SECRET' => $secret,
            'KIP_OAUTH_STUB_REDIRECT' => $redirect,
            'KIP_OAUTH_STUB_PKCE' => '1',
            'KIP_OAUTH_STUB_USER' => 'stub-user-1',
            'KIP_OAUTH_STUB_EMAIL' => 'user@example.org',
            'KIP_OAUTH_STUB_VERIFIED' => '1',
            'KIP_OAUTH_STUB_STORE' => $store,
        ];
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_log=' . $log, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname($router),
            array_merge($defaults, $env)
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the OAuth stub server');
        }
        $deadline = microtime(true) + 3.0;
        do {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
            if ($probe !== false) {
                fclose($probe);
                $this->oauthStub = ['process' => $process, 'store' => $store, 'log' => $log];
                return [
                    'client_id' => $client,
                    'client_secret' => $secret,
                    'redirect_uri' => $redirect,
                    'base' => "http://127.0.0.1:{$port}",
                    'store' => $store,
                ];
            }
            usleep(60000);
        } while (microtime(true) < $deadline);
        proc_terminate($process);
        proc_close($process);
        throw new \RuntimeException(
            "OAuth stub server on 127.0.0.1:{$port} never answered; log: "
            . substr((string) file_get_contents($log), -2000)
        );
    }

    /** Kill the server, remove its store directory and the server log. */
    protected function stopOAuthStub(): void
    {
        if ($this->oauthStub === null) {
            return;
        }
        ['process' => $process, 'store' => $store, 'log' => $log] = $this->oauthStub;
        $this->oauthStub = null;
        proc_terminate($process);
        proc_close($process);
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) {
                        $rm($f);
                    } else {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            };
            $rm($store);
            @unlink($log);
        } finally {
            restore_error_handler();
        }
    }

    /** The stub's request log, one "METHOD path" line per request. */
    protected function oauthStubRequests(): string
    {
        if ($this->oauthStub === null) {
            return '';
        }
        return (string) file_get_contents($this->oauthStub['store'] . '/requests.log');
    }

    /**
     * One HTTP request, never following a redirect (the browser side of the
     * dance only ever wants the 302's Location). Mirrors the client's
     * transports: curl when loaded, else PHP streams.
     *
     * @param list<string> $headers
     * @return array{int, array<string,string>, string} status, lowercase response headers, body
     */
    protected function fetch(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (extension_loaded('curl')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException("cannot initialize curl for {$url}");
            }
            $sent = [];
            $opts = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$sent): int {
                    $pos = strpos($line, ':');
                    if ($pos !== false) {
                        $sent[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                    }
                    return strlen($line);
                },
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $opts);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($response === false || $status === 0) {
                throw new \RuntimeException("request to {$url} failed: {$error}");
            }
            return [$status, $sent, (string) $response];
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        $sent = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) $status = (int) $m[1];
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $sent[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        if ($response === false && $status === 0) {
            throw new \RuntimeException("request to {$url} failed: no HTTP response");
        }
        return [$status, $sent, (string) $response];
    }
}
