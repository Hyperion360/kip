<?php // tests/Fixtures/Controllers/ApiController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Auth;
use Kip\Routing\Json;
use Kip\Routing\Post;
use Kip\Routing\Put;

final class ApiController
{
    public function __construct(private Request $request) {}

    /** TestClient plumbing: echoes the raw body back with a JSON content type. */
    #[Post]
    public function echoBody(): Response
    {
        return new Response($this->request->body, 200, ['Content-Type' => 'application/json']);
    }

    /** The kernel wraps the returned array; the URL pins UNESCAPED_SLASHES. */
    #[Json]
    public function list(): array
    {
        return ['ok' => true, 'url' => 'https://x/y'];
    }

    /** Reads the parsed body; the kernel wraps whatever comes back. */
    #[Post]
    #[Json]
    public function create(): array
    {
        return ['received' => $this->request->json()];
    }

    /** Every non-GET/HEAD verb counts against a rate-limited prefix. */
    #[Put]
    #[Json]
    public function replace(): array
    {
        return ['replaced' => true];
    }

    #[Post]
    #[Json]
    #[Auth]
    public function secret(): array
    {
        return ['secret' => true];
    }

    /** #[Json] never overrides a Response: the action keeps full control. */
    #[Json]
    public function raw(): Response
    {
        return new Response('{"custom":1}', 201, ['Content-Type' => 'application/vnd.api+json', 'X-Custom' => 'kept']);
    }
}
