<?php // tests/ImageTest.php
declare(strict_types=1);
namespace Kip\Tests;

use Kip\{Image, ImageException};
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-image-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }
    protected function tearDown(): void { array_map('unlink', glob($this->dir . '/*') ?: []); @rmdir($this->dir); }

    private function needGd(): void
    {
        if (!Image::available()) $this->markTestSkipped('ext-gd with WebP support is not loaded');
    }

    private function png(int $w, int $h, bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($alpha) { imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 127)); }
        else imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        $f = $this->dir . '/in-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $f);
        return $f;
    }

    public function test_it_writes_a_webp_and_caps_the_longest_edge(): void
    {
        $this->needGd();
        $out = $this->dir . '/out.webp';
        $r = Image::toWebp($this->png(3000, 1500), $out, maxEdge: 2000);
        $this->assertSame([2000, 1000], [$r['width'], $r['height']]);
        $this->assertSame('RIFF', substr((string) file_get_contents($out, false, null, 0, 4), 0, 4));
        $this->assertSame('WEBP', substr((string) file_get_contents($out, false, null, 8, 4), 0, 4));
    }

    public function test_it_never_enlarges_a_small_image(): void
    {
        $this->needGd();
        $r = Image::toWebp($this->png(40, 30), $this->dir . '/s.webp');
        $this->assertSame([40, 30], [$r['width'], $r['height']]);
    }

    public function test_it_keeps_png_transparency(): void
    {
        $this->needGd();
        $out = $this->dir . '/a.webp';
        Image::toWebp($this->png(20, 20, alpha: true), $out);
        $back = imagecreatefromwebp($out);
        $this->assertGreaterThan(0, (imagecolorat($back, 5, 5) >> 24) & 0x7F);
    }

    public function test_it_refuses_an_image_over_the_pixel_cap_before_decoding(): void
    {
        $this->needGd();
        $this->expectException(ImageException::class);
        Image::toWebp($this->png(200, 200), $this->dir . '/x.webp', maxPixels: 10_000);
    }

    public function test_it_refuses_bytes_that_are_not_an_image(): void
    {
        $this->needGd();
        $f = $this->dir . '/junk.png';
        file_put_contents($f, "\x89PNG\r\n\x1a\n" . random_bytes(64));
        try {
            Image::toWebp($f, $this->dir . '/j.webp');
            $this->fail('expected ImageException');
        } catch (ImageException) {
            $this->assertFileDoesNotExist($this->dir . '/j.webp');
        }
    }

    public function test_it_detects_an_animated_gif(): void
    {
        // Two image descriptors (0x2C) after the header and logical screen descriptor.
        $gif = "GIF89a\x01\x00\x01\x00\x00\x00\x00"
             . "\x21\xF9\x04\x00\x00\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00"
             . "\x21\xF9\x04\x00\x00\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00" . "\x3B";
        $f = $this->dir . '/anim.gif';
        file_put_contents($f, $gif);
        $this->assertTrue(Image::isAnimated($f));
        $this->assertFalse(Image::isAnimated($this->dir . '/missing.png'));
    }

    public function test_it_detects_an_animated_webp_flag(): void
    {
        $vp8x = 'RIFF' . pack('V', 22) . 'WEBP' . 'VP8X' . pack('V', 10) . "\x02" . str_repeat("\0", 9);
        $f = $this->dir . '/anim.webp';
        file_put_contents($f, $vp8x);
        $this->assertTrue(Image::isAnimated($f));
        file_put_contents($f, substr_replace($vp8x, "\x00", 20, 1));
        $this->assertFalse(Image::isAnimated($f));
    }

    public function test_it_reports_a_clear_error_when_gd_is_missing(): void
    {
        if (Image::available()) $this->markTestSkipped('GD is loaded; the fallback path cannot be reached');
        $this->expectException(ImageException::class);
        Image::toWebp($this->dir . '/none.png', $this->dir . '/n.webp');
    }
}
