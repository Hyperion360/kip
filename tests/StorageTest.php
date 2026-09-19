<?php // tests/StorageTest.php
namespace Kip\Tests;
use Kip\Storage;
use Kip\UploadException;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private string $dir;
    private Storage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kip-up-' . bin2hex(random_bytes(4));
        // rename() test seam: move_uploaded_file() only accepts real SAPI uploads
        $this->storage = new Storage($this->dir, maxBytes: 1024, mover: static fn(string $t, string $d): bool => rename($t, $d));
    }

    protected function tearDown(): void
    {
        // Rejecting paths throw before the mover runs, clean the temp files too.
        foreach ($this->tmps as $t) @unlink($t);
        if (is_dir($this->dir)) { array_map('unlink', glob($this->dir . '/*')); rmdir($this->dir); }
    }

    /** @var list<string> tempnam() files awaiting cleanup */
    private array $tmps = [];

    /** @return array shaped like one $_FILES entry */
    private function fakeUpload(string $name, string $bytes, ?int $reportedSize = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kipu');
        file_put_contents($tmp, $bytes);
        $this->tmps[] = $tmp;
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => $reportedSize ?? strlen($bytes), 'error' => UPLOAD_ERR_OK, 'type' => ''];
    }

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89"; // minimal PNG header

    public function test_valid_png_is_stored_under_random_name(): void
    {
        $path = $this->storage->put($this->fakeUpload('photo.png', self::PNG));
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.png$#', $path);
        $this->assertFileExists($this->dir . '/' . basename($path));
    }

    public function test_php_extension_rejected(): void
    {
        $this->expectException(UploadException::class);
        $this->storage->put($this->fakeUpload('shell.php', '<?php evil();'));
    }

    public function test_svg_rejected_by_default(): void
    {
        $this->expectException(UploadException::class); // stored-XSS vector
        $this->storage->put($this->fakeUpload('img.svg', '<svg onload="alert(1)"/>'));
    }

    public function test_mime_mismatch_rejected(): void
    {
        $this->expectException(UploadException::class); // PHP source wearing a .png name
        $this->storage->put($this->fakeUpload('fake.png', '<?php evil();'));
    }

    public function test_oversize_rejected(): void
    {
        $this->expectException(UploadException::class);
        $this->storage->put($this->fakeUpload('big.png', self::PNG . str_repeat('a', 2048)));
    }

    public function test_upload_error_rejected(): void
    {
        $this->expectException(UploadException::class);
        $this->storage->put(['name' => 'x.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_PARTIAL]);
    }

    public function test_traversal_name_is_neutralized_by_random_naming(): void
    {
        $path = $this->storage->put($this->fakeUpload('../../etc/passwd.png', self::PNG));
        $this->assertStringNotContainsString('..', $path);
        $this->assertFileExists($this->dir . '/' . basename($path)); // landed INSIDE the uploads dir
    }

    public function test_lying_reported_size_is_rejected_by_disk_check(): void // size key is metadata, disk is truth
    {
        $this->expectException(UploadException::class);
        $this->storage->put($this->fakeUpload('big.png', self::PNG . str_repeat('a', 2048), reportedSize: 10));
    }

    public function test_custom_extension_whitelist_is_honoured(): void
    {
        $s = new Storage($this->dir, maxBytes: 1024, allowedExt: ['csv'], mover: static fn(string $t, string $d): bool => rename($t, $d));
        $path = $s->put($this->fakeUpload('data.csv', "a,b\n1,2\n"));
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.csv$#', $path);
    }

    public function test_extension_outside_default_whitelist_is_rejected(): void // default config, unknown type
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('not allowed');
        $this->storage->put($this->fakeUpload('payload.exe', 'MZ...'));
    }

    public function test_extension_without_a_content_check_is_rejected(): void // no silently-unverified types
    {
        $s = new Storage($this->dir, maxBytes: 1024, allowedExt: ['xyz'], mover: static fn(string $t, string $d): bool => rename($t, $d));
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('No content check is defined');
        $s->put($this->fakeUpload('mystery.xyz', 'whatever'));
    }

    public function test_denylist_beats_custom_extension_list(): void // config mistakes cannot re-enable dangerous types
    {
        $s = new Storage($this->dir, maxBytes: 1024, allowedExt: ['svg', 'html'], mover: static fn(string $t, string $d): bool => rename($t, $d));
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('never allowed');
        $s->put($this->fakeUpload('img.svg', '<svg onload="alert(1)"/>'));
    }

    public function test_script_family_extensions_are_denied_even_when_configured(): void // same-origin JS execution
    {
        $s = new Storage($this->dir, maxBytes: 1024, allowedExt: ['js', 'xml'], mover: static fn(string $t, string $d): bool => rename($t, $d));
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('never allowed');
        $s->put($this->fakeUpload('app.js', 'alert(1)'));
    }
}
