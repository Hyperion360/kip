<?php // tests/Routing/Unimported/OverridingChildController.php
// Overrides a parent action that carries #[Auth] without repeating it.
namespace Kip\Tests\Routing\Unimported;

final class OverridingChildController extends GatedParentActions
{
    public function edit(): string { return 'child edit'; }
}
