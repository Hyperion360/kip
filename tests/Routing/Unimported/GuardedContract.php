<?php // tests/Routing/Unimported/GuardedContract.php
// An interface carrying #[Auth] at class level, and one method-level #[Auth] on
// a signature. PHP copies neither to implementers, so a router reading only the
// concrete class would leave implementing controllers public.
namespace Kip\Tests\Routing\Unimported;

#[\Kip\Routing\Auth]
interface GuardedContract {}
