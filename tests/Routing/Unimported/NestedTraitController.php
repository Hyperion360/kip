<?php // tests/Routing/Unimported/NestedTraitController.php
namespace Kip\Tests\Routing\Unimported;

final class NestedTraitController
{
    use NestedTrait;
    public function index(): string { return 'nested'; }
}
