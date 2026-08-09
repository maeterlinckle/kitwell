<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        $expired = Session::pull('__expired', false);

        View::render('auth/login', [
            'pageTitle' => 'Sign in',
            'expired'   => (bool) $expired,
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $email    = (string) Request::post('email', '');
        $password = (string) ($_POST['password'] ?? '');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => 'required|email|max:190', 'password' => 'required|max:255'],
            ['email' => 'Email address', 'password' => 'Password']
        );

        if ($validator->failed()) {
            Flash::errors($validator->errors());
            Flash::old(['email' => $email]);
            Response::redirect('/login');
        }

        $error = Auth::attempt($email, $password);

        if ($error !== null) {
            Flash::error($error);
            Flash::old(['email' => $email]);

            $remaining = LoginThrottle::remaining($email, Request::ip());
            if ($remaining > 0 && $remaining <= 2) {
                Flash::warning("{$remaining} attempt(s) left before this account is locked for a while.");
            }

            Response::redirect('/login');
        }

        Flash::success('Welcome back, ' . Auth::name() . '.');

        $intended = Session::pull('__intended_url');
        Response::redirect(is_string($intended) && $intended !== '' ? $intended : '/');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        Flash::info('You have been signed out.');

        Response::redirect('/login');
    }
}
