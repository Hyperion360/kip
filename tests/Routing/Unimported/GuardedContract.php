<?php // tests/Routing/Unimported/GuardedContract.php
// An interface carrying #[Auth] at class level. PHP does not copy it to
// implementers, so a router reading only the concrete class would leave
// implementing controllers public. (Method-level on a signature: GatedActions.)
namespace Kip\Tests\Routing\Unimported;

#[\Kip\Routing\Auth]
interface GuardedContract {}
