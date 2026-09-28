<?php // tests/Routing/Features/Billing/BillingController.php
namespace Kip\Tests\Routing\Features\Billing;

final class BillingController
{
    public function index(): string { return 'feature billing home'; }
    public function invoice(string $id = 'none'): string { return "feature invoice:{$id}"; }
}
