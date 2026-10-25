<?php // src/Auth/AppleSecret.php
declare(strict_types=1);
namespace Kip\Auth;

/**
 * The client secret Sign in with Apple demands: not a string in config but an
 * ES256-signed JWT naming the team, the key, and Apple as the audience. Minted
 * fresh for every token-endpoint call (Apple caps the exp at six months; a
 * per-request mint means nothing to rotate and nothing to leak at rest), the
 * .p8 key file is the only long-lived material and stays operator-managed.
 * The JWT is a bearer credential: it lives in the caller's locals, marked
 * sensitive, and is never logged or carried in an exception message.
 */
final class AppleSecret
{
    /** Apple's own ceiling for the client-secret lifetime. */
    public const MAX_TTL = 180 * 86400;

    /**
     * @param array<string, mixed> $config client_id, apple_team_id,
     *        apple_key_id, and either apple_key (PEM text) or apple_key_file
     * @param int|null $now test seam
     */
    public static function mint(array $config, ?int $now = null): string
    {
        $now ??= time();
        $clientId = $config['client_id'] ?? null;
        $teamId = $config['apple_team_id'] ?? null;
        $keyId = $config['apple_key_id'] ?? null;
        foreach (['client_id' => $clientId, 'apple_team_id' => $teamId, 'apple_key_id' => $keyId] as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException("Apple sign-in config key '{$key}' is required");
            }
        }
        $pem = $config['apple_key'] ?? null;
        if (is_string($config['apple_key_file'] ?? null) && $config['apple_key_file'] !== '') {
            $pem = @file_get_contents((string) $config['apple_key_file']);
            if ($pem === false) {
                throw new \RuntimeException('cannot read the Apple signing key file');
            }
        }
        if (!is_string($pem) || $pem === '') {
            throw new \InvalidArgumentException("Apple sign-in needs 'apple_key' (PEM text) or 'apple_key_file'");
        }
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('Sign in with Apple needs ext-openssl for the ES256 client secret');
        }
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('the Apple signing key is not a readable private key');
        }
        $segments = [
            self::b64(['alg' => 'ES256', 'kid' => $keyId]),
            self::b64([
                'iss' => $teamId,
                'iat' => $now,
                'exp' => $now + self::MAX_TTL,
                'aud' => 'https://appleid.apple.com',
                'sub' => $clientId,
            ]),
        ];
        openssl_sign($segments[0] . '.' . $segments[1], $der, $key, OPENSSL_ALGO_SHA256);
        if (!is_string($der) || $der === '') {
            throw new \RuntimeException('the Apple client-secret signature failed');
        }
        return $segments[0] . '.' . $segments[1] . '.' . self::b64(self::derToRaw($der));
    }

    /**
     * base64url, padding stripped (RFC 7515).
     *
     * @param array<string, mixed>|string $in
     */
    private static function b64(array|string $in): string
    {
        return rtrim(strtr(base64_encode(is_string($in) ? $in : json_encode($in, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /**
     * openssl_sign emits a DER SEQUENCE of two INTEGERs; JWS ES256 wants the
     * raw fixed-width r||s pair. Each INTEGER is stripped of DER's positive
     * marker (a leading 0x00, present whenever the high bit is set, which is
     * half of all signatures) and re-padded to exactly 32 bytes.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 2; // SEQUENCE header
        $integers = [];
        while ($offset < strlen($der) && count($integers) < 2) {
            $type = ord($der[$offset]);
            $length = ord($der[$offset + 1]);
            if ($type !== 0x02 || $length === 0 || $length > 33) {
                throw new \RuntimeException('unexpected DER shape in the ECDSA signature');
            }
            $integers[] = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
        }
        if (count($integers) !== 2) {
            throw new \RuntimeException('the ECDSA signature DER carries unexpected parts');
        }
        $half = static function (string $integer): string {
            $integer = ltrim($integer, "\0");
            return str_pad($integer, 32, "\0", STR_PAD_LEFT);
        };
        return $half($integers[0]) . $half($integers[1]);
    }
}
