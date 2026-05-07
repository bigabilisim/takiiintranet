<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
    ) {
    }

    public function showLogin(): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }

        return new Response($this->view->render('auth/login', ['title' => 'auth.login']));
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
}
