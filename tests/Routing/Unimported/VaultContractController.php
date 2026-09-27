<?php // tests/Routing/Unimported/VaultContractController.php
// Implements a class-level #[Auth] interface without repeating the attribute.
namespace Kip\Tests\Routing\Unimported;

final class VaultContractController implements GuardedContract
{
    public function index(): string { return 'contract'; }
}
