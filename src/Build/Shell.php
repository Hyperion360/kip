<?php // src/Build/Shell.php

declare(strict_types=1);
namespace Kip\Build;

/**
 * The one process primitive the build battery uses: run a shell command in a
 * directory with optional env overrides, return [exit code, combined output].
 * Injected as a Closure into every build class so the suite can record and
 * fake commands instead of executing them; nothing here is framework runtime
 * code, `kip build` is a manual, local command and nothing else ever calls it.
 */
final class Shell
{
    /**
     * Default runner. Env overrides ride as shell assignment prefixes so the
     * child still inherits the full parent environment (composer needs HOME,
     * PATH, etc.); the command itself is passed through the shell unchanged,
     * same contract the CLI tests use.
     *
     * @return \Closure(string, ?string, array<string,string>): array{0: int, 1: string}
     */
    public static function runner(): \Closure
    {
        return static function (string $command, ?string $cwd = null, array $env = []): array {
            $prefix = '';
            foreach ($env as $name => $value) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                    throw new \RuntimeException("Refusing to pass env name {$name} to a build command.");
                }
                $prefix .= $name . '=' . escapeshellarg((string) $value) . ' ';
            }
            $line = 'cd ' . escapeshellarg($cwd ?? (string) getcwd()) . ' && ' . $prefix . $command . ' 2>&1';
            exec($line, $lines, $code);
            return [$code, implode("\n", $lines)];
        };
    }
}
