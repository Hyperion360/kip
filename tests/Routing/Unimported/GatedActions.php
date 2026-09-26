<?php // tests/Routing/Unimported/GatedActions.php
// Declares the gated action on the interface only; the implementer's own
// method carries no attribute.
namespace Kip\Tests\Routing\Unimported;

interface GatedActions
{
    #[\Kip\Routing\Auth]
    public function secret(): string;
}
