<?php // tests/Fixtures/Controllers/LowercaseCookieController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Response;

/** TestClient jar plumbing: a Set-Cookie spelled in lowercase, legal per
 *  RFC 9110 5.1 (field names are case-insensitive). */
final class LowercaseCookieController
{
    public function index(): Response
    {
        return (new Response('lc'))->withHeader('set-cookie', 'lc=1; Path=/');
    }
}
