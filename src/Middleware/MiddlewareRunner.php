<?php

declare(strict_types=1);

namespace App\Middleware;

use RuntimeException;

/**
 * Resolves middleware names used in the route table.
 *
 *   'auth'                 must be signed in
 *   'guest'                must NOT be signed in
 *   'can:assets.edit'      must hold the permission (implies auth)
 *   'canany:a.b,c.d'       must hold at least one of the permissions
 *   'csrf'                 verify the CSRF token on state-changing requests
 */
final class MiddlewareRunner
{
    /** @param array<int,string> $middleware */
    public static function run(array $middleware): void
    {
        foreach ($middleware as $definition) {
            $name      = $definition;
            $parameter = null;

            if (str_contains($definition, ':')) {
                [$name, $parameter] = explode(':', $definition, 2);
            }

            match ($name) {
                'auth'   => AuthMiddleware::handle(),
                'guest'  => GuestMiddleware::handle(),
                'can'    => PermissionMiddleware::handle((string) $parameter),
                'canany' => PermissionMiddleware::handleAny(explode(',', (string) $parameter)),
                'csrf'   => CsrfMiddleware::handle(),
                default  => throw new RuntimeException('Unknown middleware: ' . $name),
            };
        }
    }
}
