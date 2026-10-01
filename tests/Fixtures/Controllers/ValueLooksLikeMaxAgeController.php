<?php // tests/Fixtures/Controllers/ValueLooksLikeMaxAgeController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Response;

/** TestClient jar plumbing: a cookie whose VALUE contains the text
 *  Max-Age=0. Not a deletion: no real Max-Age attribute is present. */
final class ValueLooksLikeMaxAgeController
{
    public function index(): Response
    {
        return (new Response('vm'))->withHeader('Set-Cookie', 'token=Max-Age=0; Path=/');
    }
}
