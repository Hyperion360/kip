<?php
namespace App\Controllers;
use Kip\{App, Auth, Mailer, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

final class AuthController
{
    public function __construct(
        private Auth $auth,
        private View $view,
        private Session $session,
        private Request $request,
        private Mailer $mailer,
        private App $app,
    ) {}

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
            return Response::redirect('/');
        }
        return $this->view->render('auth/login', ['title' => 'Log in', 'csrf' => $this->session->csrfToken(), 'error' => 'Wrong email or password']);
    }

    #[AuthAttr] #[Post]
    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }

    public function forgot(): string
    {
        return $this->view->render('auth/forgot', ['title' => 'Reset password', 'sent' => false]);
    }

    #[Post]
    public function remind(): string
    {
        $email = $this->request->postStr('email');
        $token = $this->auth->createReset($email, $this->request->ip);
        if ($token !== null) {
            $url = rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/') . "/auth/reset/{$token}";
            try {
                $this->mailer->send($email, 'Reset your password',
                    "Someone (hopefully you) asked to reset the password for this address.\n\n"
                    . "Reset link (valid 30 minutes):\n{$url}\n\nIf this wasn't you, ignore this email.");
            } catch (\Throwable $e) {
                // A mailer failure must not become an account-existence oracle: the page
                // is identical either way, and the failure lands in the server log.
                error_log("Password-reset mail failed for a known address: {$e->getMessage()}");
            }
        }
        // Same page whether the account exists or not, no enumeration.
        return $this->view->render('auth/forgot', ['title' => 'Reset password', 'sent' => true]);
    }

    public function reset(string $token): string
    {
        return $this->resetView($token, null);
    }

    #[Post]
    public function confirm(): Response|string
    {
        $token = $this->request->postStr('token');
        $password = $this->request->postStr('password');
        if (strlen($password) < 8) {
            return new Response($this->resetView($token, 'Password must be at least 8 characters.'), 422);
        }
        if (str_contains($password, "\0")) { // password_hash() throws on it: a 500, not a form error
            return new Response($this->resetView($token, 'Password cannot contain a NUL byte.'), 422);
        }
        if (!$this->auth->resetPassword($token, $password)) {
            return new Response($this->resetView($token, 'That reset link is invalid or has expired, request a new one.'), 422);
        }
        return Response::redirect('/auth/login');
    }

    private function resetView(string $token, ?string $error): string
    {
        return $this->view->render('auth/reset', ['title' => 'Choose a new password', 'token' => $token, 'error' => $error]);
    }
}
