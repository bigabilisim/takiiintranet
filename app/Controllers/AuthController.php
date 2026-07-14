<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\PasswordResetStore;

class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly PasswordResetStore $passwordResets,
    ) {
    }

    public function showLogin(): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }

        return new Response($this->view->render('auth/login', ['title' => 'auth.login']));
    }

    public function showForgotPassword(): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }

        return new Response($this->view->render('auth/forgot-password', ['title' => 'auth.forgot_title']));
    }

    public function requestPasswordReset(Request $request): Response
    {
        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/forgot-password');
        }

        $this->passwordResets->request(
            trim((string) $request->input('email')),
            $this->baseUrl()
        );
        Session::flash('success', 'auth.password_reset.requested');

        return Response::redirect('/forgot-password');
    }

    public function showResetPassword(Request $request, string $token): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }

        return new Response($this->view->render('auth/reset-password', [
            'title' => 'auth.reset_title',
            'token' => $token,
            'resetRecord' => $this->passwordResets->validateToken($token),
        ]));
    }

    public function resetPassword(Request $request): Response
    {
        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/forgot-password');
        }

        $token = trim((string) $request->input('token'));
        $result = $this->passwordResets->reset(
            $token,
            (string) $request->input('password'),
            (string) $request->input('password_confirmation')
        );
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $result['ok']
            ? Response::redirect('/login')
            : Response::redirect($token !== '' ? '/password-reset/' . urlencode($token) : '/forgot-password');
    }

    public function login(Request $request): Response
    {
        if (!Csrf::validate($request->input('_token'))) {
            Session::flash('error', 'security.invalid_session');

            return Response::redirect('/login');
        }

        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if (!$this->auth->attempt($email, $password)) {
            Session::flash('error', 'auth.failed');

            return Response::redirect('/login');
        }

        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        if (Csrf::validate($request->input('_token'))) {
            $this->auth->logout();
        }

        return Response::redirect('/login');
    }

    private function baseUrl(): string
    {
        $configured = trim((string) getenv('APP_URL'));

        if ($configured !== '') {
            return $configured;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host !== '' ? $scheme . '://' . $host : 'http://127.0.0.1:8080';
    }
}
