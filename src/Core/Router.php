<?php

namespace ReportingEngine\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];

    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    private function addRoute(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $method = strtoupper($method);
        $pattern = '/' . trim($pattern, '/');
        $paramNames = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'paramNames' => $paramNames,
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $uri = $request->uri;
        $method = $request->method;

        // Handle OPTIONS preflight
        if ($method === 'OPTIONS') {
            return new Response('', 204, [
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            ]);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $uri, $matches)) {
                continue;
            }

            array_shift($matches);
            foreach ($route['paramNames'] as $i => $name) {
                $request->params[$name] = isset($matches[$i]) ? urldecode($matches[$i]) : null;
            }

            // Run global middleware
            foreach ($this->middleware as $mw) {
                $result = $mw($request);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            // Run route-specific middleware
            foreach ($route['middleware'] as $mw) {
                $result = $mw($request);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $method] = $handler;
                if (!class_exists($class)) {
                    return Response::error("Controller not found: {$class}", 500);
                }
                $controller = new $class();
                if (!method_exists($controller, $method)) {
                    return Response::error("Method not found: {$class}::{$method}", 500);
                }
                return $controller->$method($request);
            }
            return $handler($request);
        }

        return Response::error('Not Found', 404);
    }
}
