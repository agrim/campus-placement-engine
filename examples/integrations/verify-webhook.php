<?php

declare(strict_types=1);

/** @param resource $stream */
function cpe_webhook_read_raw_body(mixed $stream): string|false
{
    if (!is_resource($stream)) {
        return false;
    }
    // One byte above the accepted limit is enough to reject the request;
    // never buffer an attacker-controlled request body without a bound.
    return stream_get_contents($stream, 1048577);
}

if (defined('CPE_WEBHOOK_RECEIVER_LIBRARY_ONLY') && CPE_WEBHOOK_RECEIVER_LIBRARY_ONLY === true) {
    return;
}

// Minimal receiver example. Put the real value in the receiver's secret manager.
$secret = (string) (getenv('CPE_WEBHOOK_SIGNING_SECRET') ?: '');
$eventId = (string) ($_SERVER['HTTP_CPE_WEBHOOK_ID'] ?? '');
$timestampText = (string) ($_SERVER['HTTP_CPE_WEBHOOK_TIMESTAMP'] ?? '');
$signatureHeader = (string) ($_SERVER['HTTP_CPE_WEBHOOK_SIGNATURE'] ?? '');
$schema = (string) ($_SERVER['HTTP_CPE_WEBHOOK_SCHEMA'] ?? '');
$input = fopen('php://input', 'rb');
$rawBody = cpe_webhook_read_raw_body($input);
if (is_resource($input)) {
    fclose($input);
}

if (preg_match('/^whsec_[A-Za-z0-9_-]{43}$/D', $secret) !== 1
    || preg_match('/^event_[a-f0-9]{32}$/D', $eventId) !== 1
    || preg_match('/^[1-9][0-9]{0,10}$/D', $timestampText) !== 1
    || $schema !== 'application.status_changed;version=1'
    || !is_string($rawBody)
    || strlen($rawBody) > 1048576) {
    http_response_code(400);
    exit;
}

$timestamp = (int) $timestampText;
if (abs(time() - $timestamp) > 300) {
    http_response_code(401);
    exit;
}

$expected = hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $rawBody, $secret);
$verified = false;
$candidates = explode(',', $signatureHeader);
if (count($candidates) <= 2) {
    foreach ($candidates as $candidate) {
        if (preg_match('/^v1=([a-f0-9]{64})$/D', trim($candidate), $match) === 1
            && hash_equals($expected, $match[1])) {
            $verified = true;
        }
    }
}
if (!$verified) {
    http_response_code(401);
    exit;
}

try {
    $event = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    exit;
}
if (!is_array($event)
    || ($event['event_id'] ?? null) !== $eventId
    || ($event['event_type'] ?? null) !== 'application.status_changed'
    || ($event['schema_version'] ?? null) !== 1) {
    http_response_code(400);
    exit;
}

// Transactionally reserve event_id in durable storage before any side effect.
// If it was already completed, return 2xx without repeating the side effect.
http_response_code(204);
