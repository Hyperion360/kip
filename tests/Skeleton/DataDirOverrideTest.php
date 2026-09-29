<?php // tests/Skeleton/DataDirOverrideTest.php
namespace Kip\Tests\Skeleton;

use PHPUnit\Framework\TestCase;

/**
 * KIP_DATA_DIR in both bundled apps' config.php: the three SQLite databases
 * (data, logs, cache) repoint at one writable directory, which an embedded,
 * read-only artifact (kip build) needs and containers welcome. Everything
 * else, app_dir above all (code and views are read-only inside an artifact),
 * stays anchored to the app itself. With the variable unset the defaults are
 * byte-for-byte the old paths.
 */
final class DataDirOverrideTest extends TestCase
{
    public static function appConfigs(): array
    {
        $root = dirname(__DIR__, 2);
        return [
            'skeleton' => [$root . '/skeleton/config.php'],
            'blog' => [$root . '/examples/blog/config.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('appConfigs')]
    public function test_kip_data_dir_repoints_the_three_databases(string $configFile): void
    {
        putenv('KIP_DATA_DIR=/tmp/kip-data-override-probe');
        try {
            $config = require $configFile;
        } finally {
            putenv('KIP_DATA_DIR');
        }
        $this->assertSame('sqlite:/tmp/kip-data-override-probe/data.sqlite', $config['db']['dsn']);
        $this->assertSame('sqlite:/tmp/kip-data-override-probe/logs.sqlite', $config['log_db']['dsn']);
        $this->assertSame('sqlite:/tmp/kip-data-override-probe/cache.sqlite', $config['cache_db']['dsn']);
        $this->assertSame(dirname($configFile) . '/app', $config['app_dir'], 'code and views stay in the app');
        if (isset($config['mail'])) {
            $this->assertSame('/tmp/kip-data-override-probe/mail.log', $config['mail']['log_path'], 'writable app state follows the data dir');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('appConfigs')]
    public function test_without_the_override_the_default_paths_hold(string $configFile): void
    {
        putenv('KIP_DATA_DIR'); // unset, the documented default
        $config = require $configFile;
        $this->assertSame('sqlite:' . dirname($configFile) . '/app/data.sqlite', $config['db']['dsn']);
        $this->assertSame('sqlite:' . dirname($configFile) . '/app/logs.sqlite', $config['log_db']['dsn']);
        $this->assertSame('sqlite:' . dirname($configFile) . '/app/cache.sqlite', $config['cache_db']['dsn']);
    }
}
