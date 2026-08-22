<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

use App\Domain\PlacementService as LegacyPlacementService;
use App\Domain\Workflow;
use PDO;

/**
 * Placement Operations application boundary.
 *
 * The established service remains the compatibility implementation while its
 * use cases are extracted incrementally into this module. Runtime entry points
 * depend on this class so no placement behavior leaks back into the portal
 * kernel during that migration.
 */
final class PlacementService
{
    private LegacyPlacementService $implementation;

    public function __construct(?PDO $pdo = null, ?Workflow $workflow = null)
    {
        $this->implementation = new LegacyPlacementService($pdo, $workflow);
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->implementation->{$method}(...$arguments);
    }
}
