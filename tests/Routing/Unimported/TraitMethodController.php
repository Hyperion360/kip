<?php // tests/Routing/Unimported/TraitMethodController.php
namespace Kip\Tests\Routing\Unimported;

final class TraitMethodController
{
    use GatedMethodTrait;
    public function open(): string { return 'open'; }
}
