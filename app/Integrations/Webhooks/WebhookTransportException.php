<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use RuntimeException;

final class WebhookTransportException extends RuntimeException
{
    public const NETWORK = 'network';
    public const TIMEOUT = 'timeout';
    public const TLS = 'tls';
    public const POLICY = 'policy';
    public const RESPONSE_TOO_LARGE = 'response_too_large';

    public function __construct(
        public readonly string $failureKind,
        public readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Webhook transport failed: ' . $failureKind . '.', 0, $previous);
    }
}
