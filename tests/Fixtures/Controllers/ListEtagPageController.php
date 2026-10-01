<?php // tests/Fixtures/Controllers/ListEtagPageController.php
namespace Kip\Tests\Fixtures\Controllers;

/** Conditional-GET plumbing: a LIST-valued ETag. Both the miss path and the
 *  stored/hit path must use one validator, the first leaf. */
final class ListEtagPageController
{
    public function index(): \Kip\Http\Response
    {
        return (new \Kip\Http\Response('le'))->withHeader('ETag', ['"v1"', '"v2"']);
    }
}
