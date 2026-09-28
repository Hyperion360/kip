<?php // tests/Fixtures/Controllers/WeakEtagPageController.php
namespace Kip\Tests\Fixtures\Controllers;

final class WeakEtagPageController
{
    public function index(): \Kip\Http\Response
    {
        return (new \Kip\Http\Response('wp'))->withHeader('ETag', 'W/"v1"');
    }
}
