<?php

namespace ReportingEngine\Core;

class Request
{
    public string $method;
    public string $uri;
    public array $query;
    public array $body;
    public array $files;
    public array $headers;
    public array $params = [];

    private function __construct(array $server, array $get, array $post, array $files)
    {
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $scriptName = $server['SCRIPT_NAME'] ?? '/';
        $basePath = dirname($scriptName);
        // Only strip basePath when SCRIPT_NAME is a PHP entry point.
        // The PHP built-in server incorrectly sets SCRIPT_NAME to the URI
        // path (not the router script) when dots are in the URL, which would
        // corrupt the URI. In that case use the full URI.
        $this->uri = $basePath !== '/' && $basePath !== '.' && str_ends_with($scriptName, '.php') && str_starts_with($uri, $basePath)
            ? '/' . ltrim(substr($uri, strlen($basePath)), '/')
            : $uri;
        if ($this->uri === '' || $this->uri === false) {
            $this->uri = '/';
        }
        $this->query = $get;
        $this->body = $this->parseBody($post, $server);
        $this->files = $files;
        $this->headers = $this->parseHeaders($server);
    }

    private function parseBody(array $post, array $server): array
    {
        $contentType = $server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $post;
    }

    private function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }
        return $headers;
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES);
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $this->query[$key] ?? $default;
    }

    public function getBody(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }
}
