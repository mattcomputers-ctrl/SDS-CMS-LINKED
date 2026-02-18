<?php

namespace SDS\Core;

/**
 * Router — Simple regex-based HTTP router.
 *
 * Supports GET/POST methods, named parameters ({id}), a global
 * middleware stack, and optional route-group prefixes.
 */
class Router
{
    /**
     * Registered routes grouped by HTTP method.
     * Each entry: ['pattern' => regex, 'handler' => string|callable, 'paramNames' => [...]]
     *
     * @var array<string, list<array>>
     */
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    /** @var list<callable> Global middleware stack */
    private array $middleware = [];

    /** @var string Active group prefix (set inside group() callback) */
    private string $groupPrefix = '';

    /* ------------------------------------------------------------------
     *  Route registration
     * ----------------------------------------------------------------*/

    /**
     * Register a GET route.
     *
     * @param string          $pattern  URI pattern (e.g. "/users/{id}/edit")
     * @param string|callable $handler  'Controller@method' or callable
     */
    public function get(string $pattern, string|callable $handler): self
    {
        $this->addRoute('GET', $pattern, $handler);
        return $this;
    }

    /**
     * Register a POST route.
     */
    public function post(string $pattern, string|callable $handler): self
    {
        $this->addRoute('POST', $pattern, $handler);
        return $this;
    }

    /**
     * Group routes under a shared URI prefix.
     *
     * @param string   $prefix   e.g. "/admin"
     * @param callable $callback Receives the Router instance
     */
    public function group(string $prefix, callable $callback): self
    {
        $previousPrefix   = $this->groupPrefix;
        $this->groupPrefix = $previousPrefix . $prefix;
        $callback($this);
        $this->groupPrefix = $previousPrefix;
        return $this;
    }

    /* ------------------------------------------------------------------
     *  Middleware
     * ----------------------------------------------------------------*/

    /**
     * Add a middleware to the global stack.
     *
     * A middleware is any callable with signature:
     *   function (callable $next): void
     *
     * @param callable $middleware
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /* ------------------------------------------------------------------
     *  Dispatch
     * ----------------------------------------------------------------*/

    /**
     * Match the incoming request and execute the handler.
     *
     * @param string $method  HTTP method (GET, POST, ...)
     * @param string $uri     Request URI (path only, no query string)
     */
    public function dispatch(string $method, string $uri): void
    {
        // Normalise: strip query string, ensure leading slash, remove trailing slash
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        $method = strtoupper($method);

        // Find matching route
        $matched  = null;
        $params   = [];

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $uri, $matches)) {
                $matched = $route;
                // Extract named parameters
                foreach ($route['paramNames'] as $name) {
                    $params[$name] = $matches[$name] ?? null;
                }
                break;
            }
        }

        if ($matched === null) {
            $this->sendNotFound();
            return;
        }

        // Build the final dispatch callable
        $handler = $matched['handler'];
        $dispatch = function () use ($handler, $params) {
            $this->callHandler($handler, $params);
        };

        // Wrap in middleware stack (outermost middleware runs first)
        $pipeline = $dispatch;
        foreach (array_reverse($this->middleware) as $mw) {
            $next     = $pipeline;
            $pipeline = function () use ($mw, $next) {
                $mw($next);
            };
        }

        $pipeline();
    }

    /* ------------------------------------------------------------------
     *  Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Store a route definition.
     */
    private function addRoute(string $method, string $pattern, string|callable $handler): void
    {
        $fullPattern = $this->groupPrefix . $pattern;

        // Normalise (keep leading slash, strip trailing)
        $fullPattern = '/' . trim($fullPattern, '/');
        if ($fullPattern !== '/') {
            $fullPattern = rtrim($fullPattern, '/');
        }

        // Extract parameter names and build regex
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $fullPattern);

        $regex = '#^' . $regex . '$#';

        $this->routes[$method][] = [
            'pattern'    => $fullPattern,
            'regex'      => $regex,
            'handler'    => $handler,
            'paramNames' => $paramNames,
        ];
    }

    /**
     * Resolve and invoke a handler with the matched route parameters.
     *
     * @param string|callable $handler  'ControllerClass@method' or callable
     * @param array           $params   Named route parameters
     */
    private function callHandler(string|callable $handler, array $params): void
    {
        if (is_callable($handler) && !is_string($handler)) {
            call_user_func_array($handler, array_values($params));
            return;
        }

        // "Controller@method" format
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            // If class name is not fully qualified, assume SDS\Controllers namespace
            if (!str_contains($class, '\\')) {
                $class = 'SDS\\Controllers\\' . $class;
            }

            if (!class_exists($class)) {
                throw new \RuntimeException("Controller class [{$class}] not found.");
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Method [{$method}] not found on [{$class}].");
            }

            call_user_func_array([$controller, $method], array_values($params));
            return;
        }

        throw new \RuntimeException('Invalid route handler format.');
    }

    /**
     * Send a 404 Not Found response.
     */
    private function sendNotFound(): void
    {
        http_response_code(404);

        $viewFile = dirname(__DIR__) . '/Views/errors/404.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<h1>404 — Page Not Found</h1>';
            echo '<p>The requested URL was not found on this server.</p>';
        }
    }
}
