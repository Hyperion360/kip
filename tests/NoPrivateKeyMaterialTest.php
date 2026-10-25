<?php // tests/NoPrivateKeyMaterialTest.php
namespace Kip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The zero-key-material contract for this public repo and every consumer of
 * its composer dist: no tracked file carries private-key PEM markers, and no
 * tracked file has a key-file extension. Signing keys in tests are generated
 * at runtime (openssl_pkey_new), operator keys ride env vars pointing OUTSIDE
 * the repo, and this test fails any commit that forgets. Runs from a git
 * checkout, which CI and every developer machine are.
 */
final class NoPrivateKeyMaterialTest extends TestCase
{
    public function test_no_tracked_file_carries_private_key_material(): void
    {
        $root = dirname(__DIR__);
        exec('git -C ' . escapeshellarg($root) . ' ls-files', $files, $code);
        $this->assertSame(0, $code, 'this contract runs from a git checkout');
        $offenders = [];
        foreach ($files as $file) {
            if (preg_match('/\.(p8|pem|key|p12|jks|keystore|ppk)$/i', $file)) {
                $offenders[] = "{$file} (key-file extension)";
                continue;
            }
            $blob = @file_get_contents("{$root}/{$file}");
            if (is_string($blob) && preg_match('/BEGIN [A-Z ]*PRIVATE KEY/', $blob)) {
                $offenders[] = $file;
            }
        }
        $this->assertSame([], $offenders, 'private key material must never be committed: ' . implode(', ', $offenders));
    }
}
