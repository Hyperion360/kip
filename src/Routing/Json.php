<?php // src/Routing/Json.php

declare(strict_types=1);
namespace Kip\Routing;

// On a method, or on a controller class, a base class, an interface or a trait to
// mark every action in it as a JSON endpoint: the kernel wraps a non-Response
// return value as application/json. CSRF and #[Auth] apply exactly as for HTML.
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)] final class Json {}
