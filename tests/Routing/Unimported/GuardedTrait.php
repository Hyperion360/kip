<?php // tests/Routing/Unimported/GuardedTrait.php
// A trait carrying class-level #[Auth]. PHP does not report a trait's own
// attributes on the class that uses it, so the router must look at the trait.
namespace Kip\Tests\Routing\Unimported;

#[\Kip\Routing\Auth]
trait GuardedTrait
{
    public function shared(): string { return 'shared'; }
}
