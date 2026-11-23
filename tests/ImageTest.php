<?php // tests/ImageTest.php
declare(strict_types=1);
namespace Kip\Tests;

use Kip\{Image, ImageException};
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** A 4x2 JPEG, left half red and right half blue, tagged with an EXIF orientation. */
    private function jpegWithOrientation(int $orientation): string
    {
        $im = imagecreatetruecolor(4, 2);
        imagefilledrectangle($im, 0, 0, 1, 1, imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, 2, 0, 3, 1, imagecolorallocate($im, 0, 0, 255));
        $f = $this->dir . '/o' . $orientation . '.jpg';
        imagejpeg($im, $f, 100);
        $tiff = "MM\x00*\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
        $bytes = (string) file_get_contents($f);
        file_put_contents($f, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));
        return $f;
    }

    /** @return array<string,array{int,int,int}> orientation, then the upright width and height of the 4x2 source */
    public static function orientations(): array
    {
        return ['1 normal' => [1, 4, 2], '3 rotated 180' => [3, 4, 2], '6 rotated 90 cw' => [6, 2, 4], '8 rotated 90 ccw' => [8, 2, 4]];
    }

    #[DataProvider('orientations')]
    public function test_it_applies_jpeg_exif_orientation(int $orientation, int $w, int $h): void
    {
        $this->needGd();
        if (!function_exists('exif_read_data')) $this->markTestSkipped('ext-exif is not loaded');
        $r = Image::toWebp($this->jpegWithOrientation($orientation), $this->dir . '/r.webp');
        $this->assertSame([$w, $h], [$r['width'], $r['height']]);
    }

    public function test_it_undoes_mirrored_orientations(): void
    {
        $this->needGd();
        if (!function_exists('exif_read_data')) $this->markTestSkipped('ext-exif is not loaded');
        // Orientation 2 is a horizontal mirror: the stored red-left image must come out red-right.
        Image::toWebp($this->jpegWithOrientation(2), $this->dir . '/m.webp');
        $im = imagecreatefromwebp($this->dir . '/m.webp');
        $this->assertGreaterThan(200, (imagecolorat($im, 3, 0) >> 16) & 0xFF);
        // Orientation 5 is a transpose: the image turns upright with the red half on top.
        $r = Image::toWebp($this->jpegWithOrientation(5), $this->dir . '/t.webp');
        $this->assertSame([2, 4], [$r['width'], $r['height']]);
        $im = imagecreatefromwebp($this->dir . '/t.webp');
        $this->assertGreaterThan(200, (imagecolorat($im, 0, 0) >> 16) & 0xFF);
        $this->assertLessThan(60, (imagecolorat($im, 0, 3) >> 16) & 0xFF);
    }

    public function test_it_decodes_jpeg_gif_and_webp_input(): void
    {
        $this->needGd();
        $im = imagecreatetruecolor(30, 20);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        imagejpeg($im, $this->dir . '/a.jpg');
        imagegif($im, $this->dir . '/a.gif');
        imagewebp($im, $this->dir . '/a.webp');
        foreach (['a.jpg', 'a.gif', 'a.webp'] as $name) {
            $r = Image::toWebp($this->dir . '/' . $name, $this->dir . '/out-' . $name);
            $this->assertSame([30, 20], [$r['width'], $r['height']], $name);
        }
    }

    public function test_a_failed_write_leaves_an_existing_destination_untouched(): void
    {
        $this->needGd();
        $dest = $this->dir . '/keep.webp';
        file_put_contents($dest, 'previous bytes');
        $src = $this->png(20, 20);
        chmod($this->dir, 0555);
        try {
            if (is_writable($this->dir)) $this->markTestSkipped('directory permissions are not enforced here');
            $this->expectException(ImageException::class);
            Image::toWebp($src, $dest);
        } finally {
            chmod($this->dir, 0755);
            $this->assertSame('previous bytes', file_get_contents($dest));
        }
    }

    public function test_it_does_not_call_a_single_frame_gif_or_a_still_image_animated(): void
    {
        $one = "GIF89a\x01\x00\x01\x00\x00\x00\x00" . "\x21\xF9\x04\x00\x00\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00" . "\x3B";
        file_put_contents($this->dir . '/one.gif', $one);
        $this->assertFalse(Image::isAnimated($this->dir . '/one.gif'));
        $this->assertFalse(Image::isAnimated($this->png(5, 5)));
    }

    public function test_it_finds_the_second_frame_marker_across_a_chunk_boundary(): void
    {
        $gce = "\x21\xF9\x04";
        // Straddle the 64 KB read boundary: marker one early, marker two split between chunk one and chunk two.
        $bytes = "GIF89a" . $gce . str_repeat("\x00", 65536 - 6 - 3 - 1) . "\x21\xF9" . "\x04" . str_repeat("\x00", 10);
        file_put_contents($this->dir . '/split.gif', $bytes);
        $this->assertTrue(Image::isAnimated($this->dir . '/split.gif'));
    }

    public function test_it_refuses_stream_wrappers_and_missing_paths_as_a_source(): void
    {
        $this->needGd();
        foreach (['data://image/png;base64,iVBORw0KGgo=', 'phar://' . $this->dir . '/x.phar/a.png', $this->dir . '/missing.png'] as $src) {
            try {
                Image::toWebp($src, $this->dir . '/w.webp');
                $this->fail("expected ImageException for $src");
            } catch (ImageException) {
                $this->assertFileDoesNotExist($this->dir . '/w.webp');
            }
        }
        $this->assertFalse(Image::isAnimated('data://text/plain;base64,R0lGODlh'));
    }

    public function test_it_reports_a_clear_error_when_gd_is_missing(): void
    {
        if (Image::available()) $this->markTestSkipped('GD is loaded; the fallback path cannot be reached');
        $this->expectException(ImageException::class);
        Image::toWebp($this->dir . '/none.png', $this->dir . '/n.webp');
    }
}
