<?php // tests/Routing/Unimported/VerbParentActions.php
// A parent whose actions restrict their verbs, for overrides that do not repeat them.
namespace Kip\Tests\Routing\Unimported;

abstract class VerbParentActions
{
    #[\Kip\Routing\Post]
    public function store(): string { return 'parent store'; }
    #[\Kip\Routing\Post]
    public function publish(): string { return 'parent publish'; }
}
