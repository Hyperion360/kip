<?php // tests/Fixtures/Controllers/UploadsController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Routing\Auth;
use Kip\Routing\Post;

final class UploadsController
{
    public function __construct(private Request $request) {}

    #[Auth] #[Post]
    public function store(): Response
    {
        $file = $this->request->file('doc');
        return new Response($file === null ? 'no-file' : 'got:' . $file['name'], $file === null ? 422 : 200);
    }
}
