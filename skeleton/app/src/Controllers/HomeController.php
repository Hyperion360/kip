<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Session, View};

final class HomeController
{
    public function __construct(private View $view, private Session $session, private Request $request, private ?Database $db = null) {}

    public function index(): string
    {
        // Cookieless guests must never touch the session. A get() would start
        // one and set a cookie, making this page uncacheable for everyone.
        // Only a request already carrying a cookie can belong to a logged-in user.
        $loggedIn = $this->request->cookies !== [] && $this->session->get('user_id') !== null;
        // One column lookup, logged-in visitors only (guests stay zero-query and
        // cacheable): the nav needs to know whether to offer the admin panel.
        $isAdmin = false;
        if ($loggedIn && $this->db !== null) {
            $isAdmin = (bool) ($this->db->one(
                'SELECT is_admin FROM users WHERE id = ?',
                [$this->session->get('user_id')]
            )['is_admin'] ?? false);
        }
        return $this->view->render('home/index', [
            'title' => 'Welcome to Kip',
            'loggedIn' => $loggedIn,
            'isAdmin' => $isAdmin,
            'csrf' => $loggedIn ? $this->session->csrfToken() : null,
        ]);
    }
}
