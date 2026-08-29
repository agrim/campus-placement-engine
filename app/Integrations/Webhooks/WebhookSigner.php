<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use RuntimeException;

final class WebhookSigner
{
    /** @param non-empty-list<string> $secrets */
    public static function signatureHeader(string $eventId, int $timestamp, string $body, array $secrets): string
    {
        self::assertSigningInput($eventId, $timestamp, $body);
        if ($secrets === [] || count($secrets) > 2) {
            throw new RuntimeException('Webhook signing requires one or two signing secrets.');
        }
        $input = $eventId . '.' . $timestamp . '.' . $body;
        $signatures = [];
        foreach ($secrets as $secret) {
            if (preg_match('/^whsec_[A-Za-z0-9_-]{43}$/D', $secret) !== 1) {
                throw new RuntimeException('Webhook signing secret format is invalid.');
            }
            $signatures[] = 'v1=' . hash_hmac('sha256', $input, $secret);
        }
        return implode(',', $signatures);
    }

    public static function verify(
        string $eventId,
        int $timestamp,
        string $rawBody,
        string $signatureHeader,
        string $secret,
        ?int $now = null,
        int $maximumSkewSeconds = 300,
    ): bool {
        if ($maximumSkewSeconds < 1 || $maximumSkewSeconds > 3600) {
            throw new RuntimeException('Webhook verification skew must be between 1 and 3600 seconds.');
        }
        $now ??= time();
        if (abs($now - $timestamp) > $maximumSkewSeconds) {
            return false;
        }
        try {
            $expected = substr(self::signatureHeader($eventId, $timestamp, $rawBody, [$secret]), 3);
        } catch (\Throwable) {
            return false;
        }
        foreach (explode(',', $signatureHeader) as $candidate) {
            if (preg_match('/^v1=([a-f0-9]{64})$/D', trim($candidate), $match) === 1
                && hash_equals($expected, $match[1])) {
                return true;
            }
        }
        return false;
    }

    private static function assertSigningInput(string $eventId, int $timestamp, string $body): void
    {
        if (preg_match('/^(?:event|validation)_[a-f0-9]{32}$/D', $eventId) !== 1
            || $timestamp < 1
            || strlen($body) > 1048576) {
            throw new RuntimeException('Webhook signing input is invalid.');
        }
    }
}
