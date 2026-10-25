<?php // tests/Auth/AppleSecretTest.php
namespace Kip\Tests\Auth;

use Kip\Auth\AppleSecret;
use PHPUnit\Framework\TestCase;

final class AppleSecretTest extends TestCase
{
    private const CONFIG = [
        'client_id' => 'org.example.signin',
        'apple_team_id' => 'TEAM123456',
        'apple_key_id' => 'KEY123456',
    ];

    private string $pem;

    private string $publicPem;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key);
        // The PRIVATE key PEM: mint() signs with it, and the test then derives
        // the public side for openssl_verify. get_details()['key'] hands back
        // the public half, which cannot sign.
        $this->assertTrue(openssl_pkey_export($key, $exported));
        $this->pem = $exported;
        // Derived up front: PHP 8.4 on OpenSSL 3 refuses to coerce a private
        // PEM into a public key (openssl_pkey_get_public returns false), so
        // the public half is pulled from get_details() instead.
        $this->publicPem = openssl_pkey_get_details($key)['key'];
    }

    public function test_mints_a_verifiable_es256_jwt_with_apple_claims(): void
    {
        $now = time();
        $jwt = AppleSecret::mint([...self::CONFIG, 'apple_key' => $this->pem], $now);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $jwt !== '' ? $parts : [], 'compact JWS');
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);
        $this->assertSame('ES256', $header['alg'] ?? null);
        $this->assertSame('KEY123456', $header['kid'] ?? null);
        $this->assertSame('TEAM123456', $claims['iss'] ?? null);
        $this->assertSame('https://appleid.apple.com', $claims['aud'] ?? null);
        $this->assertSame('org.example.signin', $claims['sub'] ?? null);
        $this->assertSame($now, $claims['iat'] ?? null);
        $this->assertLessThanOrEqual($now + 180 * 86400, (int) $claims['exp'], 'Apple caps client secrets at six months');

        // The 64-byte raw r||s signature must verify against the public key.
        // openssl_verify wants DER, so the test re-derives the DER INTEGERs
        // from the raw pair (the inverse of derToRaw) before verifying: strip
        // the left pad, then restore DER's positive marker when the top bit
        // is set, which rebuilds each minimal INTEGER whatever length
        // openssl_sign chose (31, 32 with marker, or 33).
        $public = $this->publicPem;
        $sig = base64_decode(strtr($parts[2], '-_', '+/'), true);
        $this->assertTrue(is_string($sig) && strlen($sig) === 64, 'ES256 raw signature is r||s, exactly 64 bytes');
        $toDer = static function (string $half): string {
            $value = ltrim($half, "\0");
            if (ord($value[0]) >= 0x80) $value = "\x00" . $value; // DER positive marker for a high first bit
            return "\x02" . chr(strlen($value)) . $value;
        };
        $r = $toDer(substr($sig, 0, 32));
        $s = $toDer(substr($sig, 32, 32));
        $der = "\x30" . chr(strlen($r . $s)) . $r . $s;
        $this->assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], $der, $public, OPENSSL_ALGO_SHA256));
    }

    public function test_a_tampered_payload_fails_verification(): void
    {
        $jwt = AppleSecret::mint([...self::CONFIG, 'apple_key' => $this->pem], time());
        $parts = explode('.', $jwt);
        $forged = $parts[0] . '.' . base64_encode(json_encode(['iss' => 'evil'])) . '.' . $parts[2];
        $parts = explode('.', $forged);
        $sig = base64_decode(strtr($parts[2], '-_', '+/'), true);
        $this->assertNotSame(1, openssl_verify($parts[0] . '.' . $parts[1], (string) $sig, $this->publicPem, OPENSSL_ALGO_SHA256));
    }

    public function test_refuses_config_without_a_key_or_team_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppleSecret::mint(['client_id' => 'x', 'apple_key_id' => 'k'], null); // no apple_key, no apple_key_file, no team
    }

    public function test_reads_the_key_from_a_file_when_the_path_is_given(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'p8-');
        file_put_contents((string) $path, $this->pem);
        $jwt = AppleSecret::mint([...self::CONFIG, 'apple_key_file' => $path], time());
        $this->assertStringContainsString('.', $jwt);
        @unlink((string) $path);
    }
}
