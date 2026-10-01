<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\{Database, Session, Http\Request, View};
use App\Nav;

final class HomeController
{
    public function __construct(private View $view, private Session $session, private Request $request, private ?Database $db = null) {}

    public function index(): string
    {
        return $this->view->render('home/index', Nav::frame($this->session, $this->request, $this->db, 'My Blog'));
    }
}
