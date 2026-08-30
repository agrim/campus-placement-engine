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
 * durable browser-user authorization decision in the same outer transaction.
 */
final class ApplicationTransitionService
{
    public const CAPABILITY = 'placement.application.transition';
    public const DENIED_CODE = 'BOARD_TRANSITION_FORBIDDEN';
    public const DENIED_MESSAGE = 'Auditors cannot change candidate status.';

    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function execute(
        ApplicationTransitionCommand $command,
        ApplicationTransitionActor $actor,
    ): ApplicationTransitionResult {
        return WriteTransaction::run($this->pdo, function () use ($command, $actor): ApplicationTransitionResult {
            // Canonical PostgreSQL transition order uses PortalKernelSynchronizer's
            // module-first gate: module_installations, users, role_capabilities.
            $this->lockPlacementModule();
            $currentUser = $this->revalidateActor($actor);
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

    /** @return array<string, mixed> */
    private function revalidateActor(ApplicationTransitionActor $actor): array
    {
        $lock = $this->isPostgres() ? ' FOR UPDATE' : '';
        $user = $this->pdo->prepare(
            'SELECT id, role, scope_type, scope_value, active
             FROM users WHERE id = ?' . $lock,
        );
        $user->execute([$actor->userId()]);
        $current = $user->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)
            || !$actor->active()
            || !in_array($current['active'] ?? null, [true, 1, '1'], true)
            || (int) ($current['id'] ?? 0) !== $actor->userId()
            || (string) ($current['role'] ?? '') !== $actor->role()
            || (string) ($current['scope_type'] ?? '') !== $actor->scopeType()
            || (string) ($current['scope_value'] ?? '') !== $actor->scopeValue()) {
            $this->deny();
        }

        $this->lockRoleCapabilities((string) $current['role']);
        $modules = new ModuleRegistry(cpe_config('modules', []), $this->pdo);
        if (!CapabilityService::fromDatabase($this->pdo, $modules)->allows($current, self::CAPABILITY)) {
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

    private function lockRoleCapabilities(string $role): void
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
        $grants->execute([$role, self::CAPABILITY]);
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
}
