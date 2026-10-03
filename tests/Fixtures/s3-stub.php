<?php // tests/Fixtures/s3-stub.php, a stub S3 endpoint for the S3 tests.
//
// Runs ONLY as a php -S router (127.0.0.1, throwaway port, started by the
// test harness). PUT stores the body under the store directory, HEAD answers
// existence. Every request is verified with a Signature Version 4 check
// written from the AWS documentation structure on purpose and NOT shared
// with src/S3.php, so a transcription bug in the client cannot cancel
// itself out against a server that repeats it.
//
// Configuration through the environment (set by the harness):
//   KIP_S3_STUB_SECRET  the secret signatures must match (default stub-secret)
//   KIP_S3_STUB_KEY     the access key id (default stub-key)
//   KIP_S3_STUB_BUCKET  the bucket name (default stub-bucket)
//   KIP_S3_STUB_STORE   directory PUT bodies are written under

$secret = (string) (getenv('KIP_S3_STUB_SECRET') ?: 'stub-secret');
$keyId = (string) (getenv('KIP_S3_STUB_KEY') ?: 'stub-key');
$bucket = (string) (getenv('KIP_S3_STUB_BUCKET') ?: 'stub-bucket');
$store = (string) (getenv('KIP_S3_STUB_STORE') ?: sys_get_temp_dir() . '/kip-s3-stub-store');

$fail = static function (int $status, string $why): void {
    http_response_code($status);
    header('Content-Type: text/plain');
    echo $why . "\n";
    exit;
};

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
// The path as received, percent-encoded form intact: that is what the client
// signed, so it is what the canonical request below must use.
$rawPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$body = (string) (file_get_contents('php://input') ?: '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$amzDate = (string) ($_SERVER['HTTP_X_AMZ_DATE'] ?? '');
$payloadHash = (string) ($_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '');
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

// The redirect fixture, for the transport no-follow pin: a key under
// redirect/ answers 302 toward /landed/, a plain dump target that skips the
// signature checks. A transport that follows the redirect re-sends the whole
// request, Authorization header included, and the object lands; the pin
// asserts it never does. Runs before verification: a redirect is an
// endpoint-level answer, not an authenticated one.
if (str_starts_with($rawPath, '/' . $bucket . '/redirect/')) {
    http_response_code(302);
    header('Location: /' . $bucket . '/landed/' . basename($rawPath));
    exit;
}
if (str_starts_with($rawPath, '/' . $bucket . '/landed/') && $method === 'PUT') {
    $landed = $store . '/landed/' . basename($rawPath);
    @mkdir(dirname($landed), 0777, true);
    file_put_contents($landed, $body);
    exit; // 200, unsigned on purpose: this target exists to prove following
}

// Structural checks: the Authorization header must parse into
// Credential/SignedHeaders/Signature, the signed header list must be exactly
// the three the client documents, and the access key must be known.
if (preg_match(
    '#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/([^/]+)/aws4_request, '
        . 'SignedHeaders=([^,]+), Signature=([0-9a-f]{64})$#',
    $auth,
    $m
) !== 1) {
    $fail(403, 'Authorization is not a parsable SigV4 header');
}
[, $credentialKey, $date, $region, $service, $signedHeaders, $signature] = $m;
if ($signedHeaders !== 'host;x-amz-content-sha256;x-amz-date') {
    $fail(403, "unexpected SignedHeaders: {$signedHeaders}");
}
if ($credentialKey !== $keyId) {
    $fail(403, 'unknown access key id');
}
$when = DateTime::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
if ($when === false || abs(time() - $when->getTimestamp()) > 900) {
    $fail(403, 'x-amz-date is not recent');
}
// Body integrity through the transport: the hash of the bytes that arrived
// must equal the header the signature covers, whatever moved them.
if (hash('sha256', $body) !== $payloadHash) {
    $fail(400, 'received body does not match x-amz-content-sha256');
}

// Signature verification, rebuilt from the request as received following the
// documented canonical-request and string-to-sign structure.
$canonicalRequest = $method . "\n" . $rawPath . "\n" . "\n"
    . "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n"
    . "\n" . $signedHeaders . "\n" . $payloadHash;
$stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$date}/{$region}/{$service}/aws4_request\n"
    . hash('sha256', $canonicalRequest);
$kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
$kRegion = hash_hmac('sha256', $region, $kDate, true);
$kService = hash_hmac('sha256', $service, $kRegion, true);
$kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
if (!hash_equals(hash_hmac('sha256', $stringToSign, $kSigning), $signature)) {
    $fail(403, 'signature mismatch');
}

// Object routing: /bucket/key..., the key decoded for storage only.
$segments = explode('/', trim(rawurldecode($rawPath), '/'));
if (array_shift($segments) !== $bucket || $segments === []) {
    $fail(404, 'no such bucket');
}
$file = $store . '/' . implode('/', $segments);
@mkdir(dirname($file), 0777, true);

if ($method === 'PUT') {
    if (file_put_contents($file, $body) === false) {
        $fail(500, 'cannot store the object');
    }
    header('ETag: "' . md5($body) . '"');
    exit; // 200
}
if ($method === 'HEAD') {
    if (!is_file($file)) {
        $fail(404, 'no such object');
    }
    header('Content-Length: ' . (string) filesize($file));
    exit; // 200, and no body on a HEAD response
}
$fail(405, "unsupported method {$method}");
