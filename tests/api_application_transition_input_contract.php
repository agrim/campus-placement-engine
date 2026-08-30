<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';

use App\Api\Http\ApiApplicationTransitionRequestParser;
use App\Api\Http\ApiHttpException;
use App\Api\Http\ApiHttpRequest;

function api_transition_input_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api_transition_input_request(string $body, array $overrides = []): ApiHttpRequest
{
    $server = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v1/applications/application_' . str_repeat('a', 32) . '/transitions',
        'QUERY_STRING' => '',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) strlen($body),
        'HTTP_IDEMPOTENCY_KEY' => str_repeat('b', 32),
        'HTTP_IF_MATCH' => '"' . str_repeat('c', 64) . '"',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    foreach ($overrides as $name => $value) {
        if ($value === null) {
            unset($server[$name]);
        } else {
            $server[$name] = $value;
        }
    }
    return ApiHttpRequest::fromServer($server, 'req_' . str_repeat('d', 32));
}

function api_transition_input_denied(
    string $body,
    int $status,
    string $code,
    array $overrides = [],
): void {
    try {
        (new ApiApplicationTransitionRequestParser())->parse(
            api_transition_input_request($body, $overrides),
            $body,
        );
    } catch (ApiHttpException $failure) {
        api_transition_input_assert($failure->status() === $status, 'Strict input status differs for ' . $code . '.');
        api_transition_input_assert($failure->publicCode() === $code, 'Strict input code differs for ' . $code . '.');
        return;
    }
    throw new RuntimeException('Strict input was accepted for ' . $code . '.');
}

$validBody = '{"transition_key":"idle_to_scheduled","target_status":"scheduled","note":"API move"}';
$parsed = (new ApiApplicationTransitionRequestParser())->parse(
    api_transition_input_request($validBody),
    $validBody,
);
api_transition_input_assert($parsed->transitionKey() === 'idle_to_scheduled', 'Valid transition key changed.');
api_transition_input_assert($parsed->targetStatus() === 'scheduled', 'Valid target status changed.');
api_transition_input_assert($parsed->note() === 'API move', 'Valid note changed.');
api_transition_input_assert($parsed->idempotencyKey() === str_repeat('b', 32), 'Clear key parsing differs.');
api_transition_input_assert($parsed->ifMatch() === '"' . str_repeat('c', 64) . '"', 'If-Match parsing differs.');
api_transition_input_assert(
    $parsed->fingerprintRequest() === [
        'if_match' => '"' . str_repeat('c', 64) . '"',
        'note' => 'API move',
        'target_status' => 'scheduled',
        'transition_key' => 'idle_to_scheduled',
    ],
    'Normalized fingerprint request differs.',
);

$withoutNote = '{"target_status":"scheduled","transition_key":"idle_to_scheduled"}';
$normalized = (new ApiApplicationTransitionRequestParser())->parse(
    api_transition_input_request($withoutNote),
    $withoutNote,
);
api_transition_input_assert($normalized->note() === '', 'Omitted note did not normalize to empty.');

$multibyteNote = str_repeat('é', 1000);
$multibyteBody = json_encode([
    'transition_key' => 'idle_to_scheduled',
    'target_status' => 'scheduled',
    'note' => $multibyteNote,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$multibyte = (new ApiApplicationTransitionRequestParser())->parse(
    api_transition_input_request($multibyteBody),
    $multibyteBody,
);
api_transition_input_assert(
    $multibyte->note() === $multibyteNote,
    'A schema-valid 1000-character multibyte note was rejected.',
);

api_transition_input_denied($validBody, 400, 'invalid_query', ['QUERY_STRING' => 'x=1']);
api_transition_input_denied($validBody, 415, 'unsupported_media_type', ['CONTENT_TYPE' => 'text/json']);
api_transition_input_denied($validBody, 415, 'unsupported_media_type', ['CONTENT_TYPE' => 'application/json; charset=utf-8']);
api_transition_input_denied($validBody, 400, 'invalid_idempotency_key', ['HTTP_IDEMPOTENCY_KEY' => null]);
api_transition_input_denied($validBody, 400, 'invalid_idempotency_key', ['HTTP_IDEMPOTENCY_KEY' => str_repeat('A', 32)]);
api_transition_input_denied($validBody, 400, 'invalid_idempotency_key', ['HTTP_IDEMPOTENCY_KEY' => str_repeat('b', 32) . ', ' . str_repeat('c', 32)]);
api_transition_input_denied($validBody, 428, 'precondition_required', ['HTTP_IF_MATCH' => null]);
foreach (['*', 'W/"' . str_repeat('c', 64) . '"', '"' . str_repeat('c', 64) . '", "' . str_repeat('d', 64) . '"'] as $invalidEtag) {
    api_transition_input_denied($validBody, 400, 'invalid_precondition', ['HTTP_IF_MATCH' => $invalidEtag]);
}
api_transition_input_denied('', 400, 'request_body_required');
api_transition_input_denied($validBody, 400, 'invalid_request_body', ['CONTENT_LENGTH' => '1']);
$largeBody = '{"transition_key":"a","target_status":"b","note":"' . str_repeat('x', 16384) . '"}';
api_transition_input_denied($largeBody, 413, 'payload_too_large');
api_transition_input_denied("{\"transition_key\":\"a\",\"target_status\":\"b\",\"note\":\"\xFF\"}", 400, 'invalid_json');

foreach ([
    ['[]', 'invalid_json'],
    ['{', 'invalid_json'],
    ['{"transition_key":"a","transition_key":"b","target_status":"c"}', 'invalid_request_body'],
    ['{"transition_key":"a","target_status":"b","unknown":"c"}', 'invalid_request_body'],
    ['{"transition_key":"a"}', 'invalid_request_body'],
    ['{"transition_key":"a","target_status":1}', 'invalid_json'],
    ['{"transition_key":"a","target_status":{"deep":{"deeper":"x"}}}', 'invalid_json'],
] as [$invalidJson, $expected]) {
    api_transition_input_denied($invalidJson, 400, $expected);
}

foreach ([
    '{"transition_key":"UPPER","target_status":"scheduled"}',
    '{"transition_key":"idle_to_scheduled","target_status":"has-dash"}',
    '{"transition_key":"idle_to_scheduled","target_status":"scheduled","note":"line\\nbreak"}',
    '{"transition_key":"idle_to_scheduled","target_status":"scheduled","note":"' . str_repeat('n', 1001) . '"}',
    json_encode([
        'transition_key' => 'idle_to_scheduled',
        'target_status' => 'scheduled',
        'note' => str_repeat('é', 1001),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
] as $invalidField) {
    api_transition_input_denied($invalidField, 400, 'invalid_request_body');
}

echo "PASS API application transition strict input contract\n";
