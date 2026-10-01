<?php // tests/Fixtures/Controllers/EchoCookiesController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Http\Request;
use Kip\Http\Response;

/** TestClient plumbing: renders the cookies the request presented into the
 *  body, so a jar round-trip is assertable. */
final class EchoCookiesController
{
    public function __construct(private Request $request) {}

    public function index(): Response
    {
        $pairs = [];
        foreach ($this->request->cookies as $name => $value) {
            $pairs[] = $name . '=' . (is_string($value) ? $value : json_encode($value));
        }
        return new Response(implode(' ', $pairs), 200, ['Content-Type' => 'text/plain']);
    }
}
