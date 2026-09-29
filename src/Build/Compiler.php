<?php // src/Build/Compiler.php

declare(strict_types=1);
namespace Kip\Build;

/**
 * `kip build`'s compile step: turn the staging copy into one self-contained
 * server binary. On Linux the native static-build script runs directly, no
 * Docker anywhere. On every other platform (macOS) the official builder
 * image is used through Docker, and only when a daemon actually answers;
 * otherwise the command names the two alternatives and refuses rather than
 * half-building. Docker appears here and nowhere else in Kip, and only when
 * a human typed `kip build`.
 */
final class Compiler
{
    private const IMAGE = 'dunglas/frankenphp:static-builder-gnu';
    private const BINARY_IN_IMAGE = '/go/src/app/dist/frankenphp-linux-%s';
    private const TAG = 'kip-app-static';
    private const ARTIFACT = 'kip-app';

    private string $platform;
    /** @var \Closure(): bool */
    private \Closure $dockerAvailable;

    /**
     * @param string $stagingDir the prepared app copy (Preparer's output)
     * @param string $buildDir holds the generated Dockerfile and the artifact
     * @param ?string $platform PHP_OS_FAMILY by default, injectable for tests
     * @param ?\Closure $dockerAvailable defaults to running `docker info`
     * @param ?\Closure $runner command runner, Shell::runner() by default
     */
    public function __construct(
        private string $stagingDir,
        private string $buildDir,
        ?string $platform = null,
        ?\Closure $dockerAvailable = null,
        private ?\Closure $runner = null,
    ) {
        $this->platform = $platform ?? PHP_OS_FAMILY;
        $this->runner ??= Shell::runner();
        $this->dockerAvailable = $dockerAvailable ?? static function (): bool {
            [$code] = Shell::runner()('docker info', null, []);
            return $code === 0;
        };
    }

    /** @return string path to the built artifact (build/kip-app) */
    public function compile(): string
    {
        // Never leave a stale artifact behind a failed run: a binary on disk
        // says "this build succeeded" to whoever finds it.
        $artifact = $this->buildDir . '/' . self::ARTIFACT;
        if (file_exists($artifact)) @unlink($artifact);
        return $this->platform === 'Linux' ? $this->nativeCompile($artifact) : $this->dockerCompile($artifact);
    }

    /** The Docker-free path: the static build script, run natively on Linux. */
    private function nativeCompile(string $artifact): string
    {
        $script = $this->locateNativeScript();
        [$code, $output] = ($this->runner)(escapeshellarg($script), dirname($script), ['EMBED' => $this->stagingDir]);
        if ($code !== 0) {
            throw new \RuntimeException("The static build script failed (exit {$code}):\n{$output}");
        }
        $built = dirname($script) . '/dist/frankenphp-linux-' . $this->targetArch();
        if (!is_file($built)) {
            throw new \RuntimeException("The build produced no binary: expected it at {$built} after a successful script run.");
        }
        $this->place($built, $artifact);
        return $artifact;
    }

    private function locateNativeScript(): string
    {
        $script = getenv('KIP_BUILD_SCRIPT');
        if (is_string($script) && $script !== '' && is_file($script)) return $script;
        $repo = getenv('KIP_BUILD_REPO');
        if (is_string($repo) && $repo !== '' && is_file($repo . '/build-static.sh')) return $repo . '/build-static.sh';
        throw new \RuntimeException(
            "kip build on Linux uses the native static build script, no Docker. Point KIP_BUILD_SCRIPT at the "
            . "build-static.sh checkout (or KIP_BUILD_REPO at its directory); neither is set. The alternative is "
            . "the prebuilt standalone server binary pattern in guide chapter 10, which needs no build at all."
        );
    }

    /**
     * The builder-image path for non-Linux machines. Detection comes first:
     * no daemon, no build, and the message says what to do instead.
     */
    private function dockerCompile(string $artifact): string
    {
        if (!($this->dockerAvailable)()) {
            throw new \RuntimeException(
                'Docker is required to build the single-file artifact on this machine (macOS), and no Docker daemon answered. '
                . 'Two alternatives: run kip build on a Linux host, where the native static build script needs no Docker '
                . '(KIP_BUILD_SCRIPT), or skip the single-file artifact and use the prebuilt standalone server binary '
                . 'pattern in guide chapter 10, which needs no build at all. Nothing was built.'
            );
        }
        $target = getenv('KIP_BUILD_PLATFORM') ?: 'linux/amd64';
        if (!in_array($target, ['linux/amd64', 'linux/arm64'], true)) {
            throw new \RuntimeException("Unsupported KIP_BUILD_PLATFORM {$target}: use linux/amd64 or linux/arm64.");
        }
        $this->writeDockerfile($target);
        $inImage = sprintf(self::BINARY_IN_IMAGE, $this->targetArch());

        [$code, $output] = ($this->runner)(
            'docker build -f ' . escapeshellarg($this->buildDir . '/Dockerfile') . ' -t ' . self::TAG . ' ' . escapeshellarg($this->stagingDir),
            $this->buildDir
        );
        if ($code !== 0) {
            throw new \RuntimeException("The builder image build failed (exit {$code}):\n{$output}");
        }
        [$code, $output] = ($this->runner)('docker create ' . self::TAG, $this->buildDir, []);
        if ($code !== 0 || trim($output) === '') {
            throw new \RuntimeException("Could not create a container from the built image (exit {$code}):\n{$output}");
        }
        $containerId = trim($output);

        $failure = null;
        try {
            [$code, $output] = ($this->runner)(
                'docker cp ' . escapeshellarg($containerId . ':' . $inImage) . ' ' . escapeshellarg($artifact),
                $this->buildDir
            );
            if ($code !== 0) {
                throw new \RuntimeException("Extracting the binary from the builder container failed (exit {$code}):\n{$output}");
            }
        } catch (\Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            // The temporary container never outlives the extraction, success
            // or not; a cleanup failure only surfaces when nothing worse happened.
            [$rmCode, $rmOut] = ($this->runner)('docker rm ' . escapeshellarg($containerId), $this->buildDir, []);
            if ($failure === null && $rmCode !== 0) {
                throw new \RuntimeException("Could not remove the temporary builder container {$containerId}:\n{$rmOut}");
            }
        }
        if (!is_file($artifact)) {
            throw new \RuntimeException("The build produced no binary: extraction reported success but {$artifact} is not there.");
        }
        chmod($artifact, 0755);
        return $artifact;
    }

    /** The documented static-builder recipe, with the target platform pinned in FROM. */
    private function writeDockerfile(string $target): void
    {
        if (!is_dir($this->buildDir) && !@mkdir($this->buildDir, 0755, true) && !is_dir($this->buildDir)) {
            throw new \RuntimeException("Cannot create build directory {$this->buildDir}.");
        }
        $dockerfile = 'FROM --platform=' . $target . ' ' . self::IMAGE . "\n"
            . 'WORKDIR /go/src/app/dist/app' . "\n"
            . 'COPY . .' . "\n"
            . 'WORKDIR /go/src/app/' . "\n"
            . 'RUN EMBED=dist/app/ ./build-static.sh' . "\n";
        file_put_contents($this->buildDir . '/Dockerfile', $dockerfile);
    }

    private function targetArch(): string
    {
        $target = getenv('KIP_BUILD_PLATFORM') ?: 'linux/amd64';
        return $target === 'linux/arm64' ? 'aarch64' : 'x86_64';
    }

    private function place(string $from, string $artifact): void
    {
        if (!@copy($from, $artifact)) {
            throw new \RuntimeException("Cannot move the built binary from {$from} to {$artifact}.");
        }
        chmod($artifact, 0755);
    }
}
