<?php // tests/Fixtures/Controllers/AuthController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\{Auth, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AuthController
{
    public function __construct(private Auth $auth, private View $view, private Session $session, private Request $request) {}

    public function login(): string
    {
        return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(), 'error' => null]);
    }

    #[Post]
    public function attempt(): Response|string
    {
        $email = $this->request->postStr('email');
        if ($this->auth->throttled($email, $this->request->ip)) {
            return new Response(
                $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(),
                    'error' => 'Too many attempts, try again in 15 minutes.']),
                429
            );
        }
        if ($this->auth->attempt($email, $this->request->postStr('password'), $this->request->ip)) {
            return Response::redirect('/posts');
        }
        return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(), 'error' => 'Wrong email or password']);
    }

    #[AuthAttr] #[Post]
    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }
}
