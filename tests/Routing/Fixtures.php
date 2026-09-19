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
}
