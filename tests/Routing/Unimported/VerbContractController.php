<?php // tests/Routing/Unimported/VerbContractController.php
// Implements an interface-declared DELETE action without repeating the attribute.
namespace Kip\Tests\Routing\Unimported;

final class VerbContractController implements VerbContract
{
    public function purge(): string { return 'purged'; }
}
