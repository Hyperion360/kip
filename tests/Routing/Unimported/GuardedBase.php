<?php // tests/Routing/Unimported/GuardedBase.php
// A base controller carrying the class-level #[Auth]. PHP does not inherit
// attributes, so a router reading only the concrete class would leave every
// subclass public.
namespace Kip\Tests\Routing\Unimported;

#[\Kip\Routing\Auth]
abstract class GuardedBase
{
    public function shared(): string { return 'shared'; }
}
