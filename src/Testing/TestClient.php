<?php // src/Testing/TestClient.php

declare(strict_types=1);
namespace Kip\Testing;

use Kip\App;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Session;

/**
 * In-process test client: drives App::handle() like a browser, holding one
 * session across requests. No server, no sockets, a full request/response
 * cycle per call, CSRF and #[Auth] included.
 */
final class TestClient
{
    /** @var array<string, mixed> */
    private array $store = [];
    /** @var array<string, string> cookies this client presents on every request (theme preference, consent flags) */
    private array $cookies = [];
    public readonly Session $session;

    public function __construct(private App $app)
    {
        $this->session = new Session($this->store);
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    public function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $path, $query, [], $headers);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string>   $headers
     */
    public function post(string $path, array $data = [], array $headers = []): Response
    {
        return $this->request('POST', $path, [], $data, $headers);
    }

    /**
     * POST a JSON body. Arrays encode with the same flags the kernel's #[Json]
     * wrap uses; strings pass through raw, so a malformed payload reaches the
     * app for its 400 path. The CSRF token is NOT merged (mirrors post()):
     * pass ['x-csrf-token' => $client->csrfToken()] in $headers.
     *
     * @param array<array-key, mixed>|string $payload
     * @param array<string, string>          $headers
     */
    public function postJson(string $path, array|string $payload = [], array $headers = []): Response
    {
        $body = is_string($payload)
            ? $payload
            : (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->request('POST', $path, [], [], $headers + ['content-type' => 'application/json'], [], $body);
    }

    /** Present a cookie on every subsequent request, like a browser that received one. */
    public function cookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * @param array<array-key, mixed> $get
     * @param array<array-key, mixed> $post
     * @param array<string, string>   $headers
     * @param array<array-key, mixed> $files
     */
    public function request(string $method, string $path, array $get = [], array $post = [], array $headers = [], array $files = [], string $body = ''): Response
    {
        // A client whose session holds state sends a cookie, like a real browser.
        // This keeps the page cache honest: fresh clients are cookieless guests
        // (cacheable), stateful clients are BYPASS. Explicitly presented cookies
        // ride along either way.
        $cookies = $this->store === [] ? [] : ['kip_test_session' => '1'];
        $response = $this->app->handle(new Request($method, $path, $get, $post, $this->cookies + $cookies, '127.0.0.1', $headers, $files, $body), $this->session);
        $this->app->runDeferred(); // as the front controller does after send()
        return $response;
    }

    /** Log in without a password round-trip: seed the session like Auth::attempt() does,
     *  including the password epoch the kernel's auth gate checks. */
    public function actingAs(int $userId): self
    {
        $this->session->set('user_id', $userId);
        try {
            $row = $this->app->container->make(\Kip\Database::class)
                ->one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
            if (is_string($row['password_hash'] ?? null)) {
                $this->session->set('pwd_epoch', substr($row['password_hash'], 0, \Kip\Auth::EPOCH_LEN));
            }
        } catch (\Throwable) {
            // no database / no users table, session-only auth mode, nothing to seed
        }
        return $this;
    }

    public function csrfToken(): string
    {
        return $this->session->csrfToken();
    }

    /**
     * POST with the session CSRF token merged in, the common authed-form case.
     *
     * @param array<array-key, mixed> $data
     */
    public function postWithToken(string $path, array $data = []): Response
    {
        return $this->post($path, $data + ['_token' => $this->csrfToken()]);
    }

    /**
     * POST a form with a file (plus the CSRF token), the upload case.
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $file
     */
    public function postWithFile(string $path, array $data, string $field, array $file): Response
    {
        return $this->request('POST', $path, [], $data + ['_token' => $this->csrfToken()], [], [$field => $file]);
    }
}
