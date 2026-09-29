<?php // tests/Fixtures/Controllers/InheritshowController.php
namespace Kip\Tests\Fixtures\Controllers;

use Kip\Routing\Auth;

// Precedence fixture (closing-pass finding): the parent declares the method
// policy, the child stays silent on the method but narrows the class policy.
// The nearer declaration (the child class) must decide.
abstract class InheritPolicyBaseController
{
    #[Auth(policy: 'member')]
    public function index(): string
    {
        return 'parent-decision';
    }
}

#[Auth(policy: 'admin')]
final class InheritshowController extends InheritPolicyBaseController {}
