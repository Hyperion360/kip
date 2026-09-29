<?php // tests/Fixtures/Controllers/PolicyController.php
namespace Kip\Tests\Fixtures\Controllers;

use Kip\Routing\Auth;
use Kip\Routing\Json;
use Kip\Routing\Post;

// G2 fixture: every action is gated by #[Auth] naming the 'can-edit' policy.
// The static proves a denied action never runs.
final class PolicyController
{
    public static bool $ran = false;

    #[Auth(policy: 'can-edit')]
    public function edit(): string
    {
        self::$ran = true;
        return 'edited';
    }

    #[Auth(policy: 'can-edit')]
    #[Json]
    public function stats(): array
    {
        return ['edited' => true];
    }

    #[Auth(policy: 'can-edit')]
    #[Post]
    public function save(): string
    {
        self::$ran = true;
        return 'saved';
    }
}
