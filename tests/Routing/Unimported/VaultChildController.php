<?php // tests/Routing/Unimported/VaultChildController.php
// Extends a #[Auth] base without repeating the attribute. Its own actions and
// the ones it inherits must both stay gated.
namespace Kip\Tests\Routing\Unimported;

final class VaultChildController extends GuardedBase
{
    public function index(): string { return 'child'; }
}
