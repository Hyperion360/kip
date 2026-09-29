<?php // src/Routing/Auth.php

declare(strict_types=1);
namespace Kip\Routing;

// On a method, or on a controller class, a base class, an interface or a trait to
// require login for every action in it. With a policy name the route additionally
// runs that named closure (registered via App::policy()) after login; a policy
// that returns false is a 403, the user is logged in but not allowed.
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)] final class Auth
{
    /** @param ?string $policy a policy name registered with App::policy(), or null to gate login-only */
    public function __construct(public readonly ?string $policy = null) {}
}
