<?php // tests/Fixtures/Controllers/LongMaxAgeController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Response;

/** TestClient jar plumbing: Max-Age=10, which must not substring-match the
 *  Max-Age=0 deletion test. */
final class LongMaxAgeController
{
    public function index(): Response
    {
        return (new Response('lm'))->withHeader('Set-Cookie', 'token=x; Path=/; Max-Age=10');
    }
}
