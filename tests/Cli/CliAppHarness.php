<?php // tests/Cli/CliAppHarness.php
namespace Kip\Tests\Cli;

/**
 * Shared throwaway-app harness for bin/kip tests: the skeleton's real
 * bin/kip + config.php + migrations in a temp dir, with a hand-rolled
 * PSR-4 autoloader pointing Kip\ at the framework checkout (no composer,
 * no network, no skeleton/vendor dependency. The tests can never silently
 * skip on a fresh clone).
 */
trait CliAppHarness
{
    protected string $cliApp;
    private string $cliRepo;

    /** @var list<string> tempnam files awaiting cleanup */
    private array $cliTmps = [];

    protected function buildCliApp(bool $withMigrations = true): void
    {
        $this->cliRepo = dirname(__DIR__, 2);
        $this->cliApp = sys_get_temp_dir() . '/kip-cli-' . bin2hex(random_bytes(4));
        foreach (['/app/migrations', '/bin', '/public', '/vendor', '/app/src/Controllers'] as $sub) {
            mkdir($this->cliApp . $sub, 0777, true);
        }
        copy($this->cliRepo . '/skeleton/bin/kip', $this->cliApp . '/bin/kip');
        chmod($this->cliApp . '/bin/kip', 0755);
        copy($this->cliRepo . '/skeleton/config.php', $this->cliApp . '/config.php');
        if ($withMigrations) {
            foreach (glob($this->cliRepo . '/skeleton/app/migrations/*.php') as $m) copy($m, $this->cliApp . '/app/migrations/' . basename($m));
        }
        $src = var_export($this->cliRepo . '/src/', true);
        file_put_contents($this->cliApp . '/vendor/autoload.php',
            '<?php // hand-rolled PSR-4 autoloader: Kip\ -> framework checkout, App\ -> this app
spl_autoload_register(static function (string $class): void {
    $map = ["Kip\\\\" => ' . $src . ', "App\\\\" => __DIR__ . "/../app/src/"];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
            if (is_file($file)) require $file;
        }
    }
});'
        );
    }

    /** @return array{0: string, 1: int} [combined output, exit code] */
    protected function cli(array $args, ?string $stdin = null): array
    {
        // cd BEFORE any pipeline: `cd x && printf | php` runs the pipe in the right directory.
        $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ';
        if ($stdin !== null) $cmd .= 'printf %s ' . escapeshellarg($stdin) . ' | ';
        $cmd .= PHP_BINARY . ' ./bin/kip';
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        exec($cmd . ' 2>&1', $lines, $code);
        return [implode("\n", $lines), $code];
    }

    protected function tearDownCliApp(): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            if (isset($this->cliApp) && is_dir($this->cliApp)) $rm($this->cliApp);
        } finally { restore_error_handler(); }
    }
}
