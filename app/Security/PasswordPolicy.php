<?php

declare(strict_types=1);

namespace App\Security;

/** PASSWORD_DEFAULT uses bcrypt, which authenticates at most 72 bytes. */
final class PasswordPolicy
{
    public static function allows(string $password): bool
    {
        return strlen($password) >= 8 && self::canVerify($password);
    }

    public static function canVerify(string $password): bool
    {
        // Preserve existing short credentials; the creation minimum applies
        // when setting a password, not when verifying a legacy hash.
        return strlen($password) <= 72 && !str_contains($password, "\0");
    }
}
