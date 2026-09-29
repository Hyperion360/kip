<?php // tests/ScaffoldTest.php
namespace Kip\Tests;
use Kip\Migrations\Migrator;
use Kip\Routing\Router;
use Kip\Scaffold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scaffold (guide ch. 3 + ch. 8): generators that EMIT, never edit. Every
 * collision is a named refusal before any byte is written, numbering spans
 * the whole migration ledger, and the emitted shapes round-trip through the
 * Router/View/Migrator rules the guide documents.
 */
final class ScaffoldTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-scaffold-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/app/views', 0777, true);
        mkdir($this->dir . '/app/src/Controllers', 0777, true);
        mkdir($this->dir . '/app/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $d) use (&$rm): void {
                foreach (scandir($d) ?: [] as $e) {
                    if ($e === '.' || $e === '..') continue;
                    $p = $d . '/' . $e;
                    if (is_dir($p) && !is_link($p)) $rm($p); else unlink($p);
                }
                rmdir($d);
            };
            $rm($this->dir);
        } finally { restore_error_handler(); }
    }

    /** @param list<string> $expected absolute paths: the created list AND every file under $root must be exactly these */
    private function assertExactFileSet(array $created, array $expected, string $root): void
    {
        sort($created);
        sort($expected);
        $this->assertSame($expected, $created, 'the returned created-list is the exact file set');
        $found = [];
        $walk = static function (string $d) use (&$walk, &$found): void {
            foreach (scandir($d) ?: [] as $e) {
                if ($e === '.' || $e === '..') continue;
                $p = $d . '/' . $e;
                if (is_dir($p) && !is_link($p)) $walk($p); else $found[] = $p;
            }
        };
        $walk($root);
        sort($found);
        $this->assertSame($expected, $found, 'no stray files under ' . $root);
    }

    public function test_feature_emits_the_guide_ch3_shape_with_working_code(): void
    {
        $created = Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features\\');
        $f = $this->dir . '/app/Features/Billing';
        $this->assertExactFileSet($created, [
            $f . '/BillingController.php',
            $f . '/views/index.php',
            $f . '/migrations/.gitkeep',
            $f . '/Tests/BillingControllerTest.php',
        ], $this->dir . '/app');

        $controller = (string) file_get_contents($f . '/BillingController.php');
        $this->assertSame(1, preg_match('/^namespace App\\\\Features\\\\Billing;/m', $controller));
        $this->assertSame(1, preg_match('/^final class BillingController$/m', $controller));
        $this->assertSame(1, preg_match("/render\('billing\/index', \['title' => 'Billing'\]\)/", $controller));
        $this->assertStringNotContainsString('TODO', $controller);

        $view = (string) file_get_contents($f . '/views/index.php');
        $this->assertStringContainsString("\$this->layout('layout');", $view);
        $this->assertStringContainsString('<h1><?= $this->e($title) ?></h1>', $view);

        $this->assertSame('', (string) file_get_contents($f . '/migrations/.gitkeep'));
        $this->assertSame(1, preg_match('/^namespace App\\\\Features\\\\Billing\\\\Tests;/m', (string) file_get_contents($f . '/Tests/BillingControllerTest.php')));
    }

    public function test_feature_kebab_round_trips_through_router_studly(): void
    {
        Scaffold::feature('billing-lists', $this->dir . '/app/Features', 'App\\Features\\');
        $controller = (string) file_get_contents($this->dir . '/app/Features/BillingLists/BillingListsController.php');
        $this->assertSame(1, preg_match("/render\('billing-lists\/index'/", $controller));
        // The emitted template name must resolve back to the emitted folder (View::render + Router::studly).
        $head = strstr('billing-lists/index', '/', true);
        $this->assertSame('BillingLists', Router::studly($head));
        $this->assertSame('/billing-lists', Scaffold::routeFor('billing-lists'));
    }

    public function test_feature_refuses_an_existing_folder_exact_and_case_insensitive(): void
    {
        Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features\\');
        try {
            Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features\\');
            $this->fail('exact collision refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Billing', $e->getMessage());
        }
        try {
            Scaffold::feature('BILLING', $this->dir . '/app/Features', 'App\\Features\\');
            $this->fail('case-insensitive collision refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        $this->assertSame(['Billing'], array_values(array_diff(scandir($this->dir . '/app/Features') ?: [], ['.', '..'])));
    }

    public function test_feature_refuses_when_a_plain_controller_would_shadow_it(): void
    {
        file_put_contents($this->dir . '/app/src/Controllers/BillingController.php', '<?php namespace App\Controllers; final class BillingController {}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plain');
        Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features\\', ['plain_controller_dir' => $this->dir . '/app/src/Controllers']);
    }

    public function test_feature_refuses_when_an_app_root_template_would_shadow_it(): void
    {
        mkdir($this->dir . '/app/views/billing');
        $this->expectException(\RuntimeException::class);
        Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features\\', ['app_views_dir' => $this->dir . '/app/views']);
    }

    public function test_feature_refuses_a_reserved_studly_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Admin');
        Scaffold::feature('admin', $this->dir . '/app/Features', 'App\\Features\\', ['reserved_studly' => ['Admin']]);
    }

    public function test_controller_emits_the_layered_shape(): void
    {
        $created = Scaffold::controller('Invoices', 'App\\Controllers\\', $this->dir . '/app/src/Controllers', $this->dir . '/app/views');
        $this->assertExactFileSet($created, [
            $this->dir . '/app/src/Controllers/InvoicesController.php',
            $this->dir . '/app/views/invoices/index.php',
        ], $this->dir . '/app');
        $controller = (string) file_get_contents($this->dir . '/app/src/Controllers/InvoicesController.php');
        $this->assertSame(1, preg_match('/^namespace App\\\\Controllers;$/m', $controller));
        $this->assertSame(1, preg_match("/render\('invoices\/index', \['title' => 'Invoices'\]\)/", $controller));
        $this->assertSame(1, preg_match('/^<\?php \/\/ app\/src\/Controllers\/InvoicesController\.php$/m', $controller));
    }

    public function test_controller_refuses_collisions_including_case_insensitive(): void
    {
        Scaffold::controller('Invoices', 'App\\Controllers\\', $this->dir . '/app/src/Controllers', $this->dir . '/app/views');
        try {
            Scaffold::controller('INVOICES', 'App\\Controllers\\', $this->dir . '/app/src/Controllers', $this->dir . '/app/views');
            $this->fail('case-insensitive controller collision refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        mkdir($this->dir . '/app/views/reports');
        $this->expectException(\RuntimeException::class);
        Scaffold::controller('Reports', 'App\\Controllers\\', $this->dir . '/app/src/Controllers', $this->dir . '/app/views');
    }

    public function test_controller_refuses_when_it_would_shadow_a_feature(): void
    {
        Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features\\');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('feature');
        Scaffold::controller('Billing', 'App\\Controllers\\', $this->dir . '/app/src/Controllers', $this->dir . '/app/views', $this->dir . '/app/Features');
    }

    /** @return list<array{0: string}> */
    public static function badNames(): array
    {
        return [[''], ['1x'], ['billing-'], ['-billing'], ['bi--lling'], ['bil ling'], ['Bi.lling'], ['../etc'], ["bill\ting"], ['billing/x']];
    }

    #[DataProvider('badNames')]
    public function test_names_outside_the_accepted_shape_are_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scaffold::feature($name, $this->dir . '/app/Features', 'App\\Features\\');
    }

    public function test_a_namespace_without_a_trailing_backslash_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('namespace');
        Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Features');
    }

    public function test_migration_numbers_across_the_whole_ledger(): void
    {
        file_put_contents($this->dir . '/app/migrations/001_users.php', '<?php return new class extends Kip\Migrations\Migration {};');
        file_put_contents($this->dir . '/app/migrations/007_last.php', '<?php return new class extends Kip\Migrations\Migration {};');
        mkdir($this->dir . '/app/Features/Billing/migrations', 0777, true);
        file_put_contents($this->dir . '/app/Features/Billing/migrations/008_billing.php', '<?php return new class extends Kip\Migrations\Migration {};');

        $inFeature = Scaffold::migration('create_plans', [
            $this->dir . '/app/migrations',
            $this->dir . '/app/Features/Billing/migrations',
        ], $this->dir . '/app/Features/Billing/migrations');
        $this->assertSame($this->dir . '/app/Features/Billing/migrations/009_create_plans.php', $inFeature);

        $inApp = Scaffold::migration('create_plans', [
            $this->dir . '/app/migrations',
            $this->dir . '/app/Features/Billing/migrations',
        ], $this->dir . '/app/migrations');
        $this->assertSame($this->dir . '/app/migrations/010_create_plans.php', $inApp);
        $this->assertStringContainsString('app/migrations/010_create_plans.php', (string) file_get_contents($inApp));
    }

    public function test_migration_starts_at_001_and_creates_the_target_dir(): void
    {
        $path = Scaffold::migration('create_users', [$this->dir . '/app/migrations', $this->dir . '/app/Features/New/migrations'], $this->dir . '/app/Features/New/migrations');
        $this->assertSame($this->dir . '/app/Features/New/migrations/001_create_users.php', $path);
        $this->assertFileExists($path);
    }

    public function test_migration_label_normalization_is_exact(): void
    {
        $this->assertSame($this->dir . '/app/migrations/001_create_invoices.php', Scaffold::migration('Create Invoices', [$this->dir . '/app/migrations'], $this->dir . '/app/migrations'));
        $this->assertSame($this->dir . '/app/migrations/002_seed_data.php', Scaffold::migration('seed-data', [$this->dir . '/app/migrations'], $this->dir . '/app/migrations'));
        foreach (['Invoices!', '', 'create!!invoices'] as $bad) {
            try {
                Scaffold::migration($bad, [$this->dir . '/app/migrations'], $this->dir . '/app/migrations');
                $this->fail("label {$bad} refused");
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
    }

    public function test_migration_refuses_a_name_already_in_the_applied_ledger(): void
    {
        try {
            // 001_users was applied and its file deleted: regenerating that exact
            // name would be silently skipped by the next migrate.
            Scaffold::migration('users', [$this->dir . '/app/migrations'], $this->dir . '/app/migrations', ['001_users']);
            $this->fail('ledger reuse refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already applied', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir . '/app/migrations/001_users.php');
    }

    public function test_migration_refuses_when_the_next_number_would_sort_early(): void
    {
        file_put_contents($this->dir . '/app/migrations/8_old.php', '<?php return new class extends Kip\Migrations\Migration {};');
        try {
            // Next would be 009_x, but '009_x.php' sorts BEFORE '8_old.php' in the ledger's global order.
            Scaffold::migration('anything', [$this->dir . '/app/migrations'], $this->dir . '/app/migrations');
            $this->fail('mis-sorting number refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('8_old.php', $e->getMessage());
        }
    }

    public function test_migration_refuses_a_target_outside_the_ledger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scaffold::migration('x', [$this->dir . '/app/migrations'], $this->dir . '/elsewhere');
    }

    public function test_generated_migration_applies_and_rolls_back(): void
    {
        $path = Scaffold::migration('create_notes', [$this->dir . '/app/migrations'], $this->dir . '/app/migrations');
        $db = new \Kip\Database('sqlite::memory:');
        $ran = (new Migrator($db, $this->dir . '/app/migrations'))->migrate();
        $this->assertSame(['001_create_notes'], $ran);
        $this->assertSame(['001_create_notes'], (new Migrator($db, $this->dir . '/app/migrations'))->rollback());
    }

    public function test_psr4_resolution_and_the_autoload_advisory(): void
    {
        $composer = $this->dir . '/composer.json';
        file_put_contents($composer, '{"autoload":{"psr-4":{"App\\\\Features\\\\":"app/Features/","App\\\\":"app/src/"}}}');
        $paths = Scaffold::autoloadPaths($composer, 'App\\Features\\Billing\\BillingController');
        $this->assertSame([$this->dir . '/app/Features/Billing/BillingController.php'], $paths);
        $this->assertSame([$this->dir . '/app/src/Controllers/HomeController.php'], Scaffold::autoloadPaths($composer, 'App\\Controllers\\HomeController'));

        file_put_contents($composer, '{"autoload":{"psr-4":{"App\\\\":"app/src/"}}}');
        $this->assertSame([$this->dir . '/app/src/Features/Billing/BillingController.php'], Scaffold::autoloadPaths($composer, 'App\\Features\\Billing\\BillingController'));

        $this->assertSame([], Scaffold::autoloadPaths(null, 'App\\Controllers\\HomeController'));
        $this->assertSame([], Scaffold::autoloadPaths($this->dir . '/no-such-composer.json', 'App\\Controllers\\HomeController'));
    }

    public function test_route_for_home_is_the_root_url(): void
    {
        $this->assertSame('/', Scaffold::routeFor('Home'));
        $this->assertSame('/', Scaffold::routeFor('home'));
        $this->assertSame('/billing-lists', Scaffold::routeFor('BillingLists'));
    }

    public function test_a_failed_multi_file_emission_cleans_up_after_itself(): void
    {
        // The views dir is read-only, so the second artifact (the view) fails
        // AFTER the controller was written; the controller must be unlinked.
        chmod($this->dir . '/app/views', 0555);
        try {
            try {
                Scaffold::controller('Invoices', 'App\\Controllers\\', $this->dir . '/app/src/Controllers', $this->dir . '/app/views');
                $this->fail('emission into a read-only views dir failed');
            } catch (\RuntimeException) {
            }
            $this->assertFileDoesNotExist($this->dir . '/app/src/Controllers/InvoicesController.php', 'no partial emission may survive a failed run');
        } finally {
            chmod($this->dir . '/app/views', 0777);
        }
    }

    public function test_overlong_name_components_are_refused_before_writing(): void
    {
        $name = str_repeat('A', 250); // studly -> controller filename exceeds a 255-byte filesystem component
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('255');
        Scaffold::feature($name, $this->dir . '/app/Features', 'App\\Features\\');
    }

    public function test_a_custom_feature_namespace_is_used_verbatim(): void
    {
        Scaffold::feature('Billing', $this->dir . '/app/Features', 'App\\Modules\\');
        $controller = (string) file_get_contents($this->dir . '/app/Features/Billing/BillingController.php');
        $this->assertSame(1, preg_match('/^namespace App\\\\Modules\\\\Billing;/m', $controller));
    }
}
