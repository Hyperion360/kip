<?php // tests/Fixtures/Controllers/TwoCookiesController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Response;
use Kip\Routing\Post;

/** TestClient plumbing: answers with a two-cookie redirect, the multi-value
 *  Set-Cookie shape withAddedHeader builds. */
final class TwoCookiesController
{
    #[Post]
    public function index(): Response
    {
        return Response::redirect('/')
            ->withAddedHeader('Set-Cookie', 'ja=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'jb=2; Path=/');
    }
}
