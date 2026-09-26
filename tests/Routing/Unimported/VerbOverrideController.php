<?php // tests/Routing/Unimported/VerbOverrideController.php
// store() drops the parent's #[Post]; publish() replaces it with an explicit verb.
namespace Kip\Tests\Routing\Unimported;

final class VerbOverrideController extends VerbParentActions
{
    public function store(): string { return 'child store'; }
    #[\Kip\Routing\Put]
    public function publish(): string { return 'child publish'; }
}
