<?php // tests/OpenApi/Fixtures/alt/BlogPostsController.php
namespace Kip\Tests\OpenApi\Fixtures\Shadow;

// Same URL segment ('blog-posts') as Plain/BlogPostsController: the FIRST
// source claiming a segment wins and this controller is skipped entirely,
// extra actions included (fold 6). The file lives in alt/, off the PSR-4
// path, so only directory-driven discovery can ever find it.
final class BlogPostsController
{
    public function index(): array { return []; }
    public function extra(): array { return []; }  // must never appear in the document
}
