<?php // tests/Fixtures/AppFeatures/Billing/BillingController.php
namespace App\Features\Billing;
use Kip\View;

/**
 * Fixture under the REAL default feature namespace (App\Features\, review P2-2):
 * require_once'd by AppTest, because the Kip\Tests\ PSR-4 root cannot autoload
 * an App\ class. Proves the App wiring turns the folder on with zero config.
 */
final class BillingController
{
    public function __construct(private View $view) {}
    public function invoice(string $id = 'none'): string
    {
        return $this->view->render('billing/invoice', ['id' => $id]);
    }
}
