<?php // tests/Fixtures/Controllers/EtagPageController.php
namespace Kip\Tests\Fixtures\Controllers;

final class EtagPageController
{
    public function index(): \Kip\Http\Response
    {
        // Lowercase header name, and a quoted tag containing a comma: both exercise
        // the conditional-GET comparison path.
        return (new \Kip\Http\Response('ep'))->withHeader('etag', '"a,b"');
    }
}
