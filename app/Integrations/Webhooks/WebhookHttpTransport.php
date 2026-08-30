<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

interface WebhookHttpTransport
{
    /** @param list<string> $headers */
    public function send(
        string $endpointUrl,
        string $body,
        array $headers,
        bool $allowPrivateNetwork,
    ): WebhookHttpResult;
}
