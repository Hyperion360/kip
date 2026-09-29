<?php // tests/Cli/KipMakeTest.php
namespace Kip\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * kip make:feature / make:controller / make:migration (guide ch. 3 + ch. 8)
 * against the throwaway app: exact file sets, lint-clean emitted PHP, the
 * shared failure contract (kip: prefix, exit 1, nothing written), ledger-wide
 * numbering, and scaffolds that actually resolve and render through the
 * framework in a child process (no in-process class caching between tests).
 */
final class KipMakeTest extends TestCase
{
    use CliAppHarness;

    protected function setUp(): void
    {
        $this->buildCliApp();
    }

    protected function tearDown(): void
    {
        $this->tearDownCliApp();
    }

    /** @return list<string> every file under the app dir, sorted, dotfiles included */
    private function appSnapshot(): array
    {
        $files = [];
        $walk = static function (string $dir) use (&$walk, &$files): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $p = $dir . '/' . $entry;
                if (is_dir($p) && !is_link($p)) $walk($p); else $files[] = $p;
            }
        };
        $walk($this->cliApp . '/app');
        sort($files);
        return $files;
    }

    private function assertLint(string $file): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
    }

    /**
     * Boot the scaffolded app in a child process and request $path. A child
     * process per call: no generated class ever pollutes this process's
     * class cache, so two scaffolds may share a name across tests.
     *
     * @return array{0: int, 1: string} [http status, body]
     */
    private function renderThroughFramework(string $path): array
    {
        // The skeleton's layout is what a scaffolded template wraps itself in.
        if (!is_dir($this->cliApp . '/app/views')) mkdir($this->cliApp . '/app/views', 0777, true);
        copy($this->cliRepo . '/skeleton/app/views/layout.php', $this->cliApp . '/app/views/layout.php');
        $script = sprintf(
            'require %s; $app = new Kip\App(["app_dir" => %s, "views" => %s, "controller_namespace" => "App\\\\Controllers\\\\"]);'
            . ' $res = $app->handle(new Kip\Http\Request("GET", %s, [], [], [], "127.0.0.1"));'
            . ' echo $res->status, "\n", $res->body;',
            var_export($this->cliApp . '/vendor/autoload.php', true),
            var_export($this->cliApp . '/app', true),
            var_export($this->cliApp . '/app/views', true),
            var_export($path, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
        $out = implode("\n", $lines);
        $this->assertSame(0, $code, $out);
        return [(int) strtok($out, "\n"), $out];
    }

    public function test_make_feature_emits_the_guide_shape_lints_and_names_the_route(): void
    {
        [$out, $code] = $this->cli(['make:feature', 'Newsletters']);
        $this->assertSame(0, $code, $out);
        $f = $this->cliApp . '/app/Features/Newsletters';
        foreach (['/NewslettersController.php', '/views/index.php', '/migrations/.gitkeep', '/Tests/NewslettersControllerTest.php'] as $suffix) {
            $this->assertFileExists($f . $suffix);
        }
        $this->assertStringContainsString('Created: app/Features/Newsletters/NewslettersController.php', $out);
        $this->assertStringContainsString('Route: /newsletters (NewslettersController::index)', $out);
        $this->assertStringNotContainsString('Note:', $out, 'no composer.json in this app, nothing to advise on');
        $this->assertLint($f . '/NewslettersController.php');
        $this->assertLint($f . '/Tests/NewslettersControllerTest.php');
        $controller = (string) file_get_contents($f . '/NewslettersController.php');
        $this->assertStringContainsString("render('newsletters/index'", $controller);
        $this->assertStringContainsString("namespace App\\Features\\Newsletters;", $controller);
    }

    public function test_make_feature_refuses_an_existing_folder_writing_nothing(): void
    {
        $this->cli(['make:feature', 'Billing']);
        $before = $this->appSnapshot();
        [$out, $code] = $this->cli(['make:feature', 'Billing']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        $this->assertStringContainsString('already exists', $out);
        $this->assertSame($before, $this->appSnapshot(), 'a refusal must not write anything');
    }

    public function test_make_feature_autoload_note_fires_only_when_the_prefix_is_missing(): void
    {
        [, $code] = $this->cli(['make:feature', 'Billing']);
        $this->assertSame(0, $code);
        file_put_contents($this->cliApp . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/src/"}}}');
        [$out, $code] = $this->cli(['make:feature', 'Reports']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Note:', $out);
        $this->assertStringContainsString('composer dump-autoload', $out);
        file_put_contents($this->cliApp . '/composer.json', '{"autoload":{"psr-4":{"App\\\\Features\\\\":"app/Features/","App\\\\":"app/src/"}}}');
        [$out, $code] = $this->cli(['make:feature', 'Alerts']);
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('Note:', $out, 'with the prefix mapped, no advice is owed');
    }

    public function test_make_feature_refuses_when_feature_routing_is_off(): void
    {
        $configPath = $this->cliApp . '/config.php';
        $config = (string) file_get_contents($configPath);
        $patched = str_replace("'trusted_proxy'", "'feature_namespace' => null,\n    'trusted_proxy'", $config);
        $this->assertNotSame($config, $patched, 'skeleton/config.php changed shape; update the injection');
        file_put_contents($configPath, $patched);
        [$out, $code] = $this->cli(['make:feature', 'Billing']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('feature_namespace', $out);
        $this->assertFileDoesNotExist($this->cliApp . '/app/Features/Billing/BillingController.php');
    }

    public function test_make_feature_refuses_the_reserved_admin_name(): void
    {
        [$out, $code] = $this->cli(['make:feature', 'admin']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('reserved', $out);
        $this->assertStringContainsString('Admin', $out);
    }

    public function test_make_controller_emits_the_layered_shape_lints_and_names_the_route(): void
    {
        [$out, $code] = $this->cli(['make:controller', 'Invoices']);
        $this->assertSame(0, $code, $out);
        $controller = $this->cliApp . '/app/src/Controllers/InvoicesController.php';
        $view = $this->cliApp . '/app/views/invoices/index.php';
        $this->assertFileExists($controller);
        $this->assertFileExists($view);
        $this->assertStringContainsString('Created: app/src/Controllers/InvoicesController.php', $out);
        $this->assertStringContainsString('Route: /invoices (InvoicesController::index)', $out);
        $this->assertLint($controller);
        $this->assertStringContainsString("render('invoices/index'", (string) file_get_contents($controller));
        $this->assertStringContainsString('namespace App\Controllers;', (string) file_get_contents($controller));
    }

    public function test_make_controller_honors_the_composer_psr4_mapping(): void
    {
        file_put_contents($this->cliApp . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/code/"}}}');
        [$out, $code] = $this->cli(['make:controller', 'Invoices']);
        $this->assertSame(0, $code, $out);
        $this->assertFileExists($this->cliApp . '/app/code/Controllers/InvoicesController.php');
        $this->assertFileExists($this->cliApp . '/app/views/invoices/index.php');
    }

    public function test_make_controller_refuses_to_shadow_a_feature(): void
    {
        $this->cli(['make:feature', 'Billing']);
        [$out, $code] = $this->cli(['make:controller', 'Billing']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('feature folder', $out);
        $this->assertFileDoesNotExist($this->cliApp . '/app/src/Controllers/BillingController.php');
    }

    public function test_make_migration_numbers_after_the_app_ledger_and_applies(): void
    {
        [$out, $code] = $this->cli(['make:migration', 'create_plans']);
        $this->assertSame(0, $code, $out);
        $path = $this->cliApp . '/app/migrations/009_create_plans.php';
        $this->assertFileExists($path, 'the skeleton ships 001..008, the next number is 009');
        $this->assertStringContainsString('Created: app/migrations/009_create_plans.php', $out);
        $this->assertLint($path);
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('009_create_plans', $out);
    }

    public function test_make_migration_in_a_feature_numbers_across_both_directories(): void
    {
        $this->cli(['make:feature', 'Billing']);
        file_put_contents($this->cliApp . '/app/Features/Billing/migrations/008_billing.php',
            '<?php return new class extends Kip\Migrations\Migration { public function up(Kip\Database $db): void {} public function down(Kip\Database $db): void {} };');
        [$out, $code] = $this->cli(['make:migration', 'create_plans', '--feature=Billing']);
        $this->assertSame(0, $code, $out);
        $this->assertFileExists($this->cliApp . '/app/Features/Billing/migrations/009_create_plans.php', '009: max over app (008) and feature (008) ledgers');
        $this->assertFileDoesNotExist($this->cliApp . '/app/migrations/009_create_plans.php');
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('008_billing, 008_create_jobs, 009_create_plans', $out, 'one global order');
    }

    public function test_make_migration_targets_the_feature_of_the_working_directory(): void
    {
        $this->cli(['make:feature', 'Billing']);
        $cmd = 'cd ' . escapeshellarg($this->cliApp . '/app/Features/Billing') . ' && ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->cliApp . '/bin/kip') . ' make:migration create_plans 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        $this->assertFileExists($this->cliApp . '/app/Features/Billing/migrations/009_create_plans.php');
        $this->assertFileDoesNotExist($this->cliApp . '/app/migrations/009_create_plans.php');
    }

    public function test_make_migration_refuses_a_feature_that_does_not_exist(): void
    {
        $before = $this->appSnapshot();
        [$out, $code] = $this->cli(['make:migration', 'create_plans', '--feature=Ghost']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('make:feature', $out);
        $this->assertStringContainsString('Ghost', $out);
        $this->assertSame($before, $this->appSnapshot(), 'nothing written');
    }

    public function test_make_migration_refuses_a_name_the_ledger_already_applied(): void
    {
        $this->cli(['migrate']);
        unlink($this->cliApp . '/app/migrations/008_create_jobs.php');
        [$out, $code] = $this->cli(['make:migration', 'create_jobs']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('already applied', $out);
        $this->assertFileDoesNotExist($this->cliApp . '/app/migrations/008_create_jobs.php', 'the deleted name must not be regenerated');
    }

    public function test_make_migration_label_rules(): void
    {
        [$out, $code] = $this->cli(['make:migration', 'Create Invoices']);
        $this->assertSame(0, $code, $out);
        $this->assertFileExists($this->cliApp . '/app/migrations/009_create_invoices.php');
        [, $code] = $this->cli(['make:migration', 'seed-data']);
        $this->assertSame(0, $code);
        $this->assertFileExists($this->cliApp . '/app/migrations/010_seed_data.php');
        [$out, $code] = $this->cli(['make:migration', 'Invoices!']);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('kip: ', $out);
        [$out, $code] = $this->cli(['make:migration']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Usage: kip make:migration', $out);
    }

    public function test_a_fresh_feature_migrations_dir_is_tolerated_by_migrate(): void
    {
        // .gitkeep is a dotfile: the Migrator skips it, so a freshly scaffolded
        // feature contributes nothing to migrate, and a second run is a no-op.
        $this->cli(['make:feature', 'Billing']);
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('007_index_login_attempts_by_time', $out); // the app ledger ran, and only it
        $this->assertStringNotContainsString('Billing', $out);
        [$out, $code] = $this->cli(['migrate']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Nothing to migrate.', $out);
    }

    public function test_generated_feature_renders_through_the_framework(): void
    {
        $this->cli(['make:feature', 'Newsletters']);
        [$status, $body] = $this->renderThroughFramework('/newsletters');
        $this->assertSame(200, $status, $body);
        $this->assertStringContainsString('<h1>Newsletters</h1>', $body);
        $this->assertStringContainsString('<main>', $body, 'the feature template wraps the app layout');
    }

    public function test_generated_controller_renders_through_the_framework(): void
    {
        $this->cli(['make:controller', 'Invoices']);
        [$status, $body] = $this->renderThroughFramework('/invoices');
        $this->assertSame(200, $status, $body);
        $this->assertStringContainsString('<h1>Invoices</h1>', $body);
        $this->assertStringContainsString('<main>', $body);
    }

    public function test_usage_errors_land_on_stderr(): void
    {
        $errFile = (string) tempnam(sys_get_temp_dir(), 'kip-make-err-');
        $outFile = (string) tempnam(sys_get_temp_dir(), 'kip-make-out-');
        try {
            $cmd = 'cd ' . escapeshellarg($this->cliApp) . ' && ' . escapeshellarg(PHP_BINARY) . ' ./bin/kip make:feature'
                . ' > ' . escapeshellarg($outFile) . ' 2> ' . escapeshellarg($errFile);
            exec($cmd, $lines, $code);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('Usage: kip make:feature <Name>', (string) file_get_contents($errFile));
            $this->assertSame('', (string) file_get_contents($outFile), 'usage errors never leak into stdout');
        } finally {
            @unlink($errFile);
            @unlink($outFile);
        }
    }
}
