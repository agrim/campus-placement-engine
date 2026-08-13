<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Csv
{
    /** @param resource $handle */
    public static function writeRow(mixed $handle, array $row): void
    {
        $safe = array_map(self::safeCell(...), $row);
        if (fputcsv($handle, $safe, ',', '"', '') === false) {
            throw new RuntimeException('Could not write CSV row.');
        }
    }

    public static function safeCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (preg_match('/^(?:[\t\r]|\s*[=+\-@])/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }
}
