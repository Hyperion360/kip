<?php // tests/Build/PreparerTest.php
namespace Kip\Tests\Build;

use PHPUnit\Framework\TestCase;

/**
 * `kip build`'s staging step (Kip\Build\Preparer): the app tree is copied
 * minus dev junk and runtime databases, dependencies are reinstalled with
 * --no-dev (path repositories materialized as real copies), tests/docs are
 * pruned from vendor, and the shipped-inventory assertion runs LAST, after
 * every staging mutation. The assertion is the deploy-pipeline guard from
 * TODOS: a package that drops app/Features makes kip migrate apply a short
 * schema while reporting success, so the preparer refuses a partial artifact
 * by name.
 */
final class PreparerTest extends TestCase
{
    private string $src;
    private string $staging;
    /** @var list<string> stub scripts awaiting cleanup */
    private array $stubs = [];

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/kip-prep-' . bin2hex(random_bytes(4));
        $this->src = $base . '/app';
        $this->staging = $base . '/build/staging';
        foreach ([
            '/app/migrations', '/app/Features/Billing/migrations', '/public', '/bin',
            '/vendor', '/tests', '/docs', '/.git/objects', '/app/backups', '/build/old',
        ] as $sub) {
            mkdir($this->src . $sub, 0777, true);
        }
        file_put_contents($this->src . '/composer.json', <<<'JSON'
        {
            "name": "test/app",
            "require": { "php": ">=8.3" },
            "autoload": { "psr-4": { "App\\": "app/src/" } }
        }
        JSON);
        file_put_contents($this->src . '/config.php', "<?php return [];\n");
        file_put_contents($this->src . '/bin/kip', "<?php // cli\n");
        file_put_contents($this->src . '/public/index.php', "<?php // front\n");
        file_put_contents($this->src . '/app/migrations/001_users.php', "<?php // m\n");
        file_put_contents($this->src . '/app/Features/Billing/migrations/008_billing.php', "<?php // m\n");
        // Runtime state and local output must never ship inside an artifact.
        file_put_contents($this->src . '/app/mail.log', "sent\n");
        foreach (['data.sqlite', 'logs.sqlite', 'cache.sqlite-wal', 'data.sqlite-shm'] as $db) {
            file_put_contents($this->src . '/app/' . $db, 'runtime state');
        }
        file_put_contents($this->src . '/app/backups/kip-backup-1.zip', 'zip');
        file_put_contents($this->src . '/vendor/devonly.php', '<?php // dev tool marker');
        file_put_contents($this->src . '/tests/Unit.php', '<?php // t');
        file_put_contents($this->src . '/docs/guide.md', '# doc');
        file_put_contents($this->src . '/.git/objects/pack.txt', 'git');
        file_put_contents($this->src . '/build/old/stale-artifact', 'x');
    }

    protected function tearDown(): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            if (is_dir(dirname($this->src))) $rm(dirname($this->src));
            foreach ($this->stubs as $s) @unlink($s);
        } finally { restore_error_handler(); }
    }

    /** Writes a stub `composer` that logs cwd + argv and can mutate staging before exiting 0. */
    private function stubComposer(string $action = 'record'): string
    {
        $stub = (string) tempnam(sys_get_temp_dir(), 'kip-stub-composer-');
        $log = escapeshellarg($stub . '.log');
        $staging = escapeshellarg($this->staging);
        $extra = match ($action) {
            'drop-features' => "rm -rf {$staging}/app/Features\n",
            'dirty-vendor' => "mkdir -p {$staging}/vendor/acme/pkg/tests {$staging}/vendor/acme/pkg/docs {$staging}/vendor/acme/pkg/src\n"
                . "touch {$staging}/vendor/acme/pkg/tests/a.php {$staging}/vendor/acme/pkg/docs/b.md {$staging}/vendor/acme/pkg/src/keep.php\n",
            'fail' => "echo 'composer blew up: no disk'\nexit 22\n",
            default => '',
        };
        file_put_contents($stub, "#!/bin/sh\necho \"cwd=$(pwd) args=$*\" >> {$log}\n{$extra}exit 0\n");
        chmod($stub, 0755);
        $this->stubs[] = $stub;
        return $stub;
    }

    private function preparer(string $composer = 'composer'): \Kip\Build\Preparer
    {
        return new \Kip\Build\Preparer($this->src, $this->staging, $composer);
    }

    public function test_prepare_copies_the_app_without_dev_junk_or_runtime_state(): void
    {
        $this->preparer($this->stubComposer())->prepare();

        foreach (['app/migrations/001_users.php', 'app/Features/Billing/migrations/008_billing.php', 'public/index.php', 'config.php', 'bin/kip', 'composer.json'] as $kept) {
            $this->assertFileExists($this->staging . '/' . $kept, $kept);
        }
        foreach (['app/data.sqlite', 'app/logs.sqlite', 'app/cache.sqlite-wal', 'app/data.sqlite-shm', 'app/mail.log', 'app/backups', 'vendor/devonly.php', 'tests', 'docs', '.git', 'build'] as $dropped) {
            $this->assertFileDoesNotExist($this->staging . '/' . $dropped, $dropped);
        }
    }

    public function test_prepare_reinstalls_dependencies_with_no_dev_in_staging(): void
    {
        $stub = $this->stubComposer();
        $this->preparer($stub)->prepare();

        $log = (string) file_get_contents($stub . '.log');
        $this->assertStringContainsString('install --no-dev --no-interaction --prefer-dist --no-progress', $log);
        $this->assertStringContainsString('cwd=' . $this->staging, $log);
    }

    public function test_prepare_prunes_tests_and_docs_from_vendor_after_install(): void
    {
        $this->preparer($this->stubComposer('dirty-vendor'))->prepare();

        $this->assertFileDoesNotExist($this->staging . '/vendor/acme/pkg/tests/a.php');
        $this->assertFileDoesNotExist($this->staging . '/vendor/acme/pkg/docs/b.md');
        $this->assertFileExists($this->staging . '/vendor/acme/pkg/src/keep.php');
    }

    /**
     * The deploy-pipeline assertion, end to end: a middle step that drops
     * app/Features from the staging copy (here: the dependency install)
     * makes prepare() itself fail, proving assertShipped() runs after every
     * staging mutation, not just after the copy.
     */
    public function test_prepare_refuses_a_staging_copy_that_lost_app_features(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('app/Features');
        $this->preparer($this->stubComposer('drop-features'))->prepare();
    }

    public function test_the_partial_artifact_error_names_the_failure_mode(): void
    {
        $this->preparer($this->stubComposer())->prepare();
        $rm = static function (string $dir) use (&$rm): void {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
            }
            rmdir($dir);
        };
        $rm($this->staging . '/app/Features');

        try {
            $this->preparer($this->stubComposer())->assertShipped();
            $this->fail('assertShipped() must refuse a staging copy missing app/Features');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('app/Features', $e->getMessage());
            $this->assertStringContainsString('partial artifact', $e->getMessage());
            $this->assertStringContainsString('short schema', $e->getMessage());
        }
    }

    public function test_assert_shipped_also_refuses_a_generic_missing_root_directory(): void
    {
        $this->preparer($this->stubComposer())->prepare();
        rename($this->staging . '/public', $this->staging . '/public-renamed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('public');
        $this->preparer($this->stubComposer())->assertShipped();
    }

    public function test_assert_shipped_passes_when_the_inventory_is_complete(): void
    {
        $this->preparer($this->stubComposer())->prepare();
        $this->expectNotToPerformAssertions();
        $this->preparer($this->stubComposer())->assertShipped();
    }

    public function test_prepare_fails_loudly_without_composer_json(): void
    {
        unlink($this->src . '/composer.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.json');
        $this->preparer($this->stubComposer())->prepare();
    }

    public function test_prepare_surfaces_a_failing_dependency_install(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer blew up');
        $this->preparer($this->stubComposer('fail'))->prepare();
    }

    /**
     * Found by the first real run: the bundled apps pin the framework
     * through a path repository whose source IS the repo root, so a staging
     * copy under app/build would sit inside the package's own source and
     * composer refuses to install a package into itself. The staging copy
     * relocates outside every path package, and prepare() returns the real
     * location it used.
     */
    public function test_staging_relocates_out_of_a_path_package_source(): void
    {
        // The bundled-app shape: the path package (url ".." from the app) is
        // an ancestor of app/build/staging, and composer refuses to install a
        // package into its own source tree.
        $packageRoot = dirname($this->src);
        file_put_contents($this->src . '/composer.json', <<<'JSON'
        {
            "name": "test/app",
            "require": { "php": ">=8.3" },
            "repositories": [ { "type": "path", "url": ".." } ],
            "autoload": { "psr-4": { "App\\": "app/src/" } }
        }
        JSON);
        // The bundled apps carry a lock; the relocation must refresh it without
        // a second full install (--no-install).
        file_put_contents($this->src . '/composer.lock', "{\n  \"packages\": []\n}\n");

        $stub = $this->stubComposer();
        $actual = $this->preparer($stub)->prepare();

        try {
            $this->assertStringStartsWith(sys_get_temp_dir(), $actual, 'staging leaves the package tree');
            $this->assertStringStartsNotWith($packageRoot . '/', $actual, 'staging never sits inside the path package source');
            $this->assertFileExists($actual . '/app/Features/Billing/migrations/008_billing.php', 'the relocated copy is complete');
            $log = (string) file_get_contents($stub . '.log');
            $this->assertStringContainsString('cwd=' . $actual, $log, 'composer runs in the relocated staging');
            $this->assertStringContainsString('--no-install', $log, 'the lock refresh never installs (the --no-dev install does)');
        } finally {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            if (is_dir($actual) && str_starts_with(basename($actual), 'kip-build-staging-')) $rm($actual);
        }
    }

    public function test_prepare_names_a_source_composer_json_that_is_not_valid_json(): void
    {
        file_put_contents($this->src . '/composer.json', "{ not json");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->preparer($this->stubComposer())->prepare();
    }

    public function test_prepare_names_a_path_repository_that_does_not_exist(): void
    {
        file_put_contents($this->src . '/composer.json', <<<'JSON'
        {
            "name": "test/app",
            "require": { "php": ">=8.3" },
            "repositories": [ { "type": "path", "url": "../gone" } ],
            "autoload": { "psr-4": { "App\\": "app/src/" } }
        }
        JSON);

        try {
            $this->preparer($this->stubComposer())->prepare();
            $this->fail('a missing path repository must fail the build by name');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('../gone', $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }
    }

    public function test_prepare_rewrites_path_repositories_to_absolute_materialized_copies(): void
    {
        $base = dirname($this->src);
        // A local package + an app that pins it through a RELATIVE path repository,
        // the same shape the bundled apps use for the framework itself.
        mkdir($base . '/fixture-pkg/src', 0777, true);
        file_put_contents($base . '/fixture-pkg/composer.json', <<<'JSON'
        {
            "name": "fixture/lorem",
            "version": "1.0.0",
            "require": { "php": ">=8.3" },
            "autoload": { "psr-4": { "Lorem\\": "src/" } }
        }
        JSON);
        file_put_contents($base . '/fixture-pkg/src/Lorem.php', '<?php namespace Lorem; final class Lorem { const HI = "fixture"; }');
        file_put_contents($this->src . '/composer.json', <<<'JSON'
        {
            "name": "test/app",
            "require": { "php": ">=8.3", "fixture/lorem": "^1.0" },
            "repositories": [ { "type": "path", "url": "../fixture-pkg", "options": { "symlink": true } } ],
            "autoload": { "psr-4": { "App\\": "app/src/" } }
        }
        JSON);

        $snapshotBefore = glob($base . '/fixture-pkg/*') ?: [];
        $this->preparer('composer')->prepare();

        // The staged package is a real copy, not a link into the source checkout.
        $this->assertFileExists($this->staging . '/vendor/fixture/lorem/src/Lorem.php');
        $this->assertFileDoesNotExist($this->src . '/vendor/fixture'); // nothing leaked into the source app
        $pkgDir = $this->staging . '/vendor/fixture/lorem';
        $this->assertFalse(is_link($pkgDir), 'the path repository must materialize, not symlink');
        // The staged autoloader is self-contained: a fresh PHP process resolves
        // the package classes from staging alone (subprocess: no autoloader
        // pollution of this test process).
        $script = sprintf('require %s; echo \\Lorem\\Lorem::HI;', var_export($this->staging . '/vendor/autoload.php', true));
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        $this->assertSame('fixture', implode('', $lines));
        // And the staged composer.json no longer points at a relative path that broke on relocation.
        $stagedJson = (string) file_get_contents($this->staging . '/composer.json');
        $this->assertStringNotContainsString('"../fixture-pkg"', $stagedJson);
        $this->assertStringContainsString(json_encode((string) realpath($base . '/fixture-pkg'), JSON_UNESCAPED_SLASHES), $stagedJson);
        $this->assertStringContainsString('"symlink": false', $stagedJson);
        // The source package itself was never written to.
        $this->assertSame($snapshotBefore, glob($base . '/fixture-pkg/*') ?: []);
    }
}
