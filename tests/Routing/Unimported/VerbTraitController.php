<?php // tests/Routing/Unimported/VerbTraitController.php
// Overrides a trait's POST action in the class body without repeating the attribute.
namespace Kip\Tests\Routing\Unimported;

final class VerbTraitController
{
    use VerbTrait;
    public function save(): string { return 'class save'; }
}
