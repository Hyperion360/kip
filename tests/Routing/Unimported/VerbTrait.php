<?php // tests/Routing/Unimported/VerbTrait.php
namespace Kip\Tests\Routing\Unimported;

trait VerbTrait
{
    #[\Kip\Routing\Post]
    public function save(): string { return 'trait save'; }
}
