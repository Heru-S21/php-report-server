<?php

namespace ReportingEngine\Core;

class Response
{
    private int $statusCode;
    private array $headers;
    private mixed $body;

    public function __construct(mixed $body = '', int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = array_merge([
            'Content-Type' => 'text/html; charset=utf-8',
        ], $headers);
    }

    public static function json(mixed $data, int $statusCode = 200, string $message = '', array $errors = []): self
    {
        $payload = [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'data' => $data,
        ];
        if ($message !== '') {
            $payload['message'] = $message;
        }
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        return new self(json_encode($payload, JSON_UNESCAPED_UNICODE), $statusCode, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function error(string $message, int $statusCode = 400, array $errors = []): self
    {
        return self::json(null, $statusCode, $message, $errors);
    }

    public static function view(string $viewPath, array $data = []): self
    {
        $viewFile = __DIR__ . '/../../views/' . $viewPath . '.php';
        if (!file_exists($viewFile)) {
            return self::error("View not found: {$viewPath}", 500);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewFile;
        $content = ob_get_clean();
        return new self($content);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if (is_string($this->body)) {
            echo $this->body;
        } elseif ($this->body !== null) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
        }
    }
}
