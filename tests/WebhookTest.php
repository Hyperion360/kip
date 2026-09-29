<?php // tests/WebhookTest.php
namespace Kip\Tests;
use Kip\Http\Request;
use Kip\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec-test';

    private function signed(string $body, ?string $signature, string $header = 'x-webhook-signature'): Request
    {
        $headers = $signature === null ? [] : [$header => $signature];
        return new Request('POST', '/hooks/demo', [], [], [], '127.0.0.1', $headers, [], $body);
    }

    public function test_accepts_a_bare_hex_signature(): void
    {
        $body = '{"event":"ping"}';
        $sig = hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(Webhook::verify($this->signed($body, $sig), self::SECRET));
    }

    public function test_accepts_the_sha256_prefixed_form(): void
    {
        $body = '{"event":"ping"}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(Webhook::verify($this->signed($body, $sig), self::SECRET));
    }

    public function test_rejects_a_signature_from_a_wrong_secret(): void
    {
        $body = '{"event":"ping"}';
        $forged = hash_hmac('sha256', $body, 'whsec-attacker');
        $this->assertFalse(Webhook::verify($this->signed($body, $forged), self::SECRET));
    }

    public function test_rejects_a_missing_header(): void
    {
        $this->assertFalse(Webhook::verify($this->signed('{"event":"ping"}', null), self::SECRET));
    }

    public function test_empty_body_with_an_empty_signature_is_rejected(): void
    {
        // The HMAC of an empty body is a normal digest, never the empty string,
        // so an empty header value must fail closed, not "verify" vacuously.
        $this->assertFalse(Webhook::verify($this->signed('', ''), self::SECRET));
    }

    public function test_empty_secret_fails_closed(): void
    {
        $body = '{"event":"ping"}';
        $this->assertFalse(Webhook::verify($this->signed($body, hash_hmac('sha256', $body, '')), ''),
            'a config bug (empty secret) must never verify anything');
    }

    public function test_header_name_lookup_is_case_insensitive(): void
    {
        $body = '{"event":"ping"}';
        $sig = hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(Webhook::verify($this->signed($body, $sig, 'x-hook-sig'), self::SECRET, 'X-Hook-Sig'));
    }

    public function test_the_signature_is_over_the_raw_bytes_not_a_re_encoded_parse(): void
    {
        // Multibyte body: a decode/encode round trip escapes the é, changing the
        // bytes and therefore the digest. Verification must sign what arrived.
        $body = '{"note":"café"}';
        $this->assertTrue(Webhook::verify($this->signed($body, hash_hmac('sha256', $body, self::SECRET)), self::SECRET));
        $roundTrip = (string) json_encode(json_decode($body, true), JSON_UNESCAPED_SLASHES);
        $this->assertNotSame($body, $roundTrip);
        $this->assertFalse(Webhook::verify($this->signed($body, hash_hmac('sha256', $roundTrip, self::SECRET)), self::SECRET));
    }
}
