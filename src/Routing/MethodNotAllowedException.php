<?php // src/Routing/MethodNotAllowedException.php  (review 9A: verb mismatch is 405, message = allowed verbs)
namespace Kip\Routing;

final class MethodNotAllowedException extends \RuntimeException
{
    /** @param bool $requiresAuth the route is #[Auth]: a guest gets the login redirect, not a 405 that confirms it exists */
    public function __construct(string $allowed, public readonly bool $requiresAuth = false)
    {
        parent::__construct($allowed);
    }
}
