<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /** @var null|callable(Request): Response */
    private $fallback = null;

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler): void
    {
        $this->routes['POST ' . $path] = $handler;
    }

    /** @param callable(Request): Response $handler */
    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method . ' ' . $request->path] ?? null;
        if ($handler === null) {
            if ($this->fallback !== null) {
                return ($this->fallback)($request);
            }
            return Response::html('<h1>Page not found</h1>', 404);
        }

        return $handler($request);
    }
}
