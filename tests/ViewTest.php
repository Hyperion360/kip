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
}
