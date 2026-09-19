<?php // tests/BackupTest.php
namespace Kip\Tests;
use Kip\Backup;
use Kip\Database;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/kip-bk-' . bin2hex(random_bytes(4));
        mkdir($this->work);
        $db = new Database('sqlite:' . $this->work . '/data.sqlite');
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $db->query("INSERT INTO t (v) VALUES ('keepme')");
    }

    protected function tearDown(): void
    {
        // VACUUM INTO copies opened as Database leave WAL sidecars (-wal/-shm) and
        // macOS can briefly delay their removal, clean recursively, ignoring noise.
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            $rm($this->work);
        } finally {
            restore_error_handler();
        }
    }

    public function test_backup_produces_zip_with_restorable_copy(): void
    {
        $backup = new Backup(['data' => 'sqlite:' . $this->work . '/data.sqlite'], $this->work . '/backups');
        $path = $backup->run('20260816-120000');
        $this->assertStringEndsWith('kip-backup-20260816-120000.zip', $path);
        $this->assertFileExists($path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->extractTo($this->work . '/backups/x');
        $zip->close();
        $restored = new Database('sqlite:' . $this->work . '/backups/x/data.sqlite');
        $this->assertSame('keepme', $restored->one('SELECT v FROM t')['v']);
        // teardown removes the extract dir recursively (WAL sidecars included)
    }

    public function test_non_sqlite_dsn_is_skipped(): void
    {
        $backup = new Backup(
            ['data' => 'sqlite:' . $this->work . '/data.sqlite', 'other' => 'mysql:host=x'],
            $this->work . '/backups'
        );
        $path = $backup->run('20260816-120001');
        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
    }

    public function test_no_sqlite_sources_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Backup(['other' => 'mysql:host=x'], $this->work . '/backups'))->run('20260816-120002');
    }
}
