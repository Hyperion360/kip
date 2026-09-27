<?php // tests/Routing/Unimported/SecretController.php
// Deliberately imports nothing from Kip\Routing. #[Auth] and #[Post] below
// therefore resolve to THIS namespace, and #[\Auth] to the global one; none of
// them is Kip\Routing\Auth. An app author who forgets the `use` line writes
// exactly this, and the route must still be gated.
namespace Kip\Tests\Routing\Unimported;

final class SecretController
{
    #[Auth] #[Post]
    public function wipe(): string { return 'wiped'; }

    #[\Auth]
    public function peek(): string { return 'peeked'; }

    public function open(): string { return 'open'; }

    // PHP resolves class names case-insensitively, so these must gate too.
    #[auth]
    public function lower(): string { return 'lower'; }

    #[\Kip\Routing\AUTH]
    public function upper(): string { return 'upper'; }

    #[post]
    public function lowverb(): string { return 'lowverb'; }
}
