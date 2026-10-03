<?php // tests/Build/SmokeTest.php
namespace Kip\Tests\Build;

use PHPUnit\Framework\TestCase;

/**
 * `kip build`'s smoke step (Kip\Build\Smoke): boot the built artifact, curl
 * a known route expecting 200, run migrate against a scratch data dir, kill
 * the server, report. The artifact under test here is a FAKE (a shell script
 * speaking the real artifact's argv contract: `php-server` serves on the
 * port inside SERVER_NAME, `php-cli bin/kip migrate` writes the scratch
 * database); the real Linux binary only runs when a human types kip build,
 * so the suite never needs Docker. Ports are 8093+, skipping the fixed
 * suite ports, and a server that dies before answering is a named error,
 * not a silent 200 from whoever else is listening.
 */
final class SmokeTest extends TestCase
{
    private string $scratch;
    private string $artifact;
    private string $okRoot;
    private string $failRoot;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/kip-smoke-' . bin2hex(random_bytes(4));
        $this->scratch = $base . '/scratch';
        $this->artifact = $base . '/kip-app';
        $this->okRoot = $base . '/okroot';
        $this->failRoot = $base . '/failroot';
        foreach ([$this->scratch, $this->okRoot, $this->failRoot] as $d) mkdir($d, 0777, true);
        file_put_contents($this->okRoot . '/index.php', '<?php http_response_code(200); echo "fake ok";');
        file_put_contents($this->failRoot . '/index.php', '<?php http_response_code(500); echo "fake broken";');
        // The fake artifact: exec-chain so one SIGTERM from Smoke kills the
        // server. It speaks the real artifact's argv contract: php-server
        // takes --listen=<addr> (confirmed on the real binary), php-cli gets
        // the embedded script path relatively.
        file_put_contents($this->artifact, <<<SH
        #!/bin/sh
        case "\$1" in
          php-server)
            [ "\$FAKE_MODE" = die ] && exit 9
            listen=""
            for arg in "\$@"; do
              case "\$arg" in --listen=*) listen="\${arg#--listen=}" ;; esac
            done
            [ -n "\$listen" ] || { echo "fake: php-server needs --listen=<addr>" >&2; exit 3; }
            port="\${listen##*:}"
            if [ "\$FAKE_MODE" = 500 ]; then root="\$FAKE_FAILROOT"; else root="\$FAKE_OKROOT"; fi
            exec php -S 127.0.0.1:"\$port" -t "\$root"
            ;;
          php-cli)
            case "\$2" in bin/kip) ;; *) echo "fake: embedded scripts are addressed relatively, got: \$2" >&2; exit 3 ;; esac
            [ "\$3" = migrate ] || exit 0
            mkdir -p "\$KIP_DATA_DIR"
            if [ "\$FAKE_MODE" = migrate-fail ]; then echo "fake migrate failed: no schema" >&2; exit 1; fi
            echo migrated > "\$KIP_DATA_DIR/data.sqlite"
            echo "Ran: fake migrations into \$KIP_DATA_DIR"
            exit 0
            ;;
        esac
        exit 4
        SH);
        chmod($this->artifact, 0755);
        putenv('FAKE_OKROOT=' . $this->okRoot);
        putenv('FAKE_FAILROOT=' . $this->failRoot);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_MODE');
        putenv('FAKE_OKROOT');
        putenv('FAKE_FAILROOT');
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            if (is_dir(dirname($this->scratch))) $rm(dirname($this->scratch));
        } finally { restore_error_handler(); }
    }

    private function smoke(): \Kip\Build\Smoke
    {
        return new \Kip\Build\Smoke($this->artifact, $this->scratch, platform: 'Linux');
    }

    public function test_boots_the_artifact_answers_200_and_migrates_a_scratch_data_dir(): void
    {
        $report = $this->smoke()->run();

        $this->assertSame(200, $report['http']['status']);
        $this->assertSame('/', $report['http']['route']);
        $this->assertGreaterThanOrEqual(8093, $report['port']);
        $this->assertNotContains($report['port'], [8090, 8096, 8098], 'fixed suite ports are never claimed');
        $this->assertSame(0, $report['migrate']['exit']);
        $this->assertStringContainsString('Ran: fake migrations', $report['migrate']['output']);
        $this->assertFileExists($report['migrate']['data_dir'] . '/data.sqlite', 'migrate ran against the scratch data dir');
        $this->assertSame('migrated', trim((string) file_get_contents($report['migrate']['data_dir'] . '/data.sqlite')));
    }

    public function test_the_server_process_is_gone_after_the_run(): void
    {
        $report = $this->smoke()->run();

        $leftover = @fsockopen('127.0.0.1', $report['port'], $errno, $errstr, 0.3);
        $this->assertFalse($leftover, 'the booted server must be killed, not orphaned, on port ' . $report['port']);
    }

    public function test_a_route_that_answers_500_is_a_named_error(): void
    {
        putenv('FAKE_MODE=500');

        try {
            $this->smoke()->run();
            $this->fail('a non-200 route must fail the smoke step');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringContainsString('/', $e->getMessage());
        }
    }

    public function test_an_artifact_that_exits_before_answering_is_a_named_error(): void
    {
        putenv('FAKE_MODE=die');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exited before answering');
        $this->smoke()->run();
    }

    public function test_a_failing_migrate_is_a_named_error_with_its_output(): void
    {
        putenv('FAKE_MODE=migrate-fail');

        try {
            $this->smoke()->run();
            $this->fail('a failed migrate must fail the smoke step');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fake migrate failed: no schema', $e->getMessage());
        }
    }

    /**
     * F7: migrate must run through the artifact's own embedded inventory
     * (php-cli with a RELATIVE script path), never through staging's
     * bin/kip; the fake enforces the relative path, this pins that no
     * staging path rides along.
     */
    public function test_migrate_invokes_the_artifact_with_a_relative_embedded_script_path(): void
    {
        $captured = null;
        $inner = \Kip\Build\Shell::runner();
        $recorder = function (string $cmd, ?string $cwd = null, array $env = []) use (&$captured, $inner): array {
            if (str_contains($cmd, 'php-cli')) $captured = [$cmd, $cwd, $env];
            return $inner($cmd, $cwd, $env);
        };
        (new \Kip\Build\Smoke($this->artifact, $this->scratch, $recorder, 'Linux'))->run();

        [$cmd, $cwd, $env] = $captured;
        $this->assertStringEndsWith('php-cli bin/kip migrate', $cmd, 'the embedded script path is relative, never an absolute staging path');
        $this->assertSame($this->scratch, $cwd);
        $this->assertArrayHasKey('KIP_DATA_DIR', $env);
        $this->assertStringEndsWith('/data', $env['KIP_DATA_DIR']);
    }

    /** Pure command assembly, nothing executes: the Linux boot is a bare exec with env, listening on 127.0.0.1:<port>. */
    public function test_linux_plan_execs_the_artifact_directly(): void
    {
        $plan = $this->smoke()->plan(8095);
        [$serverCmd, $serverCwd, $serverEnv] = $plan['server'];
        $this->assertStringContainsString('exec', $serverCmd);
        $this->assertStringContainsString(escapeshellarg($this->artifact) . ' php-server', $serverCmd);
        // The listener is the --listen flag, confirmed on the real artifact:
        // SERVER_NAME only names the server, the default listener is :80.
        $this->assertStringContainsString('--listen=127.0.0.1:8095', $serverCmd);
        $this->assertStringNotContainsString('SERVER_NAME', $serverCmd);
        $this->assertStringEndsWith('/http', $serverEnv['KIP_DATA_DIR']);
        $this->assertSame($this->scratch, $serverCwd);
    }

    /** Pure command assembly, nothing executes: on macOS the Linux artifact runs through Docker. */
    public function test_macos_plan_runs_the_linux_artifact_through_docker(): void
    {
        $smoke = new \Kip\Build\Smoke($this->artifact, $this->scratch, platform: 'Darwin');
        $plan = $smoke->plan(8095);

        [$serverCmd] = $plan['server'];
        $this->assertStringContainsString('docker run --rm', $serverCmd);
        // The smoke container is named per port so the run's cleanup can
        // remove it by force: killing the attached CLI alone can leave the
        // container serving on the port after kip build exits.
        $this->assertStringContainsString('--name kip-smoke-8095', $serverCmd);
        $this->assertStringContainsString('-p 127.0.0.1:8095:8095', $serverCmd);
        // All interfaces inside the container: the published port reaches the
        // container's own address, never its loopback.
        $this->assertStringContainsString('--listen=:8095', $serverCmd);
        $this->assertStringNotContainsString('SERVER_NAME', $serverCmd);
        $this->assertStringContainsString(escapeshellarg($this->artifact) . ':/kip-app:ro', $serverCmd);
        $this->assertStringContainsString('/kip-app php-server', $serverCmd);
        [$migrateCmd] = $plan['migrate'];
        $this->assertStringContainsString('docker run --rm', $migrateCmd);
        $this->assertStringContainsString('-e KIP_DATA_DIR=', $migrateCmd);
        $this->assertStringContainsString('/kip-app php-cli bin/kip migrate', $migrateCmd);
        // The artifact is a glibc PIE (first real build: interpreter
        // /lib/ld-linux-aarch64.so.1), so the smoke container must be a
        // glibc userspace; musl alpine cannot exec it at all.
        $this->assertStringContainsString('debian:bookworm-slim', $serverCmd);
        $this->assertStringContainsString('debian:bookworm-slim', $migrateCmd);
    }

    public function test_port_selection_skips_occupied_and_fixed_ports(): void
    {
        // Hold 8093 and 8094 so the scan has to walk past them (and past the
        // always-skipped fixed suite ports is implicit: they are never picked).
        $a = stream_socket_server('tcp://127.0.0.1:8093');
        $b = stream_socket_server('tcp://127.0.0.1:8094');
        try {
            $report = $this->smoke()->run();
            $this->assertGreaterThanOrEqual(8095, $report['port']);
            $this->assertNotSame(8096, $report['port']);
            $this->assertNotSame(8098, $report['port']);
            $this->assertSame(200, $report['http']['status']);
        } finally {
            // A holder can lose its fixed port to another suite process that
            // binds 8093/8094 first: the smoke scan skips an occupied port
            // either way, so a lost race must not TypeError the teardown.
            if (is_resource($a)) fclose($a);
            if (is_resource($b)) fclose($b);
        }
    }
}
