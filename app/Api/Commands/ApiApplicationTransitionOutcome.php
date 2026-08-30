<?php

declare(strict_types=1);

namespace App\Api\Commands;

use JsonException;

/** Exact committed or replayed HTTP representation from the idempotency store. */
final class ApiApplicationTransitionOutcome
{
    private function __construct(
        private readonly string $responseJson,
        private readonly int $status,
        private readonly string $etag,
        private readonly string $responseRequestId,
        private readonly bool $replay,
    ) {
    }

    public static function fromReservation(ApiCommandReservation $reservation, bool $replay): self
    {
        $json = $reservation->responseJson();
        $status = $reservation->responseStatus();
        $etag = $reservation->responseEtag();
        if (!is_string($json)
            || $status !== 200
            || !is_string($etag)
            || preg_match('/\A"[a-f0-9]{64}"\z/D', $etag) !== 1) {
            throw new ApiCommandUnavailable();
        }
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiCommandUnavailable();
        }
        $requestId = is_array($decoded) && is_array($decoded['meta'] ?? null)
            ? ($decoded['meta']['request_id'] ?? null)
            : null;
        if (!is_array($decoded['data'] ?? null)
            || !is_string($requestId)
            || preg_match('/\Areq_[a-f0-9]{32}\z/D', $requestId) !== 1) {
            throw new ApiCommandUnavailable();
        }
        return new self($json, $status, $etag, $requestId, $replay);
    }

    public function responseJson(): string { return $this->responseJson; }
    public function status(): int { return $this->status; }
    public function etag(): string { return $this->etag; }
    public function responseRequestId(): string { return $this->responseRequestId; }
    public function isReplay(): bool { return $this->replay; }
}
