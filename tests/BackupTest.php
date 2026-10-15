<?php // tests/BackupTest.php
namespace Kip\Tests;
use Kip\Backup;
use Kip\Database;
use Kip\S3;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    use S3StubServer;

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

    public function test_target_path_containing_a_quote_character_snapshots_fine(): void
    {
        // The VACUUM INTO target is a bound parameter; a quote in the path is the
        // input class a hand-rolled escaping rule existed for. It must be a
        // non-event either way.
        $dir = $this->work . "/ba'ckups";
        $path = (new Backup(['data' => 'sqlite:' . $this->work . '/data.sqlite'], $dir))->run('20260816-120003');
        $this->assertFileExists($path);
    }

    /**
     * Off-site integration: a Backup with an S3 client pushes the archive as
     * part of the run, the local copy stays (prune never touches a fresh
     * archive), and the bucket holds the exact same bytes. The stub server
     * verifies the upload's signature with its own SigV4 derivation.
     */
    public function test_off_site_backup_pushes_the_archive_and_keeps_the_local_copy(): void
    {
        $config = $this->startS3Stub(8097);
        try {
            $s3 = new S3($config);
            $backup = new Backup(['data' => 'sqlite:' . $this->work . '/data.sqlite'], $this->work . '/backups', 14, $s3);
            $path = $backup->run('20260929-120000');

            $this->assertFileExists($path, 'the local archive must survive its own upload');
            $this->assertTrue($s3->head('kip-backup-20260929-120000.zip'));
            $uploaded = $config['store'] . '/kip-backup-20260929-120000.zip';
            $this->assertFileExists($uploaded);
            $this->assertSame((string) file_get_contents($path), (string) file_get_contents($uploaded));
        } finally {
            $this->stopS3Stub();
        }
    }

    public function test_backup_dir_and_artifacts_are_owner_private(): void
    {
        $backup = new Backup(['data' => 'sqlite:' . $this->work . '/data.sqlite'], $this->work . '/backups');
        $path = $backup->run('20261015-120000');
        $this->assertSame(0700, fileperms($this->work . '/backups') & 0777);
        if (class_exists(\ZipArchive::class)) {
            $this->assertSame(0600, fileperms($path) & 0777);
        } else {
            // No-zip hosts return the directory of loose copies, itself an artifact holder.
            $this->assertSame($this->work . '/backups', $path);
            $this->assertSame(0700, fileperms($path) & 0777);
        }
    }

    public function test_no_zip_loose_copies_are_owner_private(): void
    {
        if (class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip present, the loose-copy fallback is unreachable');
        }
        $dir = $this->work . '/backups';
        (new Backup(['data' => 'sqlite:' . $this->work . '/data.sqlite'], $dir))->run('20261015-120001');
        $copy = $dir . '/data-20261015-120001.sqlite';
        $this->assertFileExists($copy); // no vacuous glob pass
        $this->assertSame(0600, fileperms($copy) & 0777);
    }

    public function test_pre_existing_loose_dir_and_old_artifacts_are_tightened(): void
    {
        $dir = $this->work . '/backups';
        mkdir($dir, 0755);
        chmod($dir, 0755); // mkdir filters through umask; pin the pre-state
        $oldZip = "{$dir}/kip-backup-20260901-120000.zip";
        file_put_contents($oldZip, 'stale archive');
        chmod($oldZip, 0644);
        $oldCopy = "{$dir}/data-20260901-120000.sqlite";
        file_put_contents($oldCopy, 'stale copy');
        chmod($oldCopy, 0644);
        (new Backup(['data' => 'sqlite:' . $this->work . '/data.sqlite'], $dir))->run('20261015-120002');
        $this->assertSame(0700, fileperms($dir) & 0777);
        $this->assertSame(0600, fileperms($oldZip) & 0777);
        $this->assertSame(0600, fileperms($oldCopy) & 0777);
    }
}
