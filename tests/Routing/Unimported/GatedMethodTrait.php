<?php // tests/Routing/Unimported/GatedMethodTrait.php
// #[Auth] on a trait METHOD: PHP reports it on the using class's method.
namespace Kip\Tests\Routing\Unimported;

trait GatedMethodTrait
{
    #[\Kip\Routing\Auth]
    public function secret(): string { return 'secret'; }
}
