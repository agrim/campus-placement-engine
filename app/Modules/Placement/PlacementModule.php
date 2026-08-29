<?php

declare(strict_types=1);

namespace App\Modules\Placement;

use App\Controllers\AdminController;
use App\Controllers\BoardController;
use App\Controllers\CandidateController;
use App\Controllers\ImportController;
use App\Controllers\NotificationController;
use App\Controllers\PreferenceController;
use App\Controllers\PublicController;
use App\Controllers\RecordsController;
use App\Controllers\ReportsController;
use App\Controllers\SystemController;
use App\Controllers\WantedController;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModulePortabilityHandler;
use App\Core\Modules\ModulePrivacyHandler;
use App\Core\Modules\ProvidesPortability;
use App\Core\Modules\ProvidesPrivacy;
use App\Modules\Placement\Portability\PlacementPortabilityHandler;
use App\Modules\Placement\Privacy\PlacementPrivacyHandler;

final class PlacementModule implements Module, ProvidesPortability, ProvidesPrivacy
{
    public const CPE_MODULE_KEY = 'placement';
    public const CPE_MODULE_VERSION = '0.1.0';

    public function key(): string
    {
        return self::CPE_MODULE_KEY;
    }

    public function manifest(): ModuleManifest
    {
        $definition = (array) cpe_config('modules.' . self::CPE_MODULE_KEY, []);
        $definition['version'] = self::CPE_MODULE_VERSION;
        return ModuleManifest::fromArray(self::CPE_MODULE_KEY, $definition);
    }

    public function portabilityHandler(): ModulePortabilityHandler
    {
        return new PlacementPortabilityHandler();
    }

    public function privacyHandler(): ModulePrivacyHandler
    {
        return new PlacementPrivacyHandler();
    }

    public function routes(): array
    {
        return [
            $this->route('public', 'GET', PublicController::class, 'placements'),
            $this->route('student', 'GET', PublicController::class, 'student'),
            $this->route('board', 'GET', BoardController::class, 'index'),
            $this->route('move', 'POST', BoardController::class, 'move'),
            $this->route('return-to-idle', 'POST', BoardController::class, 'returnToIdle'),
            $this->route('board-preferences', 'POST', BoardController::class, 'preferences'),
            $this->route('notifications', 'GET', NotificationController::class, 'show'),
            $this->route('notification-acknowledge', 'POST', NotificationController::class, 'acknowledge'),
            $this->route('candidate', 'GET', CandidateController::class, 'show'),
            $this->route('records', 'GET', RecordsController::class, 'show'),
            $this->route('records-candidate', 'POST', RecordsController::class, 'saveCandidate'),
            $this->route('records-company', 'POST', RecordsController::class, 'saveCompany'),
            $this->route('records-round', 'POST', RecordsController::class, 'saveRound'),
            $this->route('records-schedule', 'POST', RecordsController::class, 'saveSchedule'),
            $this->route('records-panelist', 'POST', RecordsController::class, 'savePanelist'),
            $this->route('records-slot-assignment', 'POST', RecordsController::class, 'saveSlotAssignment'),
            $this->route('records-application', 'POST', RecordsController::class, 'saveApplication'),
            $this->route('reports', 'GET', ReportsController::class, 'show'),
            $this->route('import', 'GET', ImportController::class, 'show'),
            $this->route('import', 'POST', ImportController::class, 'run'),
            $this->route('import-rollback', 'POST', ImportController::class, 'rollback'),
            $this->route('admin', 'GET', AdminController::class, 'show'),
            $this->route('admin', 'POST', AdminController::class, 'update'),
            $this->route('admin-user', 'POST', AdminController::class, 'createUser'),
            $this->route('admin-users', 'POST', AdminController::class, 'updateUsers'),
            $this->route('admin-password', 'POST', AdminController::class, 'resetPassword'),
            $this->route('admin-workflow', 'POST', AdminController::class, 'updateWorkflow'),
            $this->route('preferences', 'GET', PreferenceController::class, 'show'),
            $this->route('preferences', 'POST', PreferenceController::class, 'create'),
            $this->route('preferences-resolve', 'POST', PreferenceController::class, 'resolve'),
            $this->route('wanted', 'GET', WantedController::class, 'show'),
            $this->route('wanted', 'POST', WantedController::class, 'create'),
            $this->route('wanted-resolve', 'POST', WantedController::class, 'resolve'),
            $this->route('system', 'GET', SystemController::class, 'show'),
            $this->route('system-clear-demo', 'POST', SystemController::class, 'clearDemoData'),
        ];
    }

    public function navigation(): array
    {
        return [
            $this->nav('board', 'Board', 'placement.board.view', 10, '/'),
            $this->nav('records', 'Records', 'placement.records.view', 20),
            $this->nav('reports', 'Reports', 'placement.reports.view', 30),
            $this->nav('import', 'Import', 'placement.import.manage', 40),
            $this->nav('notifications', 'Notifications', 'placement.notifications.view', 50),
            $this->nav('preferences', 'Preferences', 'placement.preferences.view', 60),
            $this->nav('wanted', 'Wanted', 'placement.wanted.view', 70),
            $this->nav('admin', 'Admin', 'portal.settings.manage', 80),
            $this->nav('system', 'System', 'placement.system.view', 90),
            $this->nav('public', 'Public', 'portal.access', 100),
        ];
    }

    private function route(string $name, string $method, string $controller, string $action): array
    {
        return compact('name', 'method', 'controller', 'action');
    }

    private function nav(string $route, string $label, string $capability, int $order, ?string $href = null): array
    {
        return compact('route', 'label', 'capability', 'order', 'href');
    }
}
