<?php // src/Testing/TestClient.php
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
     * @param array<array-key, mixed> $get
     * @param array<array-key, mixed> $post
     * @param array<string, string>   $headers
     * @param array<array-key, mixed> $files
     */
    public function request(string $method, string $path, array $get = [], array $post = [], array $headers = [], array $files = []): Response
    {
        // A client whose session holds state sends a cookie, like a real browser.
        // This keeps the page cache honest: fresh clients are cookieless guests
        // (cacheable), stateful clients are BYPASS.
        $cookies = $this->store === [] ? [] : ['kip_test_session' => '1'];
        return $this->app->handle(new Request($method, $path, $get, $post, $cookies, '127.0.0.1', $headers, $files), $this->session);
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
