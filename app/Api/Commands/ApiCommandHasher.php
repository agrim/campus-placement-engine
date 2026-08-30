<?php

declare(strict_types=1);

namespace App\Api\Commands;

use App\Api\Security\ApiKeyring;
use JsonException;

final class ApiCommandHasher
{
    public const OPERATION = 'application.transition';
    public const RETRY_HOURS = 48;

    private const MAX_CANONICAL_BYTES = 16384;
    private const MAX_DEPTH = 8;
    private const MAX_CONTAINER_ITEMS = 128;
    private const MAX_STRING_BYTES = 4096;

    public function __construct(private readonly ApiKeyring $keyring)
    {
    }

    /** @param array<string, mixed> $request */
    public function fingerprintApplicationTransition(
        string $clearKey,
        string $institutionPublicId,
        string $serviceAccountPublicId,
        string $aggregatePublicId,
        array $request,
    ): ApiCommandFingerprint {
        if (preg_match('/\A[a-f0-9]{32,64}\z/D', $clearKey) !== 1) {
            throw new InvalidApiCommandInput('The API command idempotency key must be 32 to 64 lowercase hexadecimal characters.');
        }
        if (preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $institutionPublicId) !== 1
            || preg_match('/\Aapisa_[a-f0-9]{32}\z/D', $serviceAccountPublicId) !== 1
            || preg_match('/\Aapplication_[a-f0-9]{32}\z/D', $aggregatePublicId) !== 1) {
            throw new InvalidApiCommandInput('API command identity is invalid.');
        }

        $canonicalRequest = self::canonicalObject($request);
        $binding = self::canonicalObject([
            'aggregate_id' => $aggregatePublicId,
            'institution_id' => $institutionPublicId,
            'operation' => self::OPERATION,
            'request' => json_decode($canonicalRequest, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR),
            'version' => 1,
        ]);
        $requestHash = hash(
            'sha256',
            implode("\0", ['cpe-api-command-request', '1', $institutionPublicId, self::OPERATION])
                . "\0" . $binding,
        );

        $versions = $this->keyring->versions();
        sort($versions, SORT_STRING);
        $candidateHashes = [];
        foreach ($versions as $version) {
            $candidateHashes[$version] = $this->keyring->commandIdempotencyHash(
                $clearKey,
                $institutionPublicId,
                self::OPERATION,
                $version,
            );
        }
        $activeVersion = $this->keyring->activeVersion();
        return new ApiCommandFingerprint(
            $institutionPublicId,
            $serviceAccountPublicId,
            self::OPERATION,
            $aggregatePublicId,
            $requestHash,
            $activeVersion,
            $candidateHashes[$activeVersion],
            $candidateHashes,
        );
    }

    /** @param array<string, mixed> $value */
    public static function canonicalObject(array $value): string
    {
        if ($value === [] || array_is_list($value)) {
            throw new InvalidApiCommandInput('API command canonical data must be a non-empty object.');
        }
        $normalized = self::normalize($value, 1);
        try {
            $json = json_encode(
                $normalized,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $failure) {
            throw new InvalidApiCommandInput('API command canonical data is invalid.', 0, $failure);
        }
        if (!is_string($json) || strlen($json) > self::MAX_CANONICAL_BYTES) {
            throw new InvalidApiCommandInput('API command canonical data is too large.');
        }
        return $json;
    }

    private static function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidApiCommandInput('API command canonical data is too deeply nested.');
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_CONTAINER_ITEMS) {
                throw new InvalidApiCommandInput('API command canonical data has too many fields.');
            }
            if (array_is_list($value)) {
                return array_map(static fn (mixed $item): mixed => self::normalize($item, $depth + 1), $value);
            }
            foreach (array_keys($value) as $key) {
                if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1) {
                    throw new InvalidApiCommandInput('API command canonical field name is invalid.');
                }
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item, $depth + 1);
            }
            return $value;
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES || preg_match('//u', $value) !== 1) {
                throw new InvalidApiCommandInput('API command canonical string is invalid.');
            }
            return $value;
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        throw new InvalidApiCommandInput('API command canonical values must be JSON scalars, arrays, or objects.');
    }
}
