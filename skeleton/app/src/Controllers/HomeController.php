<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\{Http\Request, Session, View};

final class HomeController
{
    public function __construct(private View $view, private Session $session, private Request $request) {}

    public function index(): string
    {
        // Cookieless guests must never touch the session. A get() would start
        // one and set a cookie, making this page uncacheable for everyone.
        // Only a request already carrying a cookie can belong to a logged-in user.
        $loggedIn = $this->request->cookies !== [] && $this->session->get('user_id') !== null;
        return $this->view->render('home/index', [
            'title' => 'Welcome to Kip',
            'loggedIn' => $loggedIn,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
