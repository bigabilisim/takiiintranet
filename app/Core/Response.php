<?php

namespace App\Core;

class Response
{
    public function __construct(
        private readonly string $content = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    public static function redirect(string $path): self
    {
        return new self('', 302, ['Location' => $path]);
    }

    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function send(): void
    {
        http_response_code($this->status);

        $headers = array_merge([
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; manifest-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], $this->headers);

        if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || str_starts_with(strtolower((string) getenv('APP_URL')), 'https://')) {
            $headers['Strict-Transport-Security'] ??= 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->content;
    }
}
