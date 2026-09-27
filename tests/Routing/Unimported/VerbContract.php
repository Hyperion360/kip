<?php // tests/Routing/Unimported/VerbContract.php
namespace Kip\Tests\Routing\Unimported;

interface VerbContract
{
    #[\Kip\Routing\Delete]
    public function purge(): string;
}
