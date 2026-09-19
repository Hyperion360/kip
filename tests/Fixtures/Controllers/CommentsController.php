<?php // tests/Fixtures/Controllers/CommentsController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\{Database, Http\Request, Http\Response};
use Kip\Routing\Post;

final class CommentsController
{
    public function __construct(private Database $db, private Request $request) {}

    #[Post]
    public function store(string $postId): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$postId]);
        if ($post === null) return new Response('Post not found', 404);

        $author = $this->request->str('author');
        $body   = $this->request->str('body');
        if ($author === '' || $body === '') {
            return new Response('Author and comment are required.', 422);
        }

        $this->db->query('INSERT INTO comments (post_id, author, body, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $author, $body, date('c')]);
        return Response::redirect("/posts/show/{$postId}");
    }
}
