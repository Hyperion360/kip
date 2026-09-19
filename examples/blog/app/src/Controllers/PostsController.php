<?php // app/src/Controllers/PostsController.php
namespace App\Controllers;
use Kip\Database;
use Kip\Http\Response;
use Kip\View;
use Kip\Routing\{Auth, Post};
use Kip\{Session, Http\Request};

final class PostsController
{
    private const PER_PAGE = 20;

    public function __construct(private Database $db, private View $view, private Session $session, private Request $request) {}

    public function index(): string
    {
        $page = max(1, (int) $this->request->str('page'));
        $posts = $this->db->all(
            'SELECT * FROM posts ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE]     // fetch one extra: cheap has-next probe, no COUNT(*)
        );
        $hasNext = count($posts) > self::PER_PAGE;
        $posts = array_slice($posts, 0, self::PER_PAGE);
        return $this->view->render('posts/index', ['title' => 'Posts', 'posts' => $posts, 'page' => $page, 'hasNext' => $hasNext]);
    }

    public function show(string $id): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post === null) return new Response('Post not found', 404);
        $comments = $this->db->all('SELECT * FROM comments WHERE post_id = ? ORDER BY created_at, id', [$id]);
        return $this->view->render('posts/show', [
            'title' => $post['title'],
            'post' => $post,
            'comments' => $comments,
        ]);
    }

    #[Auth]
    public function create(): string
    {
        return $this->view->render('posts/edit', ['title' => 'New post', 'post' => ['id' => null, 'title' => '', 'body' => ''], 'csrf' => $this->session->csrfToken()]);
    }

    #[Auth] #[Post]
    public function store(): Response
    {
        $this->db->query('INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
            [$this->request->str('title'), $this->request->str('body'), date('c')]);
        return Response::redirect('/posts/show/' . $this->db->lastInsertId());
    }

    #[Auth]
    public function edit(string $id): Response|string
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post === null) return new Response('Post not found', 404);
        return $this->view->render('posts/edit', ['title' => 'Edit post', 'post' => $post, 'csrf' => $this->session->csrfToken()]);
    }

    #[Auth] #[Post]
    public function update(string $id): Response
    {
        $this->db->query('UPDATE posts SET title = ?, body = ? WHERE id = ?',
            [$this->request->str('title'), $this->request->str('body'), $id]);
        return Response::redirect("/posts/show/{$id}");
    }
}
