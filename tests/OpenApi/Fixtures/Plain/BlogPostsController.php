<?php // tests/OpenApi/Fixtures/Plain/BlogPostsController.php
namespace Kip\Tests\OpenApi\Fixtures\Plain;
use Kip\Routing\Auth;
use Kip\Routing\Json;
use Kip\Routing\Post;
use Kip\Routing\Put;

final class BlogPostsController
{
    public function index(): array { return []; }                                          // GET /blog-posts
    public function show(string $slug, string $format = 'html'): array { return []; }     // GET /blog-posts/show/{slug} (optional param not appended)
    #[Post]
    public function store(): array { return []; }                                         // POST /blog-posts/store
    #[Auth]
    public function edit(string $id): array { return []; }                                // GET /blog-posts/edit/{id} + x-kip-auth
    #[Json]
    public function feed(): array { return []; }                                          // GET /blog-posts/feed, application/json
    #[Put]
    #[Json]
    public function replace(string $id): array { return []; }                             // PUT /blog-posts/replace/{id}, application/json
}
