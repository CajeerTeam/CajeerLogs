<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class Router
{
    /** @var array<string, callable> */
private array $routes = [];

public function add(string $method, string $path, callable $handler): void
{
    $this->routes[strtoupper($method) . ' ' . $path] = $handler;
}

public function dispatch(string $method, string $path): mixed
{
    $key = strtoupper($method) . ' ' . $path;
    if (!isset($this->routes[$key])) {
        return ['error' => 'route_not_found', 'path' => $path];
    }
    return ($this->routes[$key])();
}
}
