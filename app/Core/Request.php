<?php

namespace App\Core;

class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $input,
    ) {
    }

    public static function capture(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $input = $method === 'GET' ? $_GET : self::bodyInput();

        return new self($method, $path, $input);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return rtrim($this->path, '/') ?: '/';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->input;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $_SERVER[$key] ?? $default;
    }

    public function clientIp(): string
    {
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if (filter_var(getenv('TRUST_PROXY') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            $forwarded = trim(explode(',', (string) $this->header('X-Forwarded-For', ''))[0] ?? '');

            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }

        return filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : 'unknown';
    }

    private static function bodyInput(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }
}
