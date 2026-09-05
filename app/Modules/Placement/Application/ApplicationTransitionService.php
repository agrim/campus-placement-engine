<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

use App\Core\Http\UserVisibleException;
use App\Core\Modules\ModuleRegistry;
use App\Core\Persistence\WriteTransaction;
use App\Core\Security\CapabilityService;
use App\Domain\PlacementService as LegacyPlacementService;
use App\Support\Database;
use PDO;

/**
 * Shared transaction-local boundary for ordinary application transitions.
 *
 * The established placement implementation remains the single owner of
 * workflow policy and private placement effects. This boundary adds a fresh
 * durable actor authorization decision in the same outer transaction.
 */
final class ApplicationTransitionService
{
    public const CAPABILITY = 'placement.application.transition';
    public const CORRECTION_CAPABILITY = 'placement.application.correct';
    public const SERVICE_SCOPE = 'applications.transition';
    public const DENIED_CODE = 'BOARD_TRANSITION_FORBIDDEN';
    public const DENIED_MESSAGE = 'Auditors cannot change candidate status.';
    public const SERVICE_DENIED_CODE = 'API_APPLICATION_TRANSITION_FORBIDDEN';
    public const SERVICE_DENIED_MESSAGE = 'The service account cannot transition this application.';

    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function execute(
        ApplicationTransitionCommand $command,
        ApplicationTransitionActor $actor,
    ): ApplicationTransitionResult {
        if (!$actor->isBrowserUser()) {
            $this->deny();
        }
        return WriteTransaction::run($this->pdo, function () use ($command, $actor): ApplicationTransitionResult {
            // Canonical PostgreSQL transition order uses PortalKernelSynchronizer's
            // module-first gate: module_installations, users, role_capabilities.
            $currentUser = $this->authorizeBrowserActorWithinTransaction($actor);
            $implementation = new LegacyPlacementService($this->pdo);
            $result = $implementation->applyBoardMove(
                $command->applicationId(),
                (int) $currentUser['id'],
                (string) $currentUser['role'],
                $command->toStatus(),
                $command->transitionKey(),
                $command->note(),
                $command->expectedFromStatus(),
                $command->idempotencyKey(),
                $currentUser,
            );
            return ApplicationTransitionResult::fromLegacyResult($result);
        });
    }

    /** Reusable authorization fence for ordinary moves and privileged corrections. */
    public function authorizeBrowserActorWithinTransaction(
        ApplicationTransitionActor $actor,
        string $capability = self::CAPABILITY,
    ): array {
        if (!$actor->isBrowserUser()
            || !in_array($capability, [self::CAPABILITY, self::CORRECTION_CAPABILITY], true)) {
            $this->deny();
        }
        if (!WriteTransaction::isActive($this->pdo)) {
            throw new \RuntimeException('Browser authorization requires an active write transaction.');
        }
        $this->lockPlacementModule();
        return $this->revalidateActor($actor, $capability);
    }

    public function executeForServiceAccount(
        ApplicationTransitionCommand $command,
        ApplicationTransitionActor $actor,
    ): ApplicationTransitionResult {
        if (!$actor->isServiceAccount()) {
            $this->denyServiceAccount();
        }
        return WriteTransaction::run($this->pdo, function () use ($command, $actor): ApplicationTransitionResult {
            $this->authorizeServiceAccountWithinTransaction($actor);
            $implementation = new LegacyPlacementService($this->pdo);
            $result = $implementation->applyServiceAccountMove(
                $command->applicationId(),
                $actor->serviceAccountId(),
                $command->toStatus(),
                $command->transitionKey(),
                $command->note(),
                $command->expectedFromStatus(),
            );
            return ApplicationTransitionResult::fromLegacyResult($result);
        });
    }

    /**
     * Establish the durable service-actor decision and canonical locks for a
     * larger command transaction before it acquires aggregate/idempotency rows.
     */
    public function authorizeServiceAccountWithinTransaction(ApplicationTransitionActor $actor): void
    {
        if (!$actor->isServiceAccount()) {
            $this->denyServiceAccount();
        }
        if (!WriteTransaction::isActive($this->pdo)) {
            throw new \RuntimeException('Service-account authorization requires an active write transaction.');
        }
        // Canonical PostgreSQL service-command lock order is module,
        // institution, service account, exact scope, capability catalog.
        $this->lockServicePlacementModule();
        $this->revalidateServiceAccount($actor);
    }

    /** @return array<string, mixed> */
    private function revalidateActor(ApplicationTransitionActor $actor, string $capability): array
    {
        $lock = $this->isPostgres() ? ' FOR UPDATE' : '';
        $user = $this->pdo->prepare(
            'SELECT id, role, scope_type, scope_value, active, session_generation
             FROM users WHERE id = ?' . $lock,
        );
        $user->execute([$actor->userId()]);
        $current = $user->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)
            || !$actor->active()
            || $actor->sessionGeneration() < 1
            || (int) ($current['session_generation'] ?? 0) !== $actor->sessionGeneration()
            || !in_array($current['active'] ?? null, [true, 1, '1'], true)
            || (int) ($current['id'] ?? 0) !== $actor->userId()
            || (string) ($current['role'] ?? '') !== $actor->role()
            || (string) ($current['scope_type'] ?? '') !== $actor->scopeType()
            || (string) ($current['scope_value'] ?? '') !== $actor->scopeValue()) {
            $this->deny();
        }

        $this->lockRoleCapabilities((string) $current['role'], $capability);
        $modules = new ModuleRegistry(cpe_config('modules', []), $this->pdo);
        if (!CapabilityService::fromDatabase($this->pdo, $modules)->allows($current, $capability)) {
            $this->deny();
        }
        return $current;
    }

    private function lockPlacementModule(): void
    {
        if (!$this->isPostgres()) {
            return;
        }
        $module = $this->pdo->prepare(
            'SELECT module_key FROM module_installations WHERE module_key = ? FOR UPDATE',
        );
        $module->execute(['placement']);
        $module->fetch(PDO::FETCH_ASSOC);
    }

    private function lockServicePlacementModule(): void
    {
        $module = $this->pdo->prepare(
            'SELECT module_key, enabled FROM module_installations
             WHERE module_key = ?' . ($this->isPostgres() ? ' FOR UPDATE' : ''),
        );
        $module->execute(['placement']);
        $row = $module->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (string) ($row['module_key'] ?? '') !== 'placement'
            || !in_array($row['enabled'] ?? null, [1, '1'], true)) {
            $this->denyServiceAccount();
        }
    }

    private function revalidateServiceAccount(ApplicationTransitionActor $actor): void
    {
        $lock = $this->isPostgres() ? ' FOR UPDATE' : '';
        $institution = $this->pdo->prepare(
            "SELECT id, public_id FROM institutions WHERE slug = 'default'" . $lock,
        );
        $institution->execute();
        $institutionRow = $institution->fetch(PDO::FETCH_ASSOC);
        if (!is_array($institutionRow)
            || (int) ($institutionRow['id'] ?? 0) !== $actor->institutionId()
            || !hash_equals((string) ($institutionRow['public_id'] ?? ''), $actor->institutionPublicId())) {
            $this->denyServiceAccount();
        }

        $account = $this->pdo->prepare(
            'SELECT id, public_id, institution_id, status, disabled_at, revoked_at
             FROM api_service_accounts WHERE id = ?' . $lock,
        );
        $account->execute([$actor->serviceAccountId()]);
        $accountRow = $account->fetch(PDO::FETCH_ASSOC);
        if (!is_array($accountRow)
            || (int) ($accountRow['institution_id'] ?? 0) !== $actor->institutionId()
            || !hash_equals((string) ($accountRow['public_id'] ?? ''), $actor->serviceAccountPublicId())
            || (string) ($accountRow['status'] ?? '') !== 'enabled'
            || ($accountRow['disabled_at'] ?? null) !== null
            || ($accountRow['revoked_at'] ?? null) !== null) {
            $this->denyServiceAccount();
        }

        $scope = $this->pdo->prepare(
            'SELECT service_account_id, scope FROM api_service_account_scopes
             WHERE service_account_id = ? AND scope = ?' . $lock,
        );
        $scope->execute([$actor->serviceAccountId(), self::SERVICE_SCOPE]);
        $scopeRows = $scope->fetchAll(PDO::FETCH_ASSOC);
        if (count($scopeRows) !== 1) {
            $this->denyServiceAccount();
        }

        $capabilities = $this->pdo->prepare(
            'SELECT role_key, capability FROM role_capabilities
             WHERE capability = ? ORDER BY role_key' . $lock,
        );
        $capabilities->execute([self::CAPABILITY]);
        if ($capabilities->fetchAll(PDO::FETCH_ASSOC) === []) {
            $this->denyServiceAccount();
        }

        $modules = new ModuleRegistry(cpe_config('modules', []), $this->pdo);
        if (!$modules->isEnabled('placement')) {
            $this->denyServiceAccount();
        }
    }

    private function lockRoleCapabilities(string $role, string $capability): void
    {
        if (!$this->isPostgres()) {
            return;
        }
        $grants = $this->pdo->prepare(
            "SELECT role_key, capability
             FROM role_capabilities
             WHERE role_key = ? AND capability IN ('*', ?)
             ORDER BY capability
             FOR UPDATE",
        );
        $grants->execute([$role, $capability]);
        $grants->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isPostgres(): bool
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    private function deny(): never
    {
        throw new UserVisibleException(self::DENIED_CODE, self::DENIED_MESSAGE);
    }

    private function denyServiceAccount(): never
    {
        throw new UserVisibleException(self::SERVICE_DENIED_CODE, self::SERVICE_DENIED_MESSAGE);
    }
}
