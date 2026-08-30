<?php

declare(strict_types=1);

namespace App\Api\Commands;

final class ApiCommandReservation
{
    private function __construct(
        private readonly int $recordId,
        private readonly bool $replay,
        private readonly ?string $responseJson,
        private readonly ?int $responseStatus,
        private readonly ?string $responseEtag,
    ) {
    }

    public static function pending(int $recordId): self
    {
        return new self($recordId, false, null, null, null);
    }

    public static function replay(int $recordId, string $responseJson, int $responseStatus, string $responseEtag): self
    {
        return new self($recordId, true, $responseJson, $responseStatus, $responseEtag);
    }

    public function recordId(): int { return $this->recordId; }
    public function isReplay(): bool { return $this->replay; }
    public function responseJson(): ?string { return $this->responseJson; }
    public function responseStatus(): ?int { return $this->responseStatus; }
    public function responseEtag(): ?string { return $this->responseEtag; }
}
