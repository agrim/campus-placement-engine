<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

/**
 * Immutable, exclusive browser-user or service-account identity snapshot.
 *
 * ApplicationTransitionService must match the selected actor kind against
 * durable state again inside the transition transaction before delegating.
 */
final class ApplicationTransitionActor
{
    public const KIND_BROWSER_USER = 'browser_user';
    public const KIND_SERVICE_ACCOUNT = 'service_account';
    public const SERVICE_ACCOUNT_ROLE = 'service_account';

    private function __construct(
        private readonly string $kind,
        private readonly int $userId,
        private readonly string $role,
        private readonly string $scopeType,
        private readonly string $scopeValue,
        private readonly bool $active,
        private readonly int $serviceAccountId,
        private readonly string $serviceAccountPublicId,
        private readonly int $institutionId,
        private readonly string $institutionPublicId,
    ) {
    }

    /** @param array<string, mixed> $user */
    public static function fromAuthenticatedUser(array $user): self
    {
        return new self(
            self::KIND_BROWSER_USER,
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''),
            (string) ($user['scope_type'] ?? ''),
            (string) ($user['scope_value'] ?? ''),
            in_array($user['active'] ?? null, [true, 1, '1'], true),
            0,
            '',
            0,
            '',
        );
    }

    public static function fromServiceAccount(
        int $serviceAccountId,
        string $serviceAccountPublicId,
        int $institutionId,
        string $institutionPublicId,
    ): self {
        if ($serviceAccountId < 1
            || preg_match('/\Aapisa_[a-f0-9]{32}\z/D', $serviceAccountPublicId) !== 1
            || $institutionId < 1
            || preg_match('/\A(?:inst|tenant)_[a-f0-9]{32}\z/D', $institutionPublicId) !== 1) {
            throw new \InvalidArgumentException('Service-account transition actor identity is invalid.');
        }
        return new self(
            self::KIND_SERVICE_ACCOUNT,
            0,
            self::SERVICE_ACCOUNT_ROLE,
            '',
            '',
            true,
            $serviceAccountId,
            $serviceAccountPublicId,
            $institutionId,
            $institutionPublicId,
        );
    }

    public function isBrowserUser(): bool
    {
        return $this->kind === self::KIND_BROWSER_USER;
    }

    public function isServiceAccount(): bool
    {
        return $this->kind === self::KIND_SERVICE_ACCOUNT;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    public function scopeValue(): string
    {
        return $this->scopeValue;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function serviceAccountId(): int
    {
        return $this->serviceAccountId;
    }

    public function serviceAccountPublicId(): string
    {
        return $this->serviceAccountPublicId;
    }

    public function institutionId(): int
    {
        return $this->institutionId;
    }

    public function institutionPublicId(): string
    {
        return $this->institutionPublicId;
    }
}
