<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\{Database, Session, Http\Request, View};
use App\Nav;

final class HomeController
{
    public function __construct(private View $view, private Session $session, private Request $request, private ?Database $db = null) {}

    public function index(): string
    {
        $latest = $this->db === null ? [] : $this->db->all(
            'SELECT id, title, body, created_at FROM posts ORDER BY created_at DESC, id DESC LIMIT 3'
        );
        return $this->view->render('home/index', Nav::frame($this->session, $this->request, $this->db, 'My Blog') + ['posts' => $latest]);
    }
}
