<?php // src/Build/Smoke.php

declare(strict_types=1);
namespace Kip\Build;

/**
 * `kip build`'s smoke step: boot the freshly built artifact, curl a known
 * route expecting 200, run migrate against a scratch data dir (through the
 * artifact itself, php-cli with a relative path into the embedded app), then
 * kill the server and report. The artifact is a Linux executable, so on a
 * non-Linux build machine it runs inside a throwaway container through the
 * same Docker daemon the compile step already feature-detected; on Linux it
 * execs natively. Ports are 8093 and up, the fixed suite ports are never
 * claimed, and a server that dies before answering is a named error, never
 * a silent 200 from whoever else is listening.
 */
final class Smoke
{
    private const PORT_BASE = 8093;
    private const PORTS_NEVER = [8090, 8096, 8098];
    /**
     * The artifact is a PIE that wants the glibc dynamic loader
     * (/lib/ld-linux-<arch>.so.1, observed on the first real build), so the
     * smoke container needs a glibc userspace; musl alpine cannot exec it
     * ("no such file or directory", the loader is the missing file).
     */
    private const SMOKE_IMAGE = 'debian:bookworm-slim';
    private const READY_SECONDS = 20;

    private string $platform;

    /**
     * @param string $artifact the built binary (build/kip-app)
     * @param string $scratchDir fresh dir for the smoke databases and logs
     * @param ?\Closure $runner command runner for the migrate call
     * @param ?string $platform PHP_OS_FAMILY by default, injectable for tests
     */
    public function __construct(
        private string $artifact,
        private string $scratchDir,
        private ?\Closure $runner = null,
        ?string $platform = null,
    ) {
        $this->platform = $platform ?? PHP_OS_FAMILY;
        $this->runner ??= Shell::runner();
    }

    /**
     * The exact commands the smoke step runs, assembled before anything
     * executes: server [command, cwd, env] and migrate [command, cwd, env].
     * The listener address comes from the php-server --listen flag (it exists
     * on the real artifact, confirmed empirically by the first real build;
     * SERVER_NAME only names the server, the default listener is :80). Inside
     * the smoke container the bind is :<port> on all interfaces, because the
     * published port reaches the container's own address, not its loopback.
     *
     * @return array{server: array{0: string, 1: ?string, 2: array<string,string>}, migrate: array{0: string, 1: ?string, 2: array<string,string>}}
     */
    public function plan(int $port): array
    {
        if ($this->platform === 'Linux') {
            return [
                'server' => [
                    'exec env KIP_DATA_DIR=' . escapeshellarg($this->scratchDir . '/http')
                        . ' ' . escapeshellarg($this->artifact) . ' php-server --listen=127.0.0.1:' . $port,
                    $this->scratchDir,
                    ['KIP_DATA_DIR' => $this->scratchDir . '/http'],
                ],
                'migrate' => [
                    escapeshellarg($this->artifact) . ' php-cli bin/kip migrate',
                    $this->scratchDir,
                    ['KIP_DATA_DIR' => $this->scratchDir . '/data'],
                ],
            ];
        }
        $mounts = '-v ' . escapeshellarg($this->artifact) . ':/kip-app:ro -v ' . escapeshellarg($this->scratchDir) . ':/smoke -w /smoke';
        return [
            'server' => [
                'docker run --rm --name kip-smoke-' . $port . ' -p 127.0.0.1:' . $port . ':' . $port . ' ' . $mounts
                    . ' -e KIP_DATA_DIR=/smoke/http '
                    . self::SMOKE_IMAGE . ' /kip-app php-server --listen=:' . $port,
                null,
                ['KIP_DATA_DIR' => '/smoke/http'],
            ],
            'migrate' => [
                'docker run --rm ' . $mounts . ' -e KIP_DATA_DIR=/smoke/data '
                    . self::SMOKE_IMAGE . ' /kip-app php-cli bin/kip migrate',
                null,
                ['KIP_DATA_DIR' => '/smoke/data'],
            ],
        ];
    }

    /** @return array{port: int, http: array{route: string, status: int}, migrate: array{exit: int, output: string, data_dir: string}} */
    public function run(): array
    {
        foreach (['http', 'data'] as $sub) {
            $dir = $this->scratchDir . '/' . $sub;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create smoke scratch directory {$dir}.");
            }
        }
        $port = $this->freePort();
        [$serverCmd, $serverCwd] = $this->plan($port)['server'];
        $log = $this->scratchDir . '/server.log';
        $proc = proc_open($serverCmd, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $serverCwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException("Cannot start the artifact for the smoke check: {$serverCmd}");
        }
        try {
            $status = $this->waitForAnswer($port, $proc);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            if ($this->platform !== 'Linux') {
                // Killing the attached docker CLI does not reliably stop the
                // container; it is named per port and removed by force so the
                // smoke server never outlives the run holding the port.
                ($this->runner)('docker rm -f kip-smoke-' . $port, null, []);
            }
        }
        if ($status !== 200) {
            throw new \RuntimeException("Smoke check failed: route / answered HTTP {$status}, expected 200. Server log tail:\n"
                . $this->logTail($log));
        }
        [$migrateCmd, $migrateCwd, $migrateEnv] = $this->plan($port)['migrate'];
        /** @var array{0: int, 1: string} $migrated */
        $migrated = ($this->runner)($migrateCmd, $migrateCwd, $migrateEnv);
        [$migrateExit, $migrateOutput] = $migrated;
        if ($migrateExit !== 0) {
            throw new \RuntimeException("Artifact migrate failed against the scratch data dir (exit {$migrateExit}):\n{$migrateOutput}");
        }
        return [
            'port' => $port,
            'http' => ['route' => '/', 'status' => $status],
            'migrate' => ['exit' => $migrateExit, 'output' => $migrateOutput, 'data_dir' => $this->scratchDir . '/data'],
        ];
    }

    /**
     * Poll the route until the server answers, the child dies, or the deadline passes.
     *
     * @param resource $proc
     */
    private function waitForAnswer(int $port, $proc): int
    {
        $deadline = time() + self::READY_SECONDS;
        do {
            if (!proc_get_status($proc)['running']) {
                throw new \RuntimeException("The artifact exited before answering on port {$port}.\n"
                    . $this->logTail($this->scratchDir . '/server.log'));
            }
            $status = $this->fetchStatus('127.0.0.1', $port);
            if ($status !== null) return $status;
            usleep(150_000);
        } while (time() < $deadline);
        throw new \RuntimeException("The artifact never answered on port {$port} within " . self::READY_SECONDS . " seconds.\n"
            . $this->logTail($this->scratchDir . '/server.log'));
    }

    private function fetchStatus(string $host, int $port): ?int
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true]]);
        set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents("http://{$host}:{$port}/", false, $context);
        } finally {
            restore_error_handler();
        }
        if ($body === false) return null;
        // A successful HTTP-wrapper fetch always defines the response headers.
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m) === 1) return (int) $m[1];
        }
        return null;
    }

    private function freePort(): int
    {
        foreach (range(self::PORT_BASE, self::PORT_BASE + 40) as $port) {
            if (in_array($port, self::PORTS_NEVER, true)) continue;
            $probe = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if (is_resource($probe)) {
                fclose($probe);
                return $port;
            }
        }
        throw new \RuntimeException('No free port from ' . self::PORT_BASE . ' upward for the smoke check.');
    }

    private function logTail(string $log): string
    {
        $text = is_file($log) ? (string) file_get_contents($log) : '';
        return substr($text, -2000);
    }
}
