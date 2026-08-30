<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use RuntimeException;

final class WebhookHttpResult
{
    public function __construct(public readonly int $statusCode)
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new RuntimeException('Webhook HTTP status is invalid.');
        }
    }
}
