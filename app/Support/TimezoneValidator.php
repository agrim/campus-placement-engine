<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use RuntimeException;

final class TimezoneValidator
{
    public static function normalize(string $value, string $default = 'UTC', string $label = 'Timezone'): string
    {
        $value = trim($value) ?: $default;
        if (!in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new RuntimeException($label . ' must be a valid IANA timezone such as Asia/Kolkata.');
        }
        return $value;
    }
}
