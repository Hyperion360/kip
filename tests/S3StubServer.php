<?php // tests/S3StubServer.php
namespace Kip\Tests;

/**
 * Starts the PHP-native S3 stub (tests/Fixtures/s3-stub.php) as a php -S
 * subprocess on a throwaway 8093+ port and kills it afterwards. No Docker,
 * no network beyond 127.0.0.1. Use from a TestCase: startS3Stub() inside a
 * try, stopS3Stub() in the finally (or tearDown).
 */
trait S3StubServer
{
    /** @var array{process: resource, store: string, log: string}|null */
    private ?array $s3Stub = null;

    /**
     * Start the stub. Returns S3 client config for the live server, with the
     * extra 'store' key pointing at the directory PUT bodies land in.
     *
     * @return array{endpoint:string,region:string,bucket:string,key:string,secret:string,store:string}
     */
    protected function startS3Stub(
        int $port,
        string $secret = 'stub-secret',
        string $key = 'stub-key',
        string $bucket = 'stub-bucket'
    ): array {
        $router = __DIR__ . '/Fixtures/s3-stub.php';
        $store = sys_get_temp_dir() . '/kip-s3-stub-' . bin2hex(random_bytes(4));
        mkdir($store, 0777, true);
        $log = (string) tempnam(sys_get_temp_dir(), 'kip-s3-stub-log-');
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_log=' . $log, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname($router),
            [
                'KIP_S3_STUB_SECRET' => $secret,
                'KIP_S3_STUB_KEY' => $key,
                'KIP_S3_STUB_BUCKET' => $bucket,
                'KIP_S3_STUB_STORE' => $store,
            ]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the S3 stub server');
        }
        // Wait for the listener; php -S needs a beat to bind.
        $deadline = microtime(true) + 3.0;
        do {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
            if ($probe !== false) {
                fclose($probe);
                $this->s3Stub = ['process' => $process, 'store' => $store, 'log' => $log];
                return [
                    'endpoint' => "http://127.0.0.1:{$port}",
                    'region' => 'us-east-1',
                    'bucket' => $bucket,
                    'key' => $key,
                    'secret' => $secret,
                    'store' => $store,
                ];
            }
            usleep(60000);
        } while (microtime(true) < $deadline);
        proc_terminate($process);
        proc_close($process);
        throw new \RuntimeException(
            "S3 stub server on 127.0.0.1:{$port} never answered; log: "
            . substr((string) file_get_contents($log), -2000)
        );
    }

    /** Kill the server and remove its store directory and log. */
    protected function stopS3Stub(): void
    {
        if ($this->s3Stub === null) {
            return;
        }
        ['process' => $process, 'store' => $store, 'log' => $log] = $this->s3Stub;
        $this->s3Stub = null;
        proc_terminate($process);
        proc_close($process);
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else @unlink($f);
                }
                @rmdir($dir);
            };
            $rm($store);
            @unlink($log);
        } finally {
            restore_error_handler();
        }
    }
}
