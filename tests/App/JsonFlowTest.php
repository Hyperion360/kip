<?php // tests/App/JsonFlowTest.php
namespace Kip\Tests\App;
use Kip\App;
use Kip\Database;
use Kip\Http\Response;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The JSON battery end to end: #[Json] wrapping, the X-CSRF-Token header, the
// malformed-body 400, and Response passthrough, against the fixture ApiController.
final class JsonFlowTest extends TestCase
{
    private App $app;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new App([
            'env' => 'dev',
            'db' => ['dsn' => 'sqlite::memory:'],
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $db = $this->app->container->make(Database::class);
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT)');
        $db->query("INSERT INTO users (email, password_hash) VALUES ('u@x.com', 'h')");
        $this->client = new TestClient($this->app);
    }

    public function test_json_get_wraps_the_result_as_application_json(): void
    {
        $res = $this->client->get('/api/list');
        $this->assertSame(200, $res->status);
        $this->assertSame('application/json', $res->headers['Content-Type'] ?? null);
        $this->assertSame('{"ok":true,"url":"https://x/y"}', $res->body); // slashes stay raw (UNESCAPED_SLASHES)
    }

    public function test_json_post_with_header_token_round_trips_the_parsed_body(): void
    {
        $res = $this->client->postJson('/api/create', ['a' => 1, 'nested' => ['b' => true]],
            ['x-csrf-token' => $this->client->csrfToken()]);
        $this->assertSame(200, $res->status);
        $this->assertSame('application/json', $res->headers['Content-Type'] ?? null);
        $this->assertSame('{"received":{"a":1,"nested":{"b":true}}}', $res->body);
    }

    public function test_auth_json_post_guest_is_redirected_to_login(): void
    {
        $res = $this->client->postJson('/api/secret', ['x' => 1],
            ['x-csrf-token' => $this->client->csrfToken()]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location'] ?? null);
    }

    public function test_auth_json_post_logged_in_without_token_is_403(): void
    {
        $res = $this->client->actingAs(1)->postJson('/api/secret', ['x' => 1]);
        $this->assertSame(403, $res->status);
    }

    public function test_auth_json_post_with_header_token_is_200(): void
    {
        $res = $this->client->actingAs(1)->postJson('/api/secret', ['x' => 1],
            ['x-csrf-token' => $this->client->csrfToken()]);
        $this->assertSame(200, $res->status);
        $this->assertSame('{"secret":true}', $res->body);
    }

    public function test_malformed_json_body_with_a_valid_token_is_400(): void
    {
        $res = $this->client->actingAs(1)->postJson('/api/secret', '{"broken":',
            ['x-csrf-token' => $this->client->csrfToken()]);
        $this->assertSame(400, $res->status, 'CSRF passed via the header, the body guard fires');
    }

    public function test_malformed_json_body_without_a_token_is_still_403(): void
    {
        // Gate order pinned: CSRF runs before the body guard, so a tokenless
        // attacker learns nothing about the body's validity.
        $res = $this->client->actingAs(1)->postJson('/api/secret', '{"broken":');
        $this->assertSame(403, $res->status);
    }

    public function test_malformed_json_body_on_a_guest_json_route_is_400(): void
    {
        // The guest lane (no session, headerless server-to-server client) accepts
        // the request, and the guard still rejects the unparseable body.
        $res = $this->client->postJson('/api/create', '{"broken":');
        $this->assertSame(400, $res->status);
    }

    public function test_empty_json_body_passes_the_guard_to_the_action(): void
    {
        $res = $this->client->postJson('/api/create', '', ['x-csrf-token' => $this->client->csrfToken()]);
        $this->assertSame(200, $res->status); // an action may accept an empty body; json() is null
        $this->assertSame('{"received":null}', $res->body);
    }

    public function test_non_json_content_type_body_is_not_guarded(): void
    {
        // Scoped: the 400 is for JSON claims only. A form POST to a #[Json] route
        // keeps today's behavior (the action reads what it wants from the request).
        $res = $this->client->post('/api/create', ['a' => 'form'],
            ['x-csrf-token' => $this->client->csrfToken()]);
        $this->assertSame(200, $res->status);
        $this->assertSame('{"received":null}', $res->body); // no JSON body to parse
    }

    public function test_a_json_action_returning_a_response_passes_through_unwrapped(): void
    {
        $res = $this->client->get('/api/raw');
        $this->assertSame(201, $res->status);
        $this->assertSame('application/vnd.api+json', $res->headers['Content-Type'] ?? null);
        $this->assertSame('kept', $res->headers['X-Custom'] ?? null);
        $this->assertSame('{"custom":1}', $res->body);
    }
}
