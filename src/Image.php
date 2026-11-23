<?php // src/Image.php
declare(strict_types=1);
namespace Kip;

/**
 * Raster re-encoding for untrusted uploads. Decode with GD, never trust the
 * claimed type, cap the pixel count BEFORE decoding (a small file can hold a
 * huge bitmap), resize, honor JPEG orientation, and write a fresh WebP, so
 * the stored bytes (and their metadata) are never the sender's bytes.
 * ext-gd is optional: callers check available() and fall back to storing the
 * original.
 */
final class Image
{
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    /** A GIF with more than one frame, or a WebP whose VP8X header sets the animation flag. GD would flatten these. */
    public static function isAnimated(string $file): bool
    {
        $head = (string) @file_get_contents($file, false, null, 0, 32);
        if (str_starts_with($head, 'GIF8')) {
            // More than one graphic control extension means more than one frame. A false positive only keeps the original.
            return self::countInFile($file, "\x21\xF9\x04", 2) >= 2;
        }
        if (strlen($head) >= 21 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            // An extended WebP ("VP8X") sets bit 1 of its flags byte (offset 20) when it is animated.
            return substr($head, 12, 4) === 'VP8X' && (ord($head[20]) & 0x02) !== 0;
        }
        return false;
    }

    /** Count $needle in a file read in 64 KB chunks (never the whole file), stopping at $stopAt matches. */
    private static function countInFile(string $file, string $needle, int $stopAt): int
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) return 0;
        $count = 0;
        $tail = '';
        while (!feof($fh) && $count < $stopAt) {
            $chunk = fread($fh, 65536);
            if ($chunk === false || $chunk === '') break;
            $buf = $tail . $chunk;
            $count += substr_count($buf, $needle);
            $tail = substr($buf, -(strlen($needle) - 1));
        }
        fclose($fh);
        return $count;
    }

    /**
     * @return array{width:int,height:int,bytes:int}
     * @throws ImageException when the file is not a decodable image or is over $maxPixels
     */
    public static function toWebp(string $src, string $dest, int $maxEdge = 2000, int $quality = 82, int $maxPixels = 16_000_000): array
    {
        if (!self::available()) throw new ImageException('GD with WebP support is not available');
        $info = @getimagesize($src);
        if ($info === false) throw new ImageException('not a readable image');
        [$w, $h, $type] = $info;
        if ($w < 1 || $h < 1 || $w * $h > $maxPixels) throw new ImageException("image is {$w}x{$h}, over the pixel cap");
        $im = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_GIF => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default => false,
        };
        if ($im === false) throw new ImageException('image could not be decoded');
        imagepalettetotruecolor($im);
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            $im = self::orient($im, (int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1));
            [$w, $h] = [imagesx($im), imagesy($im)];
        }
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = @imagecreatetruecolor($nw, $nh);
        if ($out === false) throw new ImageException('image canvas could not be allocated');
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        // Write beside the target and rename, so a failed write never destroys a file already at $dest.
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!@imagewebp($out, $tmp, $quality) || !@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new ImageException('WebP could not be written');
        }
        clearstatcache(true, $dest);
        return ['width' => $nw, 'height' => $nh, 'bytes' => (int) filesize($dest)];
    }

    /** Apply an EXIF orientation (2 to 8) so the pixels display upright; 1 or unknown leaves the image alone. */
    private static function orient(\GdImage $im, int $orientation): \GdImage
    {
        $flip = [2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_VERTICAL, 5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_HORIZONTAL][$orientation] ?? null;
        if ($flip !== null) imageflip($im, $flip);
        $turn = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
        if ($turn === 0) return $im;
        $rot = imagerotate($im, $turn, 0);
        return $rot === false ? $im : $rot;
    }
}
