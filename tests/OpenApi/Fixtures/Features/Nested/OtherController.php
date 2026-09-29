<?php // tests/OpenApi/Fixtures/Features/Nested/OtherController.php
namespace Kip\Tests\OpenApi\Fixtures\Features\Nested;

// DECOY: a nested <anything>/<Name>Controller.php where the directory name
// does not match is unroutable by the router's feature shape (fold 6); the
// file must never be required. The class exists only to look attractive.
final class OtherController
{
    public function index(): array { return []; }
}

throw new \RuntimeException('openapi discovery loaded the decoy Nested/OtherController.php');
