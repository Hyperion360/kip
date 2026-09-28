<?php // tests/Routing/Fixtures.php
namespace Kip\Tests\Routing;
use Kip\Routing\{Post, Auth};

class PostsController
{
    public function index(): string { return 'list'; }
    public function show(string $id): string { return "show:$id"; }
    #[Post]
    public function store(): string { return 'stored'; }
    #[Auth]
    public function edit(string $id): string { return "edit:$id"; }
    /** Coercion guard: path segments are strings, this parameter is not. */
    public function rank(string $slug, int $position): string { return "rank:$slug:$position"; }
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
