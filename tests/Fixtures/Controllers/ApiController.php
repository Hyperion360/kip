<?php // tests/Fixtures/Controllers/ApiController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Post;

final class ApiController
{
    public function __construct(private Request $request) {}

    /** TestClient plumbing: echoes the raw body back with a JSON content type. */
    #[Post]
    public function echoBody(): Response
    {
        return new Response($this->request->body, 200, ['Content-Type' => 'application/json']);
    }
}
