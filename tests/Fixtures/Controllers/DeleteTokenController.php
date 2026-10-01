<?php // tests/Fixtures/Controllers/DeleteTokenController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Response;

/** TestClient jar plumbing: a real Max-Age=0 attribute, the deletion form. */
final class DeleteTokenController
{
    public function index(): Response
    {
        return (new Response('dt'))->withHeader('Set-Cookie', 'token=x; Path=/; Max-Age=0');
    }
}
