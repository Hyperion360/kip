<?php // tests/Routing/LocalGate.php
namespace Kip\Tests\Routing\LocalGate;

// The short-name trust boundary meets policy extraction. This Auth attribute
// is app-local (never Kip's) and carries an argument shape that cannot name a
// policy: the route must fail loud at resolution instead of silently gating
// login-only while its author believes a policy is enforced.
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Auth
{
    public function __construct(public string $note = '') {}
}

final class NotesController
{
    #[Auth(note: 'kept')]
    public function index(): string { return 'notes'; }
}
