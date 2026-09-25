<?php // src/Routing/Auth.php
namespace Kip\Routing;

// On a method, or on a controller class (or a base class it extends) to require
// login for every action in it.
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)] final class Auth {}
