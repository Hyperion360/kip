<?php // src/Scaffold.php

declare(strict_types=1);
namespace Kip;

use Kip\Routing\Router;

/**
 * The create-only generators behind kip make:feature / make:controller /
 * make:migration (guide ch. 3 + ch. 8). They EMIT files and never edit one:
 * every collision is a named refusal before a byte is written, emission
 * itself is exclusive (fopen 'x', so a concurrent run or a dangling symlink
 * fails instead of overwriting), and a failed run unlinks only what that
 * run created. Emitted code matches the apps' house style: no base class,
 * constructor injection, a working index action, no TODO markers.
 */
final class Scaffold
{
    /** One studly word per URL segment, single separators only, letter-first (mirrors the Router's own segment rule). */
    private const NAME = '/^[A-Za-z][A-Za-z0-9]*(?:[-_][A-Za-z0-9]+)*$/';

    /** A namespace PREFIX must end in a backslash: the Router concatenates it raw. */
    private const NAMESPACE_PREFIX = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/';

    /** Filesystem component limit; the refusal happens before any write, not at fopen time. */
    private const COMPONENT_LIMIT = 255;

    private const FEATURE_CONTROLLER = <<<'PHP'
<?php // __COMMENT__
namespace __NS____STUDLY__;

use Kip\View;

final class __STUDLY__Controller
{
    public function __construct(private View $view) {}

    public function index(): string
    {
        return $this->view->render('__KEBAB__/index', ['title' => '__STUDLY__']);
    }
}

PHP;

    private const CONTROLLER = <<<'PHP'
<?php // __COMMENT__
namespace __NS__;

use Kip\View;

final class __STUDLY__Controller
{
    public function __construct(private View $view) {}

    public function index(): string
    {
        return $this->view->render('__KEBAB__/index', ['title' => '__STUDLY__']);
    }
}

PHP;

    private const VIEW = <<<'PHP'
<?php // __COMMENT__ ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
PHP;

    private const FEATURE_TEST = <<<'PHP'
<?php // __COMMENT__
namespace __NS____STUDLY__\Tests;

use __NS____STUDLY__\__STUDLY__Controller;
use Kip\View;
use PHPUnit\Framework\TestCase;

final class __STUDLY__ControllerTest extends TestCase
{
    public function test_index_renders_the_feature_template(): void
    {
        $appDir = dirname(__DIR__, 3);
        $view = new View($appDir . '/views', dirname(__DIR__, 2));
        $this->assertStringContainsString('<h1>', (new __STUDLY__Controller($view))->index());
    }
}

PHP;

    private const MIGRATION = <<<'PHP'
<?php // __COMMENT__
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void {}
    public function down(Kip\Database $db): void {}
};
PHP;

    /**
     * Emit the feature-folder shape of guide ch. 3: the feature controller,
     * its own index template, an empty migrations directory (a .gitkeep the
     * Migrator skips by its dotfile rule, so git keeps the directory) and
     * the ch. 3 Tests/ stub. The template renders through '<kebab>/index',
     * which View resolves back into this folder via Router::studly.
     *
     * @param array{plain_controller_dir?: string, app_views_dir?: string, reserved_studly?: list<string>} $guard
     * @return list<string> the files written, absolute
     */
    public static function feature(string $name, string $featuresDir, string $featureNamespace, array $guard = []): array
    {
        $studly = self::validatedStudly($name);
        self::assertValidNamespace($featureNamespace, 'feature_namespace');
        $kebab = self::kebab($studly);
        $featuresDir = rtrim($featuresDir, '/');
        $dir = $featuresDir . '/' . $studly;

        if (self::ciSiblingExists($featuresDir, $studly)) {
            throw new \RuntimeException("feature folder {$dir} already exists; kip make: never edits, delete it first or pick another name");
        }
        $plainDir = $guard['plain_controller_dir'] ?? null;
        if (is_string($plainDir) && self::ciSiblingExists($plainDir, $studly . 'Controller.php')) {
            throw new \RuntimeException("a plain {$studly}Controller exists in {$plainDir}; the plain one wins (guide ch. 3), the feature controller would be dead code");
        }
        $viewsDir = $guard['app_views_dir'] ?? null;
        if (is_string($viewsDir) && self::ciSiblingExists($viewsDir, $kebab)) {
            throw new \RuntimeException("template folder {$viewsDir}/{$kebab} already exists; an app-root template would shadow the feature's own (guide ch. 3)");
        }
        foreach ($guard['reserved_studly'] ?? [] as $reserved) {
            if (strcasecmp($reserved, $studly) === 0) {
                throw new \RuntimeException("name {$studly} is reserved: the built-in admin panel resolves /" . strtolower($studly) . " first (guide ch. 3)");
            }
        }

        $controller = $dir . '/' . $studly . 'Controller.php';
        $view = $dir . '/views/index.php';
        $gitkeep = $dir . '/migrations/.gitkeep';
        $test = $dir . '/Tests/' . $studly . 'ControllerTest.php';
        foreach ([$controller, $view, $gitkeep, $test] as $path) {
            self::assertComponentsFit($path);
            self::assertPathFree($path);
            self::assertAncestorsAreDirs($path);
        }

        $base = self::commentBaseFor($controller);
        $fill = static function (string $template, string $path) use ($featureNamespace, $studly, $kebab, $base): string {
            return str_replace(
                ['__COMMENT__', '__NS__', '__STUDLY__', '__KEBAB__'],
                [self::relative($path, $base), $featureNamespace, $studly, $kebab],
                $template,
            );
        };
        self::emit([
            ['dir', $dir],
            ['file', $controller, $fill(self::FEATURE_CONTROLLER, $controller)],
            ['dir', $dir . '/views'],
            ['file', $view, $fill(self::VIEW, $view)],
            ['dir', $dir . '/migrations'],
            ['file', $gitkeep, ''],
            ['dir', $dir . '/Tests'],
            ['file', $test, $fill(self::FEATURE_TEST, $test)],
        ]);
        return [$controller, $view, $gitkeep, $test];
    }

    /**
     * Emit a layered controller and its view template (guide ch. 3's default
     * layout): <controllerDir>/<Studly>Controller.php plus
     * <viewsDir>/<kebab>/index.php.
     *
     * @return list<string> the files written, absolute
     */
    public static function controller(string $name, string $namespace, string $controllerDir, string $viewsDir, ?string $featuresDir = null): array
    {
        $studly = self::validatedStudly($name);
        self::assertValidNamespace($namespace, 'controller_namespace');
        $kebab = self::kebab($studly);
        $controllerDir = rtrim($controllerDir, '/');
        $viewsDir = rtrim($viewsDir, '/');
        $controller = $controllerDir . '/' . $studly . 'Controller.php';
        $viewDir = $viewsDir . '/' . $kebab;
        $view = $viewDir . '/index.php';

        if (self::ciSiblingExists($controllerDir, $studly . 'Controller.php')) {
            throw new \RuntimeException("{$controller} already exists; kip make: never edits existing files");
        }
        if (self::ciSiblingExists($viewsDir, $kebab)) {
            throw new \RuntimeException("template folder {$viewDir} already exists; kip make: never edits existing files");
        }
        if ($featuresDir !== null && self::ciSiblingExists(rtrim($featuresDir, '/'), $studly)) {
            throw new \RuntimeException("feature folder " . rtrim($featuresDir, '/') . "/{$studly} already exists; the plain controller would take over its route (guide ch. 3)");
        }
        foreach ([$controller, $view] as $path) {
            self::assertComponentsFit($path);
            self::assertPathFree($path);
            self::assertAncestorsAreDirs($path);
        }

        $base = self::commentBaseFor($controller);
        $fill = static function (string $template, string $path) use ($namespace, $studly, $kebab, $base): string {
            return str_replace(
                ['__COMMENT__', '__NS__', '__STUDLY__', '__KEBAB__'],
                [self::relative($path, $base), rtrim($namespace, '\\'), $studly, $kebab],
                $template,
            );
        };
        // The controller is written before the view on purpose: a failure while
        // creating the view folder (a read-only views dir) proves the cleanup
        // path, and no half-emission survives either way.
        self::emit([
            ['dir', $controllerDir],
            ['file', $controller, $fill(self::CONTROLLER, $controller)],
            ['dir', $viewDir],
            ['file', $view, $fill(self::VIEW, $view)],
        ]);
        return [$controller, $view];
    }

    /**
     * Emit the next migration into $targetDir, numbered against the WHOLE
     * ledger: $migrationDirs are the same directories kip migrate reads, so
     * the next NNN is unique app-wide and the new filename must sort after
     * every existing one (the ledger runs in global filename order).
     *
     * @param list<string> $migrationDirs
     * @param list<string> $appliedNames names already recorded in _migrations; a deleted file's row must not be regenerated, migrate would silently skip it
     * @return string the file written, absolute
     */
    public static function migration(string $label, array $migrationDirs, string $targetDir, array $appliedNames = []): string
    {
        $name = self::normalizeLabel($label);
        if (!in_array(rtrim($targetDir, '/'), array_map('rtrim', $migrationDirs), true)) {
            throw new \InvalidArgumentException("target directory {$targetDir} is not one of the migration directories kip migrate reads");
        }
        $targetDir = rtrim($targetDir, '/');
        [$max, $names] = self::inventory($migrationDirs);
        $filename = sprintf('%03d_%s.php', $max + 1, $name);
        $ledgerName = substr($filename, 0, -4);
        if (in_array($ledgerName, $appliedNames, true)) {
            throw new \RuntimeException("migration {$ledgerName} was already applied and its file deleted: migrate would silently skip a regenerated file with that name. Choose a different label.");
        }
        foreach ($names as $existingName => $existingFile) {
            if ($existingName === $ledgerName) {
                throw new \RuntimeException("migration name {$ledgerName} already exists as {$existingFile}");
            }
            if (strcmp($filename, $existingFile) <= 0) {
                throw new \RuntimeException("{$filename} would sort before the existing migration {$existingFile}; the ledger runs in global filename order. Rename the unpadded existing files, or add this migration by hand.");
            }
        }
        $path = $targetDir . '/' . $filename;
        self::assertComponentsFit($path);
        self::assertPathFree($path);
        self::assertAncestorsAreDirs($path);
        $comment = self::relative($path, self::commentBaseFor($path));
        self::emit([
            ['dir', $targetDir],
            ['file', $path, str_replace('__COMMENT__', $comment, self::MIGRATION)],
        ]);
        return $path;
    }

    /** Studly name -> the URL segment spelling ('BillingLists' -> 'billing-lists'); Router::studly inverts it on the way in. */
    public static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }

    /** The canonical URL for a generated index action: '/' for Home, '/<kebab>' otherwise ('/home' is not a URL). */
    public static function routeFor(string $name): string
    {
        $studly = Router::studly($name);
        return $studly === 'Home' ? '/' : '/' . self::kebab($studly);
    }

    /**
     * The absolute file paths the app's composer.json psr-4 autoloader would
     * try for $class (longest matching prefix wins, the Composer rule, array
     * mappings included). Empty when there is no composer.json or no prefix
     * matches: the class would not autoload where it sits.
     *
     * @return list<string>
     */
    public static function autoloadPaths(?string $composerFile, string $class): array
    {
        if ($composerFile === null || !is_file($composerFile)) return [];
        $decoded = json_decode((string) file_get_contents($composerFile), true);
        $psr4 = is_array($decoded) ? ($decoded['autoload']['psr-4'] ?? []) : [];
        if (!is_array($psr4)) return [];
        $best = '';
        /** @var list<string> $dirs */
        $dirs = [];
        foreach ($psr4 as $prefix => $mapped) {
            if (!is_string($prefix) || !str_starts_with($class, $prefix) || strlen($prefix) <= strlen($best)) continue;
            $best = $prefix;
            $dirs = is_array($mapped) ? array_values(array_filter($mapped, 'is_string')) : (is_string($mapped) ? [$mapped] : []);
        }
        if ($best === '') return [];
        $remainder = str_replace('\\', '/', substr($class, strlen($best)));
        $root = dirname($composerFile);
        $paths = [];
        foreach ($dirs as $dir) {
            $paths[] = rtrim($root . '/' . trim($dir, '/'), '/') . '/' . $remainder . '.php';
        }
        return $paths;
    }

    /** $path relative to $base when it is under it, absolute otherwise. */
    public static function relative(string $path, string $base): string
    {
        $base = rtrim($base, '/');
        return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
    }

    /**
     * @param list<array{0: 'dir'|'file', 1: string, 2?: string}> $steps ordered
     *   creation steps; a later step may rely on a directory from an earlier one
     */
    private static function emit(array $steps): void
    {
        $made = [];
        $wrote = [];
        try {
            foreach ($steps as $step) {
                if ($step[0] === 'dir') {
                    $missing = [];
                    for ($p = $step[1]; $p !== '' && $p !== '/' && !is_dir($p); $p = dirname($p)) $missing[] = $p;
                    foreach (array_reverse($missing) as $make) {
                        if (!@mkdir($make, 0777) && !is_dir($make)) {
                            throw new \RuntimeException("cannot create directory {$make}: the parent is not writable");
                        }
                        $made[] = $make;
                    }
                    continue;
                }
                $content = $step[2] ?? '';
                $h = @fopen($step[1], 'x');
                if ($h === false) {
                    throw new \RuntimeException("cannot create {$step[1]}: it already exists or its directory is not writable");
                }
                $bytes = fwrite($h, $content);
                fclose($h);
                if ($bytes !== strlen($content)) {
                    throw new \RuntimeException("short write creating {$step[1]}");
                }
                $wrote[] = $step[1];
            }
        } catch (\Throwable $e) {
            // Only THIS run's own artifacts are removed, deepest first; the
            // generator never touches anything it did not create.
            foreach (array_reverse($wrote) as $path) @unlink($path);
            foreach (array_reverse($made) as $dir) @rmdir($dir);
            throw $e;
        }
    }

    /**
     * The merged ledger view: the max leading number, and every migration's
     * name (basename without extension, the Migrator's identity) => filename,
     * mirroring Migrator::files()' own entry rules: dotfiles skipped, .php
     * and .sql only, missing directories contribute nothing.
     *
     * @param list<string> $dirs
     * @return array{0: int, 1: array<string, string>}
     */
    private static function inventory(array $dirs): array
    {
        $max = 0;
        $names = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry[0] === '.') continue;
                $ext = pathinfo($entry, PATHINFO_EXTENSION);
                if ($ext !== 'php' && $ext !== 'sql') continue;
                if (preg_match('/^(\d+)/', $entry, $m) === 1) $max = max($max, (int) $m[1]);
                $names[pathinfo($entry, PATHINFO_FILENAME)] = $entry;
            }
        }
        return [$max, $names];
    }

    private static function normalizeLabel(string $label): string
    {
        $label = (string) preg_replace('/[- ]+/', '_', strtolower(trim($label)));
        if ($label === '' || preg_match('/^[a-z0-9_]+$/', $label) !== 1) {
            throw new \InvalidArgumentException('migration label must be words separated by spaces, dashes or underscores (for example "create invoices"); nothing is dropped silently');
        }
        return $label;
    }

    private static function validatedStudly(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException("name \"{$name}\" is not a valid controller name: letters and digits, single - or _ separators (billing-lists, BillingLists)");
        }
        return Router::studly($name);
    }

    private static function assertValidNamespace(string $namespace, string $what): void
    {
        if (preg_match(self::NAMESPACE_PREFIX, $namespace) !== 1) {
            throw new \InvalidArgumentException("{$what} \"{$namespace}\" must be a namespace prefix ending in a backslash, like App\\Features\\ (the Router concatenates it raw)");
        }
    }

    /** PHP class names are case-insensitive: BillingLists and Billinglists collide even on case-sensitive filesystems. */
    private static function ciSiblingExists(string $dir, string $entry): bool
    {
        if (!is_dir($dir)) return false;
        $lower = strtolower($entry);
        foreach (scandir($dir) ?: [] as $sibling) {
            if ($sibling !== '.' && $sibling !== '..' && strtolower($sibling) === $lower) return true;
        }
        return false;
    }

    private static function assertPathFree(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("{$path} already exists; kip make: never edits existing files");
        }
    }

    /** The first existing ancestor of $path must be a directory; a file in the chain blocks everything below it. */
    private static function assertAncestorsAreDirs(string $path): void
    {
        for ($p = dirname($path); $p !== '' && $p !== '.' && $p !== '/'; $p = dirname($p)) {
            if (file_exists($p) || is_link($p)) {
                if (!is_dir($p)) {
                    throw new \RuntimeException("{$p} exists and is not a directory, so {$path} cannot be created");
                }
                return;
            }
        }
    }

    private static function assertComponentsFit(string $path): void
    {
        for ($p = $path; $p !== '' && $p !== '.' && $p !== '/'; $p = dirname($p)) {
            $base = basename($p);
            if (strlen($base) > self::COMPONENT_LIMIT) {
                throw new \InvalidArgumentException("path component {$base} exceeds the " . self::COMPONENT_LIMIT . "-byte filesystem limit; pick a shorter name");
            }
        }
    }

    /**
     * The base emitted path comments are written against: the app root above
     * the nearest 'app' directory, so headers read app/Features/... like the
     * guide's examples. Outside such a tree the absolute path is used.
     */
    private static function commentBaseFor(string $path): string
    {
        for ($p = dirname($path); $p !== '' && $p !== '/' && $p !== '.'; $p = dirname($p)) {
            if (basename($p) === 'app') return dirname($p);
        }
        return dirname($path);
    }
}
