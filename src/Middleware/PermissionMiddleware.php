<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class PermissionMiddleware
{
    public static function handle(string $permission): void
    {
        AuthMiddleware::handle();
        Auth::authorize($permission);
    }

    /** @param array<int,string> $permissions */
    public static function handleAny(array $permissions): void
    {
        AuthMiddleware::handle();

        $permissions = array_filter(array_map('trim', $permissions));

        if (Auth::canAny(...$permissions)) {
            return;
        }

        if (Request::isAjax()) {
            Response::json(['error' => 'You do not have permission to do that.'], 403);
        }

        View::renderError(403, 'Not permitted', 'You do not have permission to view that page. If you think you should, ask an administrator to review your role.');
        exit;
    }
}
