<?php // tests/Routing/Unimported/GatedParentActions.php
// A parent whose edit() action is gated at method level (not class level).
namespace Kip\Tests\Routing\Unimported;

abstract class GatedParentActions
{
    #[\Kip\Routing\Auth]
    public function edit(): string { return 'parent edit'; }
    public function view(): string { return 'view'; }
}
