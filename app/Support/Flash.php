<?php

declare(strict_types=1);

namespace App\Support;

final class Flash
{
    public static function add(string $type, string $message, ?string $origin = null): void
    {
        $flash = ['type' => $type, 'message' => $message];
        // Application-authored action identity only; never submitted field values.
        if ($origin !== null) {
            $flash['origin'] = $origin;
        }
        $_SESSION['flash'][] = $flash;
    }

    /** @return array<int, array{type:string,message:string,origin?:string}> */
    public static function pull(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}
