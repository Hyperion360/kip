<?php // tests/Routing/Unimported/ContractActionsController.php
// secret() is gated on the interface signature only; open() is not gated anywhere.
namespace Kip\Tests\Routing\Unimported;

final class ContractActionsController implements GatedActions
{
    public function secret(): string { return 'secret'; }
    public function open(): string { return 'open'; }
}
