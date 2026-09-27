<?php // tests/Routing/Unimported/TraitGateController.php
namespace Kip\Tests\Routing\Unimported;

final class TraitGateController
{
    use GuardedTrait;
    public function index(): string { return 'trait-gated'; }
}
