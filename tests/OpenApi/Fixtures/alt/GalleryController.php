<?php // tests/OpenApi/Fixtures/alt/GalleryController.php
namespace Kip\Tests\OpenApi\Fixtures\Shadow;

// Second controller of the shadow source: unique segment, so it IS documented.
final class GalleryController
{
    public function index(): array { return []; }  // GET /gallery
}
