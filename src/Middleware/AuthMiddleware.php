<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthMiddleware
{
    public static function handle(): void
    {
        if (Auth::check()) {
            return;
        }

        if (Request::isAjax()) {
            Response::json(['error' => 'Not signed in.'], 401);
        }

        // Remember where they were headed so login can send them back.
        if (Request::method() === 'GET') {
            Session::put('__intended_url', Request::path());
        }

        Response::redirect('/login');
    }
}
