<?php // tests/Routing/Fixtures.php
namespace Kip\Tests\Routing;
use Kip\Routing\{Post, Auth, Json};

class PostsController
{
    public function index(): string { return 'list'; }
    public function show(string $id): string { return "show:$id"; }
    #[Post]
    public function store(): string { return 'stored'; }
    #[Auth]
    public function edit(string $id): string { return "edit:$id"; }
    #[Auth(policy: 'can-edit')]
    public function destroy(string $id): string { return "destroy:$id"; }
    #[Auth('positional')]
    public function purge(): string { return 'purged'; }
    /** Coercion guard: path segments are strings, this parameter is not. */
    public function rank(string $slug, int $position): string { return "rank:$slug:$position"; }
}

/** Class-level policy: every action is gated and carries the policy unless a nearer one names another. */
#[Auth(policy: 'admin-only')]
class PolicyPanelController
{
    public function index(): string { return 'panel'; }
    #[Auth(policy: 'override')]
    public function settings(): string { return 'settings'; }
    #[Auth] // bare: gates, but never clears the class policy
    public function reports(): string { return 'reports'; }
}

final class SubPanelController extends PolicyPanelController
{
    #[Auth(policy: 'narrower')]
    public function settings(): string { return 'sub settings'; }
}

final class TypoPolicyController
{
    /** The typo'd named argument is a loud misconfiguration, never a login-only fallback. */
    #[Auth(polciy: 'can-edit')]
    public function index(): string { return 'typo'; }
}

/** Same-named plain fixture for the feature-folder precedence test (review P2-1): the plain one wins. */
class BillingController
{
    public function index(): string { return 'plain billing'; }
}

final class HomeController
{
    public function index(): string { return 'home'; }
}

final class ReportController
{
    #[Json]
    public function stats(): array { return ['marked' => true]; }
    public function plain(): string { return 'plain'; }
}

#[Json]
final class CatalogController
{
    public function index(): array { return ['catalog' => true]; }
    public function item(string $id): array { return ['id' => $id]; }
}
