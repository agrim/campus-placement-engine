<?php

declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function input(): string
    {
        return '<input type="hidden" name="_token" value="' . h(self::token()) . '">';
    }

    public static function verify(?string $token): void
    {
        if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            throw new \RuntimeException('Security token mismatch. Please retry.');
        }
    }

    public static function rotate(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
