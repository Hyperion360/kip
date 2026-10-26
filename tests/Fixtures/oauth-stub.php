<?php // tests/Fixtures/oauth-stub.php, a stub OAuth identity provider for the OAuth tests.
//
// Runs ONLY as a php -S router (127.0.0.1, throwaway port 8093+, started by
// the test harness). It speaks the authorization-code flow the client
// implements: /authorize issues single-use codes bound to the registered
// redirect URI and PKCE challenge, /token exchanges them (form-encoded
// unless the request asks for JSON, the GitHub behavior), and the user
// endpoints answer the shapes the client maps (oidc, github, msgraph, plus
// the Discord/Facebook userinfo payloads). Every check is written from the
// RFC structure on purpose and shared with nothing in src/, so a client bug
// cannot cancel itself out against a server that repeats it.
//
// Configuration through the environment (set by the harness):
//   KIP_OAUTH_STUB_CLIENT      the client id (default stub-client)
//   KIP_OAUTH_STUB_SECRET      the client secret (default stub-secret)
//   KIP_OAUTH_STUB_REDIRECT    the ONE registered redirect URI, exact match
//   KIP_OAUTH_STUB_PKCE        1 to require a S256 challenge at authorize
//   KIP_OAUTH_STUB_USER        the subject this IdP answers for
//   KIP_OAUTH_STUB_EMAIL       the account email it reports (unset or '' omits the field, like the real providers)
//   KIP_OAUTH_STUB_VERIFIED    1 when the email reports verified; 'omit' leaves the marker out entirely
//                              (an empty value never reaches the stub: proc_open drops empty env, so a word is the switch)
//   KIP_OAUTH_STUB_ID_KEY      the userinfo id field name ('sub' default, 'id' for the Discord/Facebook shapes)
//   KIP_OAUTH_STUB_ID_TOKEN    the id_token the token response carries: a JSON response gains it verbatim, and the
//                              client secret check then also accepts a well-formed three-segment JWT (three non-empty
//                              base64url segments, the shape of the minted Sign in with Apple client secret) in place
//                              of the registered secret; its claims are the client's problem, not the stub's
//   KIP_OAUTH_STUB_USER_EMAIL  the /user response's own email field ('' = null)
//   KIP_OAUTH_STUB_ALT_EMAIL   a secondary non-primary address on /user/emails
//   KIP_OAUTH_STUB_POISON      1 to answer /userinfo with a 302 (the client must refuse)
//   KIP_OAUTH_STUB_PROBE       when set, /token and /fbtoken skip the flow and answer the scripted probe shape:
//                              invalid_grant (400 error object), invalid_client (401), 429, 500, 500_invalid_client
//                              (500 whose body echoes invalid_client), ok (200 token body), fb_ok (200 with an
//                              app access token), fb_bad (400 error object)
//   KIP_OAUTH_STUB_STORE       directory codes and the request log live in

$env = static fn(string $k, string $d = ''): string => (string) (getenv($k) !== false ? getenv($k) : $d);
$client = $env('KIP_OAUTH_STUB_CLIENT', 'stub-client');
$secret = $env('KIP_OAUTH_STUB_SECRET', 'stub-secret');
$redirect = $env('KIP_OAUTH_STUB_REDIRECT');
$pkce = $env('KIP_OAUTH_STUB_PKCE', '1') === '1';
$user = $env('KIP_OAUTH_STUB_USER', 'stub-user-1');
// No default address: unset and '' both mean "this account has none", which
// is what the Facebook flow needs (proc_open cannot deliver an empty env
// value, so the two must mean the same thing anyway).
$email = $env('KIP_OAUTH_STUB_EMAIL');
$verifiedRaw = $env('KIP_OAUTH_STUB_VERIFIED', '1');
$verified = $verifiedRaw === '1';
$idKey = $env('KIP_OAUTH_STUB_ID_KEY', 'sub');
$idToken = $env('KIP_OAUTH_STUB_ID_TOKEN');
$userEmail = $env('KIP_OAUTH_STUB_USER_EMAIL');
$altEmail = $env('KIP_OAUTH_STUB_ALT_EMAIL');
$poison = $env('KIP_OAUTH_STUB_POISON') === '1';
$probe = $env('KIP_OAUTH_STUB_PROBE');
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

// The scripted probe: when KIP_OAUTH_STUB_PROBE is set, the token endpoint
// (and the /fbtoken route the Facebook app-token probe uses) answers these
// canned verdicts instead of running the flow. The values are the cases the
// client's probe() must tell apart: an OAuth error naming the code (the
// credentials were accepted), invalid_client (they were not), a rate limit
// and a server fault (indeterminate), and the two Facebook app-token
// answers, whose error objects hide the code-vs-credentials distinction.
$probeAnswer = static function () use ($probe, $json, $fail): void {
    if ($probe === '') return;
    match ($probe) {
        'invalid_grant' => $json(['error' => 'invalid_grant'], 400),
        'invalid_client' => $json(['error' => 'invalid_client'], 401),
        '429' => $json(['error' => 'slow_down'], 429),
        '500' => $json(['error' => 'invalid_request'], 500),
        '500_invalid_client' => $json(['error' => 'invalid_client'], 500), // an outage echoing the upstream error
        'ok' => $json(['access_token' => 'probe-token', 'token_type' => 'Bearer']),
        'html_404' => $fail(404, 'nothing here'), // a reverse proxy's plain-text 404, no OAuth shape
        'fb_ok' => $json(['access_token' => 'probe-app-token', 'token_type' => 'Bearer']),
        'fb_bad' => $json(['error' => ['message' => 'Error validating application', 'type' => 'OAuthException', 'code' => 101]], 400),
        default => $fail(500, 'unknown probe script ' . $probe),
    };
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
    $probeAnswer();
    if ($method !== 'POST') $fail(405, 'token is a POST');
    parse_str((string) file_get_contents('php://input'), $form);
    // Client credentials first: an unknown client or wrong secret never
    // burns a code. With an id_token configured, a well-formed three-segment
    // JWT stands in for the registered secret: that is the shape the apple
    // client sends (it mints its client secret, it does not store one). The
    // stub checks the shape only, never the claims; that split keeps this
    // file written from the RFC structure, shared with nothing in src/.
    $jwtShape = static function (string $v): bool {
        $segments = explode('.', $v);
        if (count($segments) !== 3) return false;
        foreach ($segments as $segment) {
            if (preg_match('#^[A-Za-z0-9_-]+$#', $segment) !== 1) return false;
        }
        return true;
    };
    if (($form['client_id'] ?? '') !== $client) $fail(401, 'unknown client');
    $sentSecret = (string) ($form['client_secret'] ?? '');
    if (hash_equals($secret, $sentSecret) !== true && !($idToken !== '' && $jwtShape($sentSecret))) {
        $fail(401, 'wrong client secret');
    }
    if (($form['grant_type'] ?? '') !== 'authorization_code') $fail(400, 'grant_type must be authorization_code');
    $code = (string) ($form['code'] ?? '');
    $file = $store . '/codes/' . hash('sha256', $code) . '.json';
    // A code problem is an OAuth error object naming the grant, so a client
    // probing the endpoint can read the refusal as "credentials accepted".
    if ($code === '' || !is_file($file)) $json(['error' => 'invalid_grant', 'error_description' => 'unknown or already-used code'], 400);
    // Atomic single-use claim: the rename either succeeds once or fails.
    if (!@rename($file, $file . '.claimed')) $json(['error' => 'invalid_grant', 'error_description' => 'unknown or already-used code'], 400);
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
        $payload = ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600];
        if ($idToken !== '') $payload['id_token'] = $idToken;
        $json($payload);
    }
    // The GitHub default: a form-encoded string, useless to a JSON parser.
    http_response_code(200);
    header('Content-Type: application/x-www-form-urlencoded');
    echo 'access_token=' . rawurlencode($token) . '&token_type=Bearer';
    exit;
}

if ($path === '/fbtoken') {
    // Facebook's app-token endpoint: the probe POSTs the client_credentials
    // grant to it (never a GET: the secret would land in request logs), so
    // the tests point the configured token URL here and the scripted probe
    // answers for it.
    $probeAnswer();
    $fail(404, 'fbtoken answers only a scripted probe');
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
    // The payload mirrors what the real providers ship: the id field key
    // follows the provider family (sub for the OIDC shape, id for Discord
    // and Facebook), an absent address is omitted rather than sent empty,
    // and a verification marker, when one exists at all, is spelled both
    // ways (email_verified for OIDC, verified for Discord) so each shape
    // reads its own key. An empty KIP_OAUTH_STUB_VERIFIED omits the marker,
    // the Facebook situation.
    $payload = [$idKey => $user];
    if ($email !== '') $payload['email'] = $email;
    if ($verifiedRaw !== '' && $verifiedRaw !== 'omit') {
        $payload['email_verified'] = $verified;
        $payload['verified'] = $verified;
    }
    $json($payload);
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
