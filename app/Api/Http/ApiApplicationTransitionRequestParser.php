<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Api\Commands\ApiApplicationTransitionInput;
use JsonException;

/** Strict, dependency-free parser for the sole public command request. */
final class ApiApplicationTransitionRequestParser
{
    public const MAX_BODY_BYTES = 16384;

    /** @param string|null $bodyOverride Test-only supplied bytes; production reads php://input. */
    public function parse(ApiHttpRequest $request, ?string $bodyOverride = null): ApiApplicationTransitionInput
    {
        $request->queryParameters([]);
        if (strcasecmp(trim($request->contentType()), 'application/json') !== 0) {
            throw new ApiHttpException(
                415,
                'unsupported_media_type',
                'Content-Type must be application/json.',
                'CONTENT_TYPE_UNSUPPORTED',
            );
        }

        $idempotencyKey = $request->idempotencyKey();
        if (preg_match('/\A[a-f0-9]{32,64}\z/D', $idempotencyKey) !== 1) {
            throw new ApiHttpException(
                400,
                'invalid_idempotency_key',
                'Idempotency-Key must be 32 to 64 lowercase hexadecimal characters.',
                'IDEMPOTENCY_KEY_INVALID',
            );
        }

        $ifMatch = $request->ifMatch();
        if ($ifMatch === '') {
            throw new ApiHttpException(
                428,
                'precondition_required',
                'A strong If-Match application ETag is required.',
                'IF_MATCH_REQUIRED',
            );
        }
        if (preg_match('/\A"[a-f0-9]{64}"\z/D', $ifMatch) !== 1) {
            throw new ApiHttpException(
                400,
                'invalid_precondition',
                'If-Match must contain exactly one strong application ETag.',
                'IF_MATCH_INVALID',
            );
        }

        $body = $request->requiredBody(self::MAX_BODY_BYTES, $bodyOverride);
        $values = $this->parseStringObject($body);
        $allowed = ['transition_key', 'target_status', 'note'];
        foreach (array_keys($values) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new ApiHttpException(
                    400,
                    'invalid_request_body',
                    'The request body contains an unknown field.',
                    'BODY_FIELD_UNKNOWN',
                );
            }
        }
        foreach (['transition_key', 'target_status'] as $required) {
            if (!array_key_exists($required, $values)) {
                throw new ApiHttpException(
                    400,
                    'invalid_request_body',
                    'transition_key and target_status are required.',
                    'BODY_FIELD_REQUIRED',
                );
            }
        }

        $transitionKey = $values['transition_key'];
        $targetStatus = $values['target_status'];
        $note = $values['note'] ?? '';
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $transitionKey) !== 1) {
            throw new ApiHttpException(
                400,
                'invalid_request_body',
                'transition_key is invalid.',
                'TRANSITION_KEY_INVALID',
            );
        }
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $targetStatus) !== 1) {
            throw new ApiHttpException(
                400,
                'invalid_request_body',
                'target_status is invalid.',
                'TARGET_STATUS_INVALID',
            );
        }
        if (mb_strlen($note, 'UTF-8') > 1000 || preg_match('/[\x00-\x1F\x7F]/', $note) === 1) {
            throw new ApiHttpException(
                400,
                'invalid_request_body',
                'note must be at most 1000 printable UTF-8 characters.',
                'NOTE_INVALID',
            );
        }

        return new ApiApplicationTransitionInput(
            $transitionKey,
            $targetStatus,
            $note,
            $idempotencyKey,
            $ifMatch,
        );
    }

    /** @return array<string, string> */
    private function parseStringObject(string $json): array
    {
        if (preg_match('//u', $json) !== 1) {
            $this->invalidJson();
        }
        $offset = 0;
        $length = strlen($json);
        $this->skipWhitespace($json, $offset, $length);
        if ($offset >= $length || $json[$offset] !== '{') {
            $this->invalidJson();
        }
        $offset++;
        $this->skipWhitespace($json, $offset, $length);
        $values = [];
        if ($offset < $length && $json[$offset] === '}') {
            $offset++;
        } else {
            while (true) {
                $key = $this->parseString($json, $offset, $length);
                if (array_key_exists($key, $values)) {
                    throw new ApiHttpException(
                        400,
                        'invalid_request_body',
                        'The request body contains a duplicate field.',
                        'BODY_FIELD_DUPLICATE',
                    );
                }
                $this->skipWhitespace($json, $offset, $length);
                if ($offset >= $length || $json[$offset] !== ':') {
                    $this->invalidJson();
                }
                $offset++;
                $this->skipWhitespace($json, $offset, $length);
                $values[$key] = $this->parseString($json, $offset, $length);
                $this->skipWhitespace($json, $offset, $length);
                if ($offset < $length && $json[$offset] === ',') {
                    $offset++;
                    $this->skipWhitespace($json, $offset, $length);
                    continue;
                }
                if ($offset < $length && $json[$offset] === '}') {
                    $offset++;
                    break;
                }
                $this->invalidJson();
            }
        }
        $this->skipWhitespace($json, $offset, $length);
        if ($offset !== $length) {
            $this->invalidJson();
        }
        return $values;
    }

    private function parseString(string $json, int &$offset, int $length): string
    {
        if ($offset >= $length || $json[$offset] !== '"') {
            $this->invalidJson();
        }
        $start = $offset++;
        $escaped = false;
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === '"') {
                $token = substr($json, $start, $offset - $start);
                try {
                    $decoded = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $this->invalidJson();
                }
                if (!is_string($decoded)) {
                    $this->invalidJson();
                }
                return $decoded;
            }
        }
        $this->invalidJson();
    }

    private function skipWhitespace(string $json, int &$offset, int $length): void
    {
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private function invalidJson(): never
    {
        throw new ApiHttpException(
            400,
            'invalid_json',
            'The request body must be one valid JSON object with string values.',
            'JSON_INVALID',
        );
    }
}
