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
        // New keys ride the action data (`+` keeps Nav's values on conflicts).
        // The login form's _token renders empty for guests on purpose: Nav
        // stays zero-query, and /auth/attempt is protected by the kernel's
        // same-origin proof, not by a session token.
        return $this->view->render('auth/login', Nav::frame($this->session, $this->request, $this->db, 'Log in') + ['csrf' => $this->session->csrfToken(), 'error' => null, 'email' => '', 'narrow' => true]);
    }

    #[Post]
    public function attempt(): Response|string
    {
        $email = $this->request->postStr('email');
        if ($this->auth->throttled($email, $this->request->ip)) {
            return new Response(
                $this->view->render('auth/login', Nav::frame($this->session, $this->request, $this->db, 'Log in') + ['csrf' => $this->session->csrfToken(), 'error' => 'Too many attempts, try again in 15 minutes.', 'email' => $email, 'narrow' => true]),
                429
            );
        }
        if ($this->auth->attempt($email, $this->request->postStr('password'), $this->request->ip)) {
            return Response::redirect('/posts');
        }
        return $this->view->render('auth/login', Nav::frame($this->session, $this->request, $this->db, 'Log in') + ['csrf' => $this->session->csrfToken(), 'error' => 'Wrong email or password', 'email' => $email, 'narrow' => true]);
    }

    #[AuthAttr] #[Post]
    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }
}
