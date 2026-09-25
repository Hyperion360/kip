<?php // tests/Routing/Unimported/VaultController.php
// #[Auth] on the CLASS, the natural way to protect a whole controller. It must
// gate every action, not be silently ignored because the router only read
// method attributes.
namespace Kip\Tests\Routing\Unimported;

#[\Kip\Routing\Auth]
final class VaultController
{
    public function index(): string { return 'vault'; }
}
