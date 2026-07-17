<?php

namespace App\Core;

class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf_token');

        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf_token', $token);
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): bool
    {
        return is_string($token) && hash_equals(self::token(), $token);
    }

    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::put('_csrf_token', $token);

        return $token;
    }
}
