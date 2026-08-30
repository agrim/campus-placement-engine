<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Core\Http\UserVisibleException;
use App\Core\Modules\ModuleRegistry;
use App\Core\Security\CapabilityService;
use PDO;

final class ApiManagementAuthorization
{
    public const CAPABILITY = 'portal.integrations.manage';

    public static function requireActor(PDO $pdo, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw self::denied();
        }
        $lock = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $query = $pdo->prepare('SELECT * FROM users WHERE id = ? AND active = 1' . $lock);
        $query->execute([$actorUserId]);
        $actor = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor)) {
            throw self::denied();
        }
        $modules = new ModuleRegistry((array) cpe_config('modules', []), $pdo);
        if (!CapabilityService::fromDatabase($pdo, $modules)->allows($actor, self::CAPABILITY)) {
            throw self::denied();
        }
        return $actor;
    }

    private static function denied(): UserVisibleException
    {
        return new UserVisibleException(
            'API_MANAGEMENT_ACTOR_INVALID',
            'An active user with integration-management capability is required.',
        );
    }
}
