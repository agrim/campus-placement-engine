<?php

declare(strict_types=1);

namespace App\Api\Http;

/** Privacy-minimized entity tags derived only from the public representation. */
final class ApiRepresentationEtag
{
    /** @param array<string, mixed> $representation */
    public static function forRepresentation(array $representation): string
    {
        $encoded = json_encode(
            self::canonicalize($representation),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return '"' . hash('sha256', (string) $encoded) . '"';
    }

    public static function matchesIfNoneMatch(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate !== '' && hash_equals($etag, $candidate)) {
                return true;
            }
        }
        return false;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
