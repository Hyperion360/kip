<?php
namespace App\Controllers;
use Kip\{Auth, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Nav;

final class AuthController
{
    public function __construct(private Auth $auth, private View $view, private Session $session, private Request $request, private ?Database $db = null) {}

    public function login(): string
    {
        // The login form's token is unconditional, so it rides the action data:
        // with `+` it wins over Nav's logged-out null.
        return $this->view->render('auth/login', Nav::frame($this->session, $this->request, $this->db, 'Log in') + ['csrf' => $this->session->csrfToken(), 'error' => null]);
    }

    #[Post]
    public function attempt(): Response|string
    {
        $email = $this->request->postStr('email');
        if ($this->auth->throttled($email, $this->request->ip)) {
            return new Response(
                $this->view->render('auth/login', Nav::frame($this->session, $this->request, $this->db, 'Log in') + ['csrf' => $this->session->csrfToken(),
                    'error' => 'Too many attempts, try again in 15 minutes.']),
                429
            );
        }
        if ($this->auth->attempt($email, $this->request->postStr('password'), $this->request->ip)) {
            return Response::redirect('/posts');
        }
        return $this->view->render('auth/login', Nav::frame($this->session, $this->request, $this->db, 'Log in') + ['csrf' => $this->session->csrfToken(), 'error' => 'Wrong email or password']);
    }

    #[AuthAttr] #[Post]
    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }
}
