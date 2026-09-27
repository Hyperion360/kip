<?php // tests/Routing/Unimported/NestedTrait.php
// An unattributed trait that pulls in the guarded one: the gate must follow.
namespace Kip\Tests\Routing\Unimported;

trait NestedTrait
{
    use GuardedTrait;
}
