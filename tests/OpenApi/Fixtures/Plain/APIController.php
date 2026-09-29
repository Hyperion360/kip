<?php // tests/OpenApi/Fixtures/Plain/APIController.php
namespace Kip\Tests\OpenApi\Fixtures\Plain;

final class APIController
{
    public function index(): array { return []; }  // acronym: the only segment that studly-round-trips is 'a-p-i'
}
