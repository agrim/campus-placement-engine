<?php

declare(strict_types=1);

namespace App\Integrations;

use RuntimeException;

/** Institution-visible integration lifecycle vocabulary. */
final class IntegrationState
{
    private const LABELS = [
        'disabled' => 'Disabled',
        'setup_required' => 'Setup required',
        'validating' => 'Validating',
        'active' => 'Active',
        'degraded' => 'Degraded',
    ];

    public static function label(string $state): string
    {
        if (!isset(self::LABELS[$state])) {
            throw new RuntimeException('Unsupported institution-visible integration state.');
        }
        return self::LABELS[$state];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }
}
