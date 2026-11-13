<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class Router
{
    /** @var list<array{method:string,path:string,pattern:string,handler:callable}> */
    private array $routes = [];
    /** @var list<callable(Request,callable):Response> */
    private array $middleware = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        if (!preg_match('/^[A-Z]+$/D', $method) || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Invalid route.');
        }
        $parts = explode('/', $path);
        $names = [];
        foreach ($parts as &$part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $part, $match)) {
                if (isset($names[$match[1]])) {
                    throw new \InvalidArgumentException('Duplicate route parameter.');
                }
                $names[$match[1]] = true;
                $part = '(?P<' . $match[1] . '>[^/]+)';
            } else {
                if (strpbrk($part, '{}?#') !== false) {
                    throw new \InvalidArgumentException('Invalid route path.');
                }
                $part = preg_quote($part, '~');
            }
        }
        unset($part);
        $pattern = '~^' . implode('/', $parts) . '$~D';
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                throw new \LogicException('Duplicate route: ' . $method . ' ' . $path);
            }
        }
        $this->routes[] = compact('method', 'path', 'pattern', 'handler');
    }

    public function middleware(callable $middleware): void { $this->middleware[] = $middleware; }

    public function dispatch(string|Request $method, ?string $path = null): Response
    {
        $request = $method instanceof Request ? $method : new Request($method, $path ?? '/');
        $next = fn(Request $request): Response => $this->match($request);
        foreach (array_reverse($this->middleware) as $middleware) {
            $downstream = $next;
            $next = static fn(Request $request): Response => $middleware($request, $downstream);
        }
        return $next($request);
    }

    private function match(Request $request): Response
    {
        $allowed = [];
        $candidates = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path, $matches)) {
                continue;
            }
            $allowed[] = $route['method'];
            if ($route['method'] === 'GET') { $allowed[] = 'HEAD'; }
            if ($route['method'] === $request->method || ($request->method === 'HEAD' && $route['method'] === 'GET')) {
                $candidates[] = [$route, $matches];
            }
        }
        // Explicit HEAD and literal paths take precedence over fallbacks/parameters.
        usort($candidates, static fn(array $a, array $b): int => [substr_count($a[0]['path'], '{'), $a[0]['method'] === $request->method ? 0 : 1] <=> [substr_count($b[0]['path'], '{'), $b[0]['method'] === $request->method ? 0 : 1]);
        if ($candidates !== []) {
            [$route, $matches] = $candidates[0];
            $params = [];
            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $value = rawurldecode($value);
                    if ($value === '.' || $value === '..' || preg_match('/[\/\\\\\x00-\x1f\x7f]/', $value) || !preg_match('//u', $value)) {
                        throw new HttpException(400, 'invalid_path_parameter', 'Invalid route parameter.');
                    }
                    $params[$name] = $value;
                }
            }
            $result = ($route['handler'])($request->withParameters($params));
            if ($result instanceof Response) { return $result; }
            if (is_array($result)) { return new JsonResponse($result); }
            throw new \UnexpectedValueException('Route handler must return Response or array.');
        }
        if ($allowed === []) {
            throw new HttpException(404, 'not_found', 'Route not found.');
        }
        $allowed[] = 'OPTIONS';
        $allow = implode(', ', array_unique($allowed));
        if ($request->method === 'OPTIONS') {
            return new Response('', 204, ['Allow' => $allow]);
        }
        throw new HttpException(405, 'method_not_allowed', 'HTTP method not allowed.', ['Allow' => $allow]);
    }
}
