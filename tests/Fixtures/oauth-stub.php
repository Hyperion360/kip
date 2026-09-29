<?php // tests/Fixtures/oauth-stub.php, a stub OAuth identity provider for the OAuth tests.
//
// Runs ONLY as a php -S router (127.0.0.1, throwaway port 8093+, started by
// the test harness). It speaks the authorization-code flow the client
// implements: /authorize issues single-use codes bound to the registered
// redirect URI and PKCE challenge, /token exchanges them (form-encoded
// unless the request asks for JSON, the GitHub behavior), and the user
// endpoints answer the three shapes the client maps (oidc, github,
// msgraph). Every check is written from the RFC structure on purpose and
// shared with nothing in src/, so a client bug cannot cancel itself out
// against a server that repeats it.
//
// Configuration through the environment (set by the harness):
//   KIP_OAUTH_STUB_CLIENT      the client id (default stub-client)
//   KIP_OAUTH_STUB_SECRET      the client secret (default stub-secret)
//   KIP_OAUTH_STUB_REDIRECT    the ONE registered redirect URI, exact match
//   KIP_OAUTH_STUB_PKCE        1 to require a S256 challenge at authorize
//   KIP_OAUTH_STUB_USER        the subject this IdP answers for
//   KIP_OAUTH_STUB_EMAIL       the account email it reports
//   KIP_OAUTH_STUB_VERIFIED    1 when the email reports verified
//   KIP_OAUTH_STUB_USER_EMAIL  the /user response's own email field ('' = null)
//   KIP_OAUTH_STUB_ALT_EMAIL   a secondary non-primary address on /user/emails
//   KIP_OAUTH_STUB_POISON      1 to answer /userinfo with a 302 (the client must refuse)
//   KIP_OAUTH_STUB_STORE       directory codes and the request log live in

$env = static fn(string $k, string $d = ''): string => (string) (getenv($k) !== false ? getenv($k) : $d);
$client = $env('KIP_OAUTH_STUB_CLIENT', 'stub-client');
$secret = $env('KIP_OAUTH_STUB_SECRET', 'stub-secret');
$redirect = $env('KIP_OAUTH_STUB_REDIRECT');
$pkce = $env('KIP_OAUTH_STUB_PKCE', '1') === '1';
$user = $env('KIP_OAUTH_STUB_USER', 'stub-user-1');
$email = $env('KIP_OAUTH_STUB_EMAIL', 'user@example.org');
$verified = $env('KIP_OAUTH_STUB_VERIFIED', '1') === '1';
$userEmail = $env('KIP_OAUTH_STUB_USER_EMAIL');
$altEmail = $env('KIP_OAUTH_STUB_ALT_EMAIL');
$poison = $env('KIP_OAUTH_STUB_POISON') === '1';
$store = $env('KIP_OAUTH_STUB_STORE', sys_get_temp_dir() . '/kip-oauth-stub');
@mkdir($store . '/codes', 0777, true);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
// Every request is logged: the negative tests prove a follow-redirect never
// happened by reading this file.
@file_put_contents($store . '/requests.log', "{$method} {$path}\n", FILE_APPEND);

$fail = static function (int $status, string $why): void {
    http_response_code($status);
    header('Content-Type: text/plain');
    echo $why . "\n";
    exit;
};
$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

/** Immutable-boundary token: the user rides inside, base64url JSON. */
$mintToken = static function (string $u): string {
    return 'stubtok.' . rtrim(strtr(base64_encode(json_encode(['u' => $u], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
};
$userOfToken = static function (string $token): ?string {
    if (preg_match('#^stubtok\.([A-Za-z0-9_-]+)$#', $token, $m) !== 1) return null;
    $decoded = base64_decode(strtr($m[1], '-_', '+/'), true);
    if ($decoded === false) return null;
    $payload = json_decode($decoded, true);
    return is_array($payload) && is_string($payload['u'] ?? null) ? $payload['u'] : null;
};

if ($path === '/authorize') {
    if ($method !== 'GET') $fail(405, 'authorize is a GET');
    $q = $_GET;
    if (($q['client_id'] ?? '') !== $client) $fail(400, 'unknown client_id');
    if (($q['response_type'] ?? '') !== 'code') $fail(400, 'response_type must be code');
    if (!is_string($q['state'] ?? null) || $q['state'] === '') $fail(400, 'state is required');
    if (!is_string($q['redirect_uri'] ?? null) || $q['redirect_uri'] === '') $fail(400, 'redirect_uri is required');
    // EXACT equality against the one registered callback: prefix and suffix
    // tricks both die here.
    if ($q['redirect_uri'] !== $redirect) $fail(400, 'redirect_uri is not the registered callback');
    $challenge = $q['code_challenge'] ?? '';
    if ($pkce) {
        if (!is_string($challenge) || preg_match('#^[A-Za-z0-9_-]{43,128}$#', $challenge) !== 1) {
            $fail(400, 'this IdP requires a PKCE S256 code_challenge');
        }
        if (($q['code_challenge_method'] ?? '') !== 'S256') $fail(400, 'code_challenge_method must be S256');
    }
    $code = bin2hex(random_bytes(20));
    // An absent challenge is stored as null, never '': the token exchange
    // requires a verifier only when a challenge was actually issued.
    file_put_contents(
        $store . '/codes/' . hash('sha256', $code) . '.json',
        json_encode(['challenge' => $challenge !== '' ? $challenge : null, 'redirect_uri' => $q['redirect_uri'], 'created' => time()], JSON_THROW_ON_ERROR)
    );
    $sep = str_contains($q['redirect_uri'], '?') ? '&' : '?';
    header('Location: ' . $q['redirect_uri'] . $sep . 'code=' . rawurlencode($code) . '&state=' . rawurlencode($q['state']));
    http_response_code(302);
    exit;
}

if ($path === '/token') {
    if ($method !== 'POST') $fail(405, 'token is a POST');
    parse_str((string) file_get_contents('php://input'), $form);
    // Client credentials first: an unknown client or wrong secret never
    // burns a code.
    if (($form['client_id'] ?? '') !== $client) $fail(401, 'unknown client');
    if (hash_equals($secret, (string) ($form['client_secret'] ?? '')) !== true) $fail(401, 'wrong client secret');
    if (($form['grant_type'] ?? '') !== 'authorization_code') $fail(400, 'grant_type must be authorization_code');
    $code = (string) ($form['code'] ?? '');
    $file = $store . '/codes/' . hash('sha256', $code) . '.json';
    if ($code === '' || !is_file($file)) $fail(400, 'unknown or already-used code');
    // Atomic single-use claim: the rename either succeeds once or fails.
    if (!@rename($file, $file . '.claimed')) $fail(400, 'unknown or already-used code');
    $bound = json_decode((string) file_get_contents($file . '.claimed'), true);
    if (!is_array($bound) || time() - (int) ($bound['created'] ?? 0) > 120) $fail(400, 'expired code');
    if (($form['redirect_uri'] ?? '') !== ($bound['redirect_uri'] ?? '')) $fail(400, 'redirect_uri differs from the authorize-time value');
    if (($bound['challenge'] ?? null) !== null) {
        $verifier = (string) ($form['code_verifier'] ?? '');
        if (preg_match('#^[\x21-\x7e]{43,128}$#', $verifier) !== 1) $fail(400, 'code_verifier is required');
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (hash_equals((string) $bound['challenge'], $computed) !== true) $fail(400, 'code_verifier does not match the challenge');
    }
    $token = $mintToken($user);
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        $json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }
    // The GitHub default: a form-encoded string, useless to a JSON parser.
    http_response_code(200);
    header('Content-Type: application/x-www-form-urlencoded');
    echo 'access_token=' . rawurlencode($token) . '&token_type=Bearer';
    exit;
}

$bearer = static function () use ($userOfToken, $fail): string {
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('#^Bearer (\S+)$#', $header, $m) !== 1) $fail(401, 'a Bearer token is required');
    $u = $userOfToken($m[1]);
    if ($u === null) $fail(401, 'not a token this IdP issued');
    return $u;
};

if ($path === '/userinfo') {
    $bearer();
    if ($poison) {
        // A user-info endpoint answering a redirect: a client that follows
        // it would attach the bearer header to wherever this points.
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        header('Location: http://' . $host . '/leak');
        http_response_code(302);
        exit;
    }
    $json(['sub' => $user, 'email' => $email, 'email_verified' => $verified]);
}

if ($path === '/user') {
    $bearer();
    $json([
        'id' => ctype_digit($user) ? (int) $user : $user,
        'login' => 'stubuser',
        'name' => 'Stub User',
        'email' => $userEmail !== '' ? $userEmail : null,
    ]);
}

if ($path === '/user/emails') {
    $bearer();
    $list = [['email' => $email, 'primary' => true, 'verified' => $verified]];
    if ($altEmail !== '') {
        $list[] = ['email' => $altEmail, 'primary' => false, 'verified' => true];
    }
    $json($list);
}

if ($path === '/me') {
    // The msgraph shape: an id and a mailbox, but no email-verification
    // marker anywhere in the response.
    $bearer();
    $json(['id' => $user, 'mail' => $email, 'userPrincipalName' => $user . '@stub.example']);
}

if ($path === '/leak') {
    // Reached only by a client that followed the poisoned redirect. The
    // request log is the evidence; never hand the bearer token onward.
    $fail(200, 'the leak endpoint was requested');
}

$fail(404, "no such endpoint {$path}");
