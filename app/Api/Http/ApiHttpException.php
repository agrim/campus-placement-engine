<?php

declare(strict_types=1);

namespace App\Api\Http;

use RuntimeException;

final class ApiHttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly string $publicCode,
        private readonly string $publicMessage,
        private readonly string $auditDetailCode,
        private readonly array $headers = [],
    ) {
        parent::__construct($publicMessage);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    public function auditDetailCode(): string
    {
        return $this->auditDetailCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
