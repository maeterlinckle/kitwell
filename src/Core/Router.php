<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareRunner;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:mixed,middleware:array<int,string>,name:?string}> */
    private array $routes = [];

    /** @var array<string,string> */
    private static array $named = [];

    /** @var array<int,string> */
    private array $groupMiddleware = [];

    /** @param array<int,string> $middleware */
    public function get(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function post(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function put(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $path, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function delete(string $path, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /**
     * Apply a set of middleware to every route registered inside the callback.
     *
     * @param array<int,string> $middleware
     */
    public function group(array $middleware, callable $callback): void
    {
        $previous              = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previous, $middleware);

        $callback($this);

        $this->groupMiddleware = $previous;
    }

    /** @param array<int,string> $middleware */
    private function add(string $method, string $path, mixed $handler, array $middleware, ?string $name): void
    {
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $path,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name'       => $name,
        ];

        if ($name !== null) {
            self::$named[$name] = $path;
        }
    }

    public static function path(string $name): string
    {
        return self::$named[$name] ?? '/';
    }

    public function dispatch(string $method, string $path): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);

            if ($params === null) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            Request::setRouteParams($params);

            MiddlewareRunner::run($route['middleware']);

            $this->call($route['handler'], $params);

            return;
        }

        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            http_response_code(405);
            View::renderError(405, 'Method not allowed', 'That action is not available on this page.');

            return;
        }

        http_response_code(404);
        View::renderError(404, 'Page not found', 'The page you were looking for does not exist.');
    }

    /**
     * @return array<string,string>|null Route parameters, or null when no match.
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        if (!str_contains($pattern, '{')) {
            return null;
        }

        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m): string {
                $name    = $m[1];
                $subject = $m[2] ?? '[^/]+';

                return '(?P<' . $name . '>' . $subject . ')';
            },
            $pattern
        );

        if (!is_string($regex)) {
            return null;
        }

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /** @param array<string,string> $params */
    private function call(mixed $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler(...array_values($params));

            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = new $class();
            $controller->{$method}(...array_values($params));

            return;
        }

        throw new \RuntimeException('Invalid route handler.');
    }
}
