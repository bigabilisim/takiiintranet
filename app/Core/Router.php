<?php

namespace App\Core;

class Router
{
    private array $routes = [];
    private mixed $notFound = null;

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes[$request->method()] ?? [] as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $result = call_user_func($route['handler'], $request, ...$matches);

            return $result instanceof Response ? $result : new Response((string) $result);
        }

        if ($this->notFound !== null) {
            return call_user_func($this->notFound, $request);
        }

        return new Response('Not found', 404);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '([^/]+)', $path);
        $pattern = rtrim((string) $pattern, '/') ?: '/';
        $this->routes[$method][] = [
            'path' => $path,
            'regex' => '#^' . $pattern . '$#',
            'handler' => $handler,
        ];
    }
}
