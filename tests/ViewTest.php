<?php // tests/ViewTest.php
namespace Kip\Tests;
use Kip\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-views-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/hello.php', '<p><?= $this->e($name) ?></p>');
        file_put_contents($this->dir . '/layout.php', '<main><?= $content ?></main>');
        file_put_contents($this->dir . '/page.php', '<?php $this->layout("layout"); ?>hi');
    }

    public function test_renders_template_with_data(): void
    {
        $v = new View($this->dir);
        $this->assertSame('<p>World</p>', $v->render('hello', ['name' => 'World']));
    }

    public function test_e_escapes_html(): void // threat model: XSS
    {
        $v = new View($this->dir);
        $this->assertSame('<p>&lt;script&gt;</p>', $v->render('hello', ['name' => '<script>']));
    }

    public function test_layout_wraps_content(): void
    {
        $v = new View($this->dir);
        $this->assertSame('<main>hi</main>', $v->render('page'));
    }

    public function test_missing_template_throws_friendly_exception(): void // review 4A: error pages are the product
    {
        $v = new View($this->dir);
        $this->expectException(\Kip\TemplateNotFoundException::class);
        $this->expectExceptionMessage('nope');
        $v->render('nope');
    }

    public function test_missing_layout_throws_friendly_exception(): void // review T5-fix
    {
        file_put_contents($this->dir . '/badlayout.php', '<?php $this->layout("ghost"); ?>x');
        $v = new View($this->dir);
        $this->expectException(\Kip\TemplateNotFoundException::class);
        $this->expectExceptionMessage('ghost');
        $v->render('badlayout');
    }

    public function test_wrap_data_key_cannot_hijack_layout(): void // review T5-fix: EXTR_SKIP guard
    {
        $v = new View($this->dir);
        $this->assertSame('<main>hi</main>', $v->render('page', ['wrap' => '../evil']));
    }

    public function test_nested_render_preserves_outer_layout(): void // review T5-fix: reentrancy
    {
        file_put_contents($this->dir . '/inner.php', 'part');
        file_put_contents($this->dir . '/outer.php', '<?php $this->layout("layout"); echo $this->render("inner"); ?>!');
        $v = new View($this->dir);
        $this->assertSame('<main>part!</main>', $v->render('outer'));
    }

    public function test_template_and_data_keys_reach_the_template(): void // v0.1.1 T3: extract() collision guard
    {
        file_put_contents($this->dir . '/probe.php', '<?= $this->e($template) ?>|<?= $this->e($data) ?>');
        $v = new View($this->dir);
        $this->assertSame('T-VAL|D-VAL', $v->render('probe', ['template' => 'T-VAL', 'data' => 'D-VAL']));
    }

    public function test_content_data_key_cannot_displace_layout_body(): void // T3 fix-round regression guard
    {
        $v = new View($this->dir);
        $this->assertSame('<main>hi</main>', $v->render('page', ['content' => 'EVIL']));
    }

    public function test_throwing_template_leaks_no_output_buffer(): void // finally-block cleanup
    {
        file_put_contents($this->dir . '/boom.php', '<?php echo "partial"; throw new \RuntimeException("kaboom"); ?>');
        $v = new View($this->dir);
        $level = ob_get_level();
        try {
            $v->render('boom');
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('kaboom', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level()); // the finally block closed the buffer View opened
    }

    /**
     * render() declares string while ob_get_clean() is string|false. A template
     * that manipulates the output buffer it was handed must still yield a
     * string.
     *
     * Verified under a caller-owned output buffer, which is what PHPUnit
     * supplies: a template that closes the buffer and re-opens one yields
     * 'recovered'. This is a regression guard; the analyzer in Step 4 is the
     * authority on the declared-type defect.
     */
    public function test_render_returns_a_string_when_a_template_manipulates_the_buffer(): void
    {
        $dir = sys_get_temp_dir() . '/kip-view-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/reopens.php', "<?php ob_end_clean(); ob_start(); echo 'recovered';");

        $view = new \Kip\View($dir);

        try {
            $reopened = $view->render('reopens');
            $this->assertIsString($reopened);
            $this->assertSame('recovered', $reopened);
        } finally {
            unlink($dir . '/reopens.php');
            rmdir($dir);
        }
    }

    /** @return array{0: string, 1: string} [app views dir, features dir], with a Billing feature template in place */
    private function featureDirs(): array
    {
        $base = sys_get_temp_dir() . '/kip-feat-' . bin2hex(random_bytes(6));
        $views = $base . '/views';
        $features = $base . '/Features';
        mkdir($views . '/billing', 0777, true);
        mkdir($features . '/Billing/views', 0777, true);
        file_put_contents($features . '/Billing/views/invoice.php', 'feature invoice');
        return [$views, $features];
    }

    public function test_feature_folder_template_resolves(): void
    {
        [$views, $features] = $this->featureDirs();
        $v = new View($views, $features);
        $this->assertSame('feature invoice', $v->render('billing/invoice'));
    }

    public function test_app_root_wins_when_the_template_exists_in_both_roots(): void
    {
        [$views, $features] = $this->featureDirs();
        file_put_contents($views . '/billing/invoice.php', 'app invoice');
        $v = new View($views, $features);
        $this->assertSame('app invoice', $v->render('billing/invoice'));
    }

    public function test_feature_folder_segment_is_studly_cased(): void
    {
        [$views, $features] = $this->featureDirs();
        mkdir($features . '/ReadingLists/views', 0777, true);
        file_put_contents($features . '/ReadingLists/views/index.php', 'reading lists');
        $v = new View($views, $features);
        $this->assertSame('reading lists', $v->render('reading-lists/index'));
    }

    public function test_missing_template_message_names_both_paths(): void
    {
        [$views, $features] = $this->featureDirs();
        $v = new View($views, $features);
        try {
            $v->render('billing/ghost');
            $this->fail('expected TemplateNotFoundException');
        } catch (\Kip\TemplateNotFoundException $e) {
            $this->assertStringContainsString("{$views}/billing/ghost.php", $e->getMessage());
            $this->assertStringContainsString("{$features}/Billing/views/ghost.php", $e->getMessage());
        }
    }

    public function test_empty_features_dir_keeps_the_single_root_error_byte_identical(): void
    {
        [$views] = $this->featureDirs();
        $v = new View($views);
        try {
            $v->render('billing/ghost');
            $this->fail('expected TemplateNotFoundException');
        } catch (\Kip\TemplateNotFoundException $e) {
            $this->assertSame(
                "Template \"billing/ghost\" not found in {$views} (looked for billing/ghost.php)",
                $e->getMessage()
            );
        }
    }

    public function test_template_name_without_a_separator_never_touches_the_features_root(): void
    {
        [$views, $features] = $this->featureDirs();
        file_put_contents($features . '/Billing/views/invoice.php', 'should not matter'); // feature dir exists
        $v = new View($views, $features);
        try {
            $v->render('ghost');
            $this->fail('expected TemplateNotFoundException');
        } catch (\Kip\TemplateNotFoundException $e) {
            $this->assertSame(
                "Template \"ghost\" not found in {$views} (looked for ghost.php)",
                $e->getMessage()
            );
        }
    }

    public function test_layouts_resolve_from_the_app_root_only(): void
    {
        [$views, $features] = $this->featureDirs();
        file_put_contents($views . '/layout.php', '<main><?= $content ?></main>');
        file_put_contents($features . '/Billing/views/page.php', '<?php $this->layout("layout"); ?>feature page');
        $v = new View($views, $features);
        $this->assertSame('<main>feature page</main>', $v->render('billing/page'));
    }
}
