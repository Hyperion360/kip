<?php // tests/S3Test.php
namespace Kip\Tests;

use Kip\S3;
use PHPUnit\Framework\TestCase;

/**
 * The S3 uploader in three verification tiers:
 * 1. Unit: documented AWS Signature Version 4 derivation vectors, canonical
 *    path encoding, and the transport gate.
 * 2. A PHP-native stub server (tests/Fixtures/s3-stub.php) that verifies
 *    signatures with its own independently written derivation, so a bug in
 *    src/S3.php cannot cancel itself out.
 * 3. Real MinIO behind KIP_S3_MINIO=1 (opt-in, needs Docker).
 */
final class S3Test extends TestCase
{
    use S3StubServer;

    /** @return array{endpoint:string,region:string,bucket:string,key:string,secret:string} */
    private static function config(): array
    {
        return [
            'endpoint' => 'http://127.0.0.1:8096',
            'region' => 'us-east-1',
            'bucket' => 'bucket',
            'key' => 'k',
            'secret' => 's',
        ];
    }

    /**
     * AWS documents two derivation examples with the same example secret. The
     * hex constants are quoted from those pages, not from memory: the
     * "Examples of how to derive a signing key" page (date 20120215) prints
     * every intermediate key, and the "Create a signed AWS API request" page
     * (date 20150830) prints its derived signing key. The chain is
     * deterministic, so this also pins that hash_hmac feeds key and data in
     * the documented order with raw binary output.
     */
    public function testKeyDerivationMatchesAwsDocsVector(): void
    {
        $secret = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

        // Vector one, date 20120215: every step is pinned.
        $kDate = hash_hmac('sha256', '20120215', 'AWS4' . $secret, true);
        $this->assertSame('969fbb94feb542b71ede6f87fe4d5fa29c789342b0f407474670f0c2489e0a0d', bin2hex($kDate));
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $this->assertSame('69daa0209cd9c5ff5c8ced464a696fd4252e981430b10e3d3fd8e2f197d7a70c', bin2hex($kRegion));
        $kService = hash_hmac('sha256', 'iam', $kRegion, true);
        $this->assertSame('f72cfd46f26bc4643f06a11eabb6c0ba18780c19a8da0c31ace671265e3c87fa', bin2hex($kService));
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $this->assertSame('f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d', bin2hex($kSigning));

        // Vector two, same secret, date 20150830, region us-east-1, service
        // iam: only the signing key is printed on that page.
        $kSigning2 = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 'iam',
                hash_hmac('sha256', 'us-east-1',
                    hash_hmac('sha256', '20150830', 'AWS4' . $secret, true), true), true), true);
        $this->assertSame('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9', bin2hex($kSigning2));
    }

    /**
     * The canonical URI encodes every byte except the unreserved set and the
     * slash separators (the AWS rule for object key names); space is %20, not
     * +, and hex letters are uppercase.
     */
    public function testCanonicalUriEncodesSlashesSafely(): void
    {
        $s3 = new S3(self::config());
        $this->assertSame(
            '/bucket/backups/2026/a%20b%2B.sqlite',
            $s3->objectPath('backups/2026/a b+.sqlite')
        );
    }

    /** A configured prefix is a folder segment, not a replacement of the key. */
    public function testPrefixBecomesALeadingPathSegment(): void
    {
        $s3 = new S3(self::config() + ['prefix' => 'nightly']);
        $this->assertSame('/bucket/nightly/kip-backup-1.zip', $s3->objectPath('kip-backup-1.zip'));
    }

    public function testConstructorNamesTheMissingConfigKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("S3 config key 'secret' is required");
        new S3(['endpoint' => 'http://127.0.0.1:8096', 'region' => 'us-east-1', 'bucket' => 'b', 'key' => 'k']);
    }

    /**
     * Construction must refuse when no transport exists, naming both
     * remedies. The gate is a pure function of the transport facts because a
     * loaded extension cannot be unloaded at runtime (this PHP ships curl
     * compiled in); the constructor passes the real facts to it.
     */
    public function testPutRejectsMissingTransport(): void
    {
        try {
            S3::assertTransport(false, false, true);
            $this->fail('expected RuntimeException when neither transport exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ext-curl', $e->getMessage());
            $this->assertStringContainsString('ext-openssl', $e->getMessage());
        }
        // The streams fallback still needs allow_url_fopen; that refusal is
        // the same gate's second branch.
        try {
            S3::assertTransport(false, true, false);
            $this->fail('expected RuntimeException when streams cannot open URLs');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('allow_url_fopen', $e->getMessage());
        }
    }

    /**
     * Tier 2, happy path: PUT through the real transport (curl on this host),
     * with an object name that exercises the percent-encoding, then HEAD it.
     * The stub verifies the signature with its own derivation and compares
     * the hash of the bytes that arrived against the header, so a pass means
     * the request was signed right AND the body arrived intact.
     */
    public function testStubRoundTripPutsAndHeadsAnObject(): void
    {
        $config = $this->startS3Stub(8096);
        try {
            $file = (string) tempnam(sys_get_temp_dir(), 'kip-s3-put-');
            $bytes = 'backup-bytes-' . bin2hex(random_bytes(8));
            file_put_contents($file, $bytes);
            $s3 = new S3($config);

            $key = $s3->put($file, 'backups/2026/a b+.sqlite');
            $this->assertSame('backups/2026/a b+.sqlite', $key);
            $this->assertSame($bytes, (string) file_get_contents($config['store'] . '/backups/2026/a b+.sqlite'));
            $this->assertTrue($s3->head('backups/2026/a b+.sqlite'));
            $this->assertFalse($s3->head('backups/2026/missing.sqlite'));

            // A prefix is a folder segment: the returned key and the stored
            // path both carry it.
            $prefixed = new S3($config + ['prefix' => 'nightly']);
            $this->assertSame('nightly/plain.sqlite', $prefixed->put($file, 'plain.sqlite'));
            $this->assertFileExists($config['store'] . '/nightly/plain.sqlite');
            $this->assertTrue($prefixed->head('plain.sqlite'));
            unlink($file);
        } finally {
            $this->stopS3Stub();
        }
    }

    /** Tier 2: a wrong secret must be rejected with 403 and store nothing. */
    public function testStubRejectsAWrongSecretWith403(): void
    {
        $config = $this->startS3Stub(8096);
        try {
            $file = (string) tempnam(sys_get_temp_dir(), 'kip-s3-put-');
            file_put_contents($file, 'these bytes must not land');
            $config['secret'] = 'wrong-secret';
            $s3 = new S3($config);
            try {
                $s3->put($file, 'evil.sqlite');
                $this->fail('expected the wrong secret to be rejected');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('HTTP 403', $e->getMessage());
            }
            $this->assertFileDoesNotExist($config['store'] . '/evil.sqlite');
            unlink($file);
        } finally {
            $this->stopS3Stub();
        }
    }

    /**
     * Tier 2, streams transport: this PHP ships curl compiled in and a
     * compiled-in extension cannot be unloaded at runtime, so the streams
     * branch runs in a subprocess that shadows extension_loaded() inside
     * the Kip namespace. The stub's signature and body-hash checks then
     * verify the streams byte mover separately from curl's.
     */
    public function testStreamsTransportCarriesTheSameSignedRequest(): void
    {
        $config = $this->startS3Stub(8096);
        $script = (string) tempnam(sys_get_temp_dir(), 'kip-s3-streams-');
        $file = (string) tempnam(sys_get_temp_dir(), 'kip-s3-put-');
        try {
            $bytes = 'streamed-bytes-' . bin2hex(random_bytes(8));
            file_put_contents($file, $bytes);
            unset($config['store']); // harness detail, not client config
            $scriptBody = 'namespace Kip;

// Shadow the global extension_loaded() inside namespace Kip: the S3 client
// must believe curl is absent and take the streams transport instead.
function extension_loaded(string $ext): bool
{
    return $ext === \'curl\' ? false : \extension_loaded($ext);
}

require ' . var_export(dirname(__DIR__) . '/src/S3.php', true) . ';
$s3 = new S3(' . var_export($config, true) . ');
$file = ' . var_export($file, true) . ';
$key = $s3->put($file, \'streams/a b+.sqlite\');
if ($key !== \'streams/a b+.sqlite\') { fwrite(STDERR, "key mismatch: {$key}\n"); exit(1); }
if (!$s3->head(\'streams/a b+.sqlite\')) { fwrite(STDERR, "head failed over streams\n"); exit(1); }
if ($s3->head(\'streams/missing.sqlite\')) { fwrite(STDERR, "head on a missing object must be false\n"); exit(1); }
echo "streams-ok\n";
';
            file_put_contents($script, '<?php ' . $scriptBody);
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
            $out = implode("\n", $lines);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('streams-ok', $out);
            $this->assertSame($bytes, (string) file_get_contents($this->s3StubStore() . '/streams/a b+.sqlite'));
        } finally {
            $this->stopS3Stub();
            @unlink($script);
            @unlink($file);
        }
    }

    /** @return string the stub store directory of the running server */
    private function s3StubStore(): string
    {
        return $this->s3Stub !== null ? $this->s3Stub['store'] : '';
    }
}
