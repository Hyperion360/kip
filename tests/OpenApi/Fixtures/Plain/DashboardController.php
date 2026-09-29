<?php // tests/OpenApi/Fixtures/Plain/DashboardController.php
namespace Kip\Tests\OpenApi\Fixtures\Plain;

final class DashboardController
{
    public function index(string $filter): array { return []; }  // UNROUTABLE: the bare path under-supplies and /dashboard/index/... 404s (fold 4)
    public function listing(): array { return []; }              // GET /dashboard/listing
}
