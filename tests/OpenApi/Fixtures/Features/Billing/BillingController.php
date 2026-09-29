<?php // tests/OpenApi/Fixtures/Features/Billing/BillingController.php
namespace Kip\Tests\OpenApi\Fixtures\Features\Billing;

// Feature-shaped: <Name>/<Name>Controller.php at depth one, class name
// <FeatureNs><Name>\<Name>Controller, the router's feature resolution.
final class BillingController
{
    public function index(): array { return []; }             // GET /billing
    public function invoice(string $id): array { return []; } // GET /billing/invoice/{id}
}
