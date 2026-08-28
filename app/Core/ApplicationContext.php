<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Institution\InstitutionContext;
use App\Core\Events\EventDispatcher;
use App\Core\Institution\InstitutionRepository;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleManager;
use App\Core\Security\CapabilityService;
use App\Core\Settings\SettingRepository;
use App\Support\Database;
use PDO;

final class ApplicationContext
{
    public function __construct(
        private readonly PDO $connection,
        private readonly InstitutionContext $institution,
        private readonly SettingRepository $settings,
        private readonly ModuleRegistry $modules,
        private readonly ModuleManager $moduleManager,
        private readonly CapabilityService $capabilities,
        private readonly EventDispatcher $events,
    ) {
    }

    public static function fromCurrentInstallation(): self
    {
        $pdo = Database::connection();
        $modules = new ModuleRegistry(cpe_config('modules', []), $pdo);
        $capabilities = CapabilityService::fromDatabase($pdo, cpe_config('capabilities.roles', []), $modules);
        $moduleManager = new ModuleManager($modules, $capabilities);
        return new self(
            $pdo,
            (new InstitutionRepository($pdo))->current(),
            new SettingRepository($pdo),
            $modules,
            $moduleManager,
            $capabilities,
            new EventDispatcher($pdo, $moduleManager),
        );
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function institution(): InstitutionContext
    {
        return $this->institution;
    }

    public function settings(): SettingRepository
    {
        return $this->settings;
    }

    public function modules(): ModuleRegistry
    {
        return $this->modules;
    }

    public function moduleManager(): ModuleManager
    {
        return $this->moduleManager;
    }

    public function capabilities(): CapabilityService
    {
        return $this->capabilities;
    }

    public function events(): EventDispatcher
    {
        return $this->events;
    }
}
