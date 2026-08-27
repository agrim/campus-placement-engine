<?php

declare(strict_types=1);

namespace App\Security;

final class OperationalBearerAuthorization
{
    public const MINIMUM_TOKEN_LENGTH = 24;

    public static function authorizes(array $server, string $configuredToken): bool
    {
        if (strlen($configuredToken) < self::MINIMUM_TOKEN_LENGTH) {
            return false;
        }

        $authorization = $server['HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($authorization)) {
            return false;
        }
        $authorization = trim($authorization);
        if (!str_starts_with($authorization, 'Bearer ')) {
            return false;
        }

        $providedToken = substr($authorization, 7);
        return hash_equals(
            hash('sha256', $configuredToken, true),
            hash('sha256', $providedToken, true),
        );
    }
}
