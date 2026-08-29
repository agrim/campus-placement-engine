<?php

declare(strict_types=1);

define('CPE_WEBHOOK_RECEIVER_LIBRARY_ONLY', true);
require __DIR__ . '/../examples/integrations/verify-webhook.php';

function webhook_receiver_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$oversized = tmpfile();
webhook_receiver_assert(is_resource($oversized), 'Could not create oversized receiver fixture.');
try {
    $fixtureBytes = 2 * 1048576;
    webhook_receiver_assert(
        fwrite($oversized, str_repeat('x', $fixtureBytes)) === $fixtureBytes,
        'Could not write oversized receiver fixture.',
    );
    rewind($oversized);
    $bounded = cpe_webhook_read_raw_body($oversized);
    webhook_receiver_assert(is_string($bounded), 'Receiver did not read a valid stream.');
    webhook_receiver_assert(
        strlen($bounded) === 1048577,
        'Receiver did not stop at the one-byte oversized sentinel.',
    );
    webhook_receiver_assert(!feof($oversized), 'Receiver consumed the complete oversized input.');
    webhook_receiver_assert(
        ftell($oversized) === 1048577,
        'Receiver advanced beyond the bounded oversized-input sentinel.',
    );
} finally {
    fclose($oversized);
}

$source = (string) file_get_contents(__DIR__ . '/../examples/integrations/verify-webhook.php');
webhook_receiver_assert(
    !str_contains($source, "file_get_contents('php://input')"),
    'Receiver example reverted to unbounded request-body buffering.',
);

echo "PASS webhook receiver bounded-input example contract\n";
