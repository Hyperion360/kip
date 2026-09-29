<?php // src/Webhook.php

declare(strict_types=1);
namespace Kip;

use Kip\Http\Request;

/**
 * Constant-time verification for signed webhooks: HMAC-SHA256 over the RAW
 * request body (the bytes that arrived, never a re-encoded parse), compared
 * with hash_equals. Server-to-server callers have no session, so session CSRF
 * does not apply to them; the signature is the proof of origin. Replay
 * protection stays the app's concern: providers that sign a timestamp put it
 * in the signed payload or the header, and the app reads it.
 */
final class Webhook
{
    /**
     * True when the request's $header carries the HMAC-SHA256 of its raw body
     * under $secret. Accepts the bare hex form and the common `sha256=<hex>`
     * prefixed shape. False on a missing header, an empty secret (a config bug
     * fails closed), or any mismatch.
     */
    public static function verify(Request $request, string $secret, string $header = 'x-webhook-signature'): bool
    {
        if ($secret === '') return false;
        $provided = $request->header($header);
        if ($provided === null || $provided === '') return false;
        if (str_starts_with(strtolower($provided), 'sha256=')) $provided = substr($provided, 7);
        return hash_equals(hash_hmac('sha256', $request->body, $secret), strtolower($provided));
    }
}
