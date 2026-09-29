<?php // tests/Build/CompilerTest.php
namespace Kip\Tests\Build;

use PHPUnit\Framework\TestCase;

/**
 * `kip build`'s compile step (Kip\Build\Compiler): on Linux the native
 * static-build script runs with no Docker anywhere; on macOS the builder
 * image is used, but only when a Docker daemon answers, otherwise the
 * command names the two alternatives and refuses. Docker is never invoked by
 * these tests: detection and command execution are injected fakes, exactly
 * because the real invocation is reserved for the manual, human-typed run.
 */
final class CompilerTest extends TestCase
{
    private string $staging;
    private string $buildDir;
    /** @var list<array{cmd: string, cwd: ?string, env: array<string,string>}> */
    private array $calls = [];
    /** @var list<array{0: int, 1: string}> queued [exit, output] per runner call */
    private array $results = [];
    private bool $dockerAsked = false;
    private bool $dockerUp = true;
    /** When true, a faked `docker cp` lands the binary, like the real one. */
    private bool $cpDelivers = true;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/kip-cc-' . bin2hex(random_bytes(4));
        $this->staging = $base . '/build/staging';
        $this->buildDir = $base . '/build';
        if (!is_dir($this->staging)) mkdir($this->staging, 0777, true);
        if (!is_dir($this->buildDir)) mkdir($this->buildDir, 0777, true);
        file_put_contents($this->staging . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        putenv('KIP_BUILD_SCRIPT');
        putenv('KIP_BUILD_REPO');
        putenv('KIP_BUILD_PLATFORM');
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            if (is_dir(dirname($this->staging, 2))) $rm(dirname($this->staging, 2));
        } finally { restore_error_handler(); }
    }

    /** Recording/faking runner: no command in this suite ever executes. */
    private function runner(): \Closure
    {
        return function (string $cmd, ?string $cwd = null, array $env = []): array {
            $this->calls[] = ['cmd' => $cmd, 'cwd' => $cwd, 'env' => $env];
            $result = array_shift($this->results) ?? [0, ''];
            if (str_contains($cmd, 'docker cp') && $this->cpDelivers && $result[0] === 0) {
                file_put_contents($this->buildDir . '/kip-app', '#!elf');
                chmod($this->buildDir . '/kip-app', 0644);
            }
            return $result;
        };
    }

    private function dockerAvailable(): \Closure
    {
        return function (): bool {
            $this->dockerAsked = true;
            return $this->dockerUp;
        };
    }

    private function compiler(string $platform): \Kip\Build\Compiler
    {
        return new \Kip\Build\Compiler($this->staging, $this->buildDir, $platform, $this->dockerAvailable(), $this->runner());
    }

    private function commands(): array
    {
        return array_map(static fn(array $c): string => $c['cmd'], $this->calls);
    }

    public function test_macos_without_docker_names_both_alternatives_and_refuses(): void
    {
        $this->dockerUp = false;
        file_put_contents($this->buildDir . '/kip-app', 'stale artifact from an earlier run');

        try {
            $this->compiler('Darwin')->compile();
            $this->fail('compile() must refuse to build without Docker on macOS');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Linux host', $e->getMessage(), 'alternative 1: the native static build');
            $this->assertStringContainsString('chapter 10', $e->getMessage(), 'alternative 2: the prebuilt-binary deployment');
            $this->assertStringContainsString('Docker', $e->getMessage());
        }
        $this->assertSame([], $this->calls, 'no command may run after the refusal');
        $this->assertFileDoesNotExist($this->buildDir . '/kip-app', 'a stale artifact must not survive as a half build');
    }

    public function test_macos_with_docker_writes_the_documented_builder_recipe_and_extracts_the_binary(): void
    {
        $this->results = [[0, ''], [0, "cid123\n"], [0, ''], [0, '']];

        $artifact = $this->compiler('Darwin')->compile();

        $this->assertSame($this->buildDir . '/kip-app', $artifact);
        $dockerfile = (string) file_get_contents($this->buildDir . '/Dockerfile');
        $this->assertStringContainsString('FROM --platform=linux/amd64 dunglas/frankenphp:static-builder-gnu', $dockerfile);
        $this->assertStringContainsString('WORKDIR /go/src/app/dist/app', $dockerfile);
        $this->assertStringContainsString('COPY . .', $dockerfile);
        $this->assertStringContainsString('RUN EMBED=dist/app/ ./build-static.sh', $dockerfile);

        [$build, $create, $cp, $rm] = $this->calls;
        $this->assertStringContainsString('docker build', $build['cmd']);
        $this->assertStringContainsString('-f ' . escapeshellarg($this->buildDir . '/Dockerfile'), $build['cmd']);
        $this->assertStringContainsString(escapeshellarg($this->staging), $build['cmd'], 'the staging copy is the build context');
        $this->assertStringContainsString('docker create', $create['cmd']);
        $this->assertStringContainsString('docker cp', $cp['cmd']);
        $this->assertStringContainsString('cid123:/go/src/app/dist/frankenphp-linux-x86_64', $cp['cmd']);
        $this->assertStringContainsString('docker rm', $rm['cmd']);
        $this->assertStringContainsString('cid123', $rm['cmd']);
        $this->assertSame(0755, fileperms($this->buildDir . '/kip-app') & 0777, 'the artifact must be executable');
    }

    public function test_a_custom_target_platform_flows_into_the_dockerfile_and_artifact_name(): void
    {
        putenv('KIP_BUILD_PLATFORM=linux/arm64');
        $this->results = [[0, ''], [0, "cid9\n"], [0, ''], [0, '']];

        $this->compiler('Darwin')->compile();

        $this->assertStringContainsString('FROM --platform=linux/arm64 dunglas/frankenphp:static-builder-gnu', (string) file_get_contents($this->buildDir . '/Dockerfile'));
        $this->assertStringContainsString('frankenphp-linux-aarch64', $this->calls[2]['cmd']);
    }

    public function test_a_failed_docker_build_is_a_named_error_and_leaves_no_artifact(): void
    {
        file_put_contents($this->buildDir . '/kip-app', 'stale');
        $this->results = [[1, 'qemu: emulated build died']];

        try {
            $this->compiler('Darwin')->compile();
            $this->fail('a failed docker build must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('emulated build died', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->buildDir . '/kip-app');
        $this->assertCount(1, $this->calls, 'no container steps run after a failed build');
    }

    public function test_a_failed_extraction_still_removes_the_container_and_reports_the_extraction_error(): void
    {
        $this->results = [[0, ''], [0, "cid77\n"], [1, 'docker cp: no such path']];

        try {
            $this->compiler('Darwin')->compile();
            $this->fail('a failed extraction must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('docker cp: no such path', $e->getMessage());
        }
        $this->assertStringContainsString('docker rm', $this->calls[count($this->calls) - 1]['cmd'], 'cleanup runs even after a failed extraction');
        $this->assertStringContainsString('cid77', $this->calls[count($this->calls) - 1]['cmd']);
        $this->assertFileDoesNotExist($this->buildDir . '/kip-app');
    }

    public function test_a_missing_extracted_binary_is_a_named_error(): void
    {
        $this->cpDelivers = false; // cp "succeeds" but the binary never lands
        $this->results = [[0, ''], [0, "cid1\n"], [0, ''], [0, '']];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no binary');
        $this->compiler('Darwin')->compile();
    }

    public function test_linux_runs_the_native_script_and_never_consults_docker(): void
    {
        $scriptDir = dirname($this->staging, 2) . '/builder-src';
        mkdir($scriptDir . '/dist', 0777, true);
        file_put_contents($scriptDir . '/build-static.sh', "#!/bin/sh\nexit 0\n");
        file_put_contents($scriptDir . '/dist/frankenphp-linux-x86_64', '#!elf');
        putenv('KIP_BUILD_SCRIPT=' . $scriptDir . '/build-static.sh');

        $artifact = $this->compiler('Linux')->compile();

        $this->assertSame($this->buildDir . '/kip-app', $artifact);
        $this->assertFalse($this->dockerAsked, 'the Linux path must not touch Docker at all');
        [$run] = $this->calls;
        $this->assertStringContainsString('build-static.sh', $run['cmd']);
        $this->assertSame($scriptDir, $run['cwd'], 'the script runs from its own checkout');
        $this->assertSame(['EMBED' => $this->staging], $run['env'], 'the app reaches the script through EMBED');
        $this->assertSame(0755, fileperms($artifact) & 0777);
    }

    public function test_linux_accepts_a_repo_directory_containing_the_script(): void
    {
        $repo = dirname($this->staging, 2) . '/builder-clone';
        mkdir($repo . '/dist', 0777, true);
        file_put_contents($repo . '/build-static.sh', "#!/bin/sh\nexit 0\n");
        file_put_contents($repo . '/dist/frankenphp-linux-x86_64', '#!elf');
        putenv('KIP_BUILD_REPO=' . $repo);

        $artifact = $this->compiler('Linux')->compile();
        $this->assertSame($this->buildDir . '/kip-app', $artifact);
    }

    public function test_linux_without_a_build_script_names_the_alternatives(): void
    {
        try {
            $this->compiler('Linux')->compile();
            $this->fail('compile() must refuse without a native build script');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('KIP_BUILD_SCRIPT', $e->getMessage());
            $this->assertStringContainsString('KIP_BUILD_REPO', $e->getMessage());
            $this->assertStringContainsString('chapter 10', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    public function test_linux_surfaces_a_failing_build_script(): void
    {
        $scriptDir = dirname($this->staging, 2) . '/builder-src';
        mkdir($scriptDir, 0777, true);
        file_put_contents($scriptDir . '/build-static.sh', "#!/bin/sh\nexit 0\n");
        putenv('KIP_BUILD_SCRIPT=' . $scriptDir . '/build-static.sh');
        $this->results = [[9, 'toolchain missing: no cgo']];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('toolchain missing');
        $this->compiler('Linux')->compile();
    }
}
