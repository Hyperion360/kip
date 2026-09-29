<?php // src/Build/Preparer.php

declare(strict_types=1);
namespace Kip\Build;

/**
 * `kip build`'s staging step: assemble a deployable copy of a Kip app. The
 * app tree is copied minus dev junk and runtime databases, dependencies are
 * reinstalled with --no-dev, and tests/docs are pruned from vendor.
 *
 * The last act is the deploy-pipeline assertion (assertShipped()): a release
 * artifact that drops app/Features makes `kip migrate` apply a short schema
 * while reporting success, so a staging copy missing it is refused by name,
 * after every step that could have mutated it. Public, because an external
 * release pipeline can run the same check on its own artifact.
 */
final class Preparer
{
    /** Directory names never copied, at any depth. */
    private const SKIP_DIRS_ANYWHERE = ['.git', 'node_modules', '.idea', '.vscode'];

    /** Directory names never copied from the app root (vendor is reinstalled, not shipped). */
    private const SKIP_DIRS_AT_ROOT = ['build', 'tests', 'docs', 'vendor'];

    /** File-name suffixes never copied (runtime state must not ship inside an artifact). */
    private const SKIP_FILE_SUFFIXES = ['.sqlite', '.sqlite-wal', '.sqlite-shm', '.log'];

    /** Paths relative to the app root never copied. */
    private const SKIP_PATHS = ['app/backups'];

    /** Directory names pruned from vendor package roots after install. */
    private const VENDOR_PRUNE_DIRS = ['tests', 'Tests', 'docs'];

    /**
     * @param string $sourceAppDir the app root (holds bin/, config.php, composer.json)
     * @param string $stagingDir the staging copy to (re)create
     * @param ?string $composerBinary defaults to `composer` on PATH
     * @param ?\Closure $runner command runner, Shell::runner() by default
     */
    public function __construct(
        private string $sourceAppDir,
        private string $stagingDir,
        private ?string $composerBinary = null,
        private ?\Closure $runner = null,
    ) {
        $this->composerBinary ??= 'composer';
        $this->runner ??= Shell::runner();
    }

    /** @return string the staging directory, fully prepared and verified */
    public function prepare(): string
    {
        if (!is_file($this->sourceAppDir . '/composer.json')) {
            throw new \RuntimeException(sprintf(
                'No composer.json in %s: a Kip app needs one (vendor/autoload.php is required at boot).',
                $this->sourceAppDir
            ));
        }
        $this->stagingDir = $this->stagingOutsidePathPackages();
        $this->wipeStaging();
        $this->copyTree($this->sourceAppDir, $this->stagingDir, true);
        $this->installDependencies();
        $this->pruneVendor();
        $this->assertShipped();
        return $this->stagingDir;
    }

    /**
     * A staging copy that sits inside a path package's own source tree makes
     * composer refuse to install the package into itself (the bundled apps pin
     * the framework exactly that way, app/build under the framework checkout).
     * When any resolved path package is an ancestor of the staging directory,
     * staging moves to a per-app directory in the system temp dir; prepare()
     * returns the location actually used.
     */
    private function stagingOutsidePathPackages(): string
    {
        $jsonPath = $this->sourceAppDir . '/composer.json';
        try {
            $json = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("composer.json in {$this->sourceAppDir} is not valid JSON: {$e->getMessage()}");
        }
        foreach ((is_array($json['repositories'] ?? null) ? $json['repositories'] : []) as $repo) {
            if (!is_array($repo) || ($repo['type'] ?? '') !== 'path' || !is_string($repo['url'] ?? null)) continue;
            $url = $repo['url'];
            if (!str_starts_with($url, '/') && !str_starts_with($url, 'file://')) $url = $this->sourceAppDir . '/' . $url;
            $resolved = realpath($url);
            if ($resolved === false) {
                throw new \RuntimeException(sprintf(
                    'composer.json pins a path repository at %s that does not exist (looked from %s). '
                    . 'Fix the repository URL or remove the repository before building.',
                    $url,
                    $this->sourceAppDir
                ));
            }
            if ($this->pathIsInside($this->stagingDir, $resolved)) {
                return sys_get_temp_dir() . '/kip-build-staging-' . hash('sha1', $this->sourceAppDir);
            }
        }
        return $this->stagingDir;
    }

    /**
     * Containment on canonical spellings: macOS hands out /var/... paths while
     * realpath() answers /private/var/..., and a raw prefix compare between
     * the two spellings silently misses the overlap. The deepest existing
     * ancestor of the path is canonicalized (the non-existent tail cannot
     * carry symlinks) before the prefix check.
     */
    private function pathIsInside(string $path, string $ancestor): bool
    {
        $ancestor = (string) realpath(rtrim($ancestor, '/'));
        if ($ancestor === '') return false;
        $tail = [];
        $probe = rtrim($path, '/');
        while ($probe !== '' && $probe !== '/' && $probe !== '.') {
            $real = realpath($probe);
            if ($real !== false) {
                $canonical = $tail === [] ? $real : $real . '/' . implode('/', $tail);
                return str_starts_with($canonical, $ancestor . '/');
            }
            $tail[] = basename($probe);
            $probe = dirname($probe);
        }
        return false;
    }

    /**
     * The shipped-inventory assertion. Every top-level source directory that
     * is not on the exclusion list must exist in staging; app/Features gets
     * its own named refusal because a partial artifact that drops it silently
     * under-migrates: feature migrations live there.
     */
    public function assertShipped(): void
    {
        $sourceFeatures = $this->sourceAppDir . '/app/Features';
        if (file_exists($sourceFeatures) && !file_exists($this->stagingDir . '/app/Features')) {
            throw new \RuntimeException(sprintf(
                'app/Features exists in the source app but is missing from %s: refusing to package a partial artifact. '
                . 'Feature-folder migrations would be silently skipped and kip migrate would apply a short schema '
                . 'while reporting success. Fix the step that dropped it and build again.',
                $this->stagingDir
            ));
        }
        $entries = @scandir($this->sourceAppDir);
        if ($entries === false) {
            throw new \RuntimeException("Cannot list {$this->sourceAppDir} to verify the staging inventory.");
        }
        foreach ($entries as $entry) {
            if ($entry[0] === '.' || !is_dir($this->sourceAppDir . '/' . $entry)) continue;
            if (in_array($entry, self::SKIP_DIRS_ANYWHERE, true) || in_array($entry, self::SKIP_DIRS_AT_ROOT, true)) continue;
            if (!is_dir($this->stagingDir . '/' . $entry)) {
                throw new \RuntimeException(sprintf(
                    'The staging copy in %s is a partial artifact: source directory %s is missing from it. '
                    . 'Refusing to package it; fix the step that dropped it and build again.',
                    $this->stagingDir,
                    $entry
                ));
            }
        }
    }

    private function wipeStaging(): void
    {
        if (is_dir($this->stagingDir)) {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else @unlink($f);
                }
                @rmdir($dir);
            };
            $rm($this->stagingDir);
        }
        if (!is_dir($this->stagingDir) && !@mkdir($this->stagingDir, 0755, true) && !is_dir($this->stagingDir)) {
            throw new \RuntimeException("Cannot create staging directory {$this->stagingDir}.");
        }
    }

    private function copyTree(string $from, string $to, bool $atRoot = false): void
    {
        $entries = @scandir($from);
        if ($entries === false) {
            $why = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Cannot list {$from}: {$why}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $from . '/' . $entry;
            $rel = ltrim(substr($path, strlen($this->sourceAppDir) + 1), '/');
            if ($this->isExcluded($path, $rel, $atRoot)) continue;
            $dest = $to . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
                    throw new \RuntimeException("Cannot create {$dest}.");
                }
                $this->copyTree($path, $dest);
            } elseif (is_file($path)) {
                if (!@copy($path, $dest)) {
                    $why = error_get_last()['message'] ?? 'unknown error';
                    throw new \RuntimeException("Cannot copy {$path} to {$dest}: {$why}.");
                }
            }
        }
    }

    /** @param string $rel path relative to the app root, forward slashes */
    private function isExcluded(string $path, string $rel, bool $atRoot): bool
    {
        $name = basename($path);
        if (is_dir($path) && !is_link($path)) {
            if (in_array($name, self::SKIP_DIRS_ANYWHERE, true)) return true;
            if ($atRoot && in_array($name, self::SKIP_DIRS_AT_ROOT, true)) return true;
            if (in_array($rel, self::SKIP_PATHS, true)) return true;
            return false;
        }
        if ($name === '.DS_Store') return true;
        foreach (self::SKIP_FILE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) return true;
        }
        return false;
    }

    private function installDependencies(): void
    {
        $reposChanged = $this->materializePathRepositories();
        $composer = escapeshellarg($this->composerBinary);
        // A relocated path repository also invalidates the content hash, and a
        // lockless app cannot be installed at all: resolve from scratch then.
        $install = $reposChanged && !is_file($this->stagingDir . '/composer.lock')
            ? $composer . ' update --no-dev --no-interaction --prefer-dist --no-progress'
            : $composer . ' install --no-dev --no-interaction --prefer-dist --no-progress';
        /** @var array{0: int, 1: string} $result */
        $result = ($this->runner)($install, $this->stagingDir, []);
        [$code, $output] = $result;
        if ($code !== 0) {
            throw new \RuntimeException("Dependency install failed in staging (exit {$code}):\n{$output}");
        }
    }

    /**
     * A relocated staging copy breaks relative path repositories (the bundled
     * apps pin the framework through "../"), and a symlinked path package
     * would let staging pruning follow a link into the source checkout. Path
     * repositories are rewritten to absolute source paths with symlink forced
     * off, an existing lock is refreshed to match, and `composer install`
     * then materializes a real copy under staging/vendor.
     *
     * @return bool true when any repository entry was rewritten
     */
    private function materializePathRepositories(): bool
    {
        $jsonPath = $this->stagingDir . '/composer.json';
        $json = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $repos = $json['repositories'] ?? [];
        if (!is_array($repos) || $repos === []) return false;
        $changed = false;
        foreach ($repos as $i => $repo) {
            if (!is_array($repo) || ($repo['type'] ?? '') !== 'path' || !is_string($repo['url'] ?? null)) continue;
            $url = $repo['url'];
            if (!str_starts_with($url, '/') && !str_starts_with($url, 'file://')) {
                $url = $this->sourceAppDir . '/' . $url;
            }
            $resolved = realpath($url);
            if ($resolved === false) {
                throw new \RuntimeException(sprintf(
                    'composer.json pins a path repository at %s that does not exist (looked from %s). '
                    . 'Fix the repository URL or remove the repository before building.',
                    $url,
                    $this->sourceAppDir
                ));
            }
            $repos[$i]['url'] = $resolved;
            $repos[$i]['options']['symlink'] = false;
            $changed = true;
        }
        if (!$changed) return false;
        $json['repositories'] = $repos;
        file_put_contents($jsonPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        if (is_file($this->stagingDir . '/composer.lock')) {
            /** @var array{0: int, 1: string} $lock */
            $lock = ($this->runner)(escapeshellarg($this->composerBinary) . ' update --lock --no-install --no-interaction', $this->stagingDir, []);
            if ($lock[0] !== 0) {
                throw new \RuntimeException("Refreshing composer.lock for the relocated path repositories failed (exit {$lock[0]}):\n{$lock[1]}");
            }
        }
        return true;
    }

    private function pruneVendor(): void
    {
        $vendor = $this->stagingDir . '/vendor';
        if (!is_dir($vendor)) return;
        $rm = static function (string $dir) use (&$rm): void {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_dir($f) && !is_link($f)) $rm($f); else @unlink($f);
            }
            @rmdir($dir);
        };
        foreach (glob($vendor . '/*/*', GLOB_ONLYDIR) ?: [] as $pkg) {
            foreach (self::VENDOR_PRUNE_DIRS as $junk) {
                $dir = $pkg . '/' . $junk;
                if (is_dir($dir)) $rm($dir);
            }
        }
    }
}
