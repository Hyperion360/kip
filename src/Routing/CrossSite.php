<?php // src/Routing/CrossSite.php

declare(strict_types=1);
namespace Kip\Routing;

// On a guest action that legitimately receives cross-site POSTs and proves
// them itself: an OAuth callback whose provider form-posts the authorization
// code back carries a session-bound, single-use `state` the action verifies
// with hash_equals, so the kernel's same-origin proof (Sec-Fetch-Site, Origin,
// Referer) would only reject the one real caller. The kernel skips that proof
// for the marked action. Nothing else relaxes: an #[Auth] action keeps its
// mandatory CSRF token whatever this attribute says, and the action still owns
// its own replay defense. Read from the whole declaration hierarchy like the
// other routing attributes.
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)] final class CrossSite {}
