<?php

declare(strict_types=1);

namespace App\Modules\Placement;

use App\Controllers\AdminController;
use App\Controllers\ApiAccessController;
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
use App\Controllers\WebhookController;
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
            $this->route('operations', 'GET', ReportsController::class, 'operations'),
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
            $this->route('integrations', 'GET', WebhookController::class, 'show'),
            $this->route('integration-create', 'POST', WebhookController::class, 'create'),
            $this->route('integration-secret-generate', 'POST', WebhookController::class, 'generateSecret'),
            $this->route('integration-secret-rotate', 'POST', WebhookController::class, 'rotateSecret'),
            $this->route('integration-validate', 'POST', WebhookController::class, 'validate'),
            $this->route('integration-activate', 'POST', WebhookController::class, 'activate'),
            $this->route('integration-disable', 'POST', WebhookController::class, 'disable'),
            $this->route('integration-revoke', 'POST', WebhookController::class, 'revoke'),
            $this->route('integration-replay', 'POST', WebhookController::class, 'replay'),
            $this->route('api-access', 'GET', ApiAccessController::class, 'show'),
            $this->route('api-service-account-create', 'POST', ApiAccessController::class, 'create'),
            $this->route('api-token-rotate', 'POST', ApiAccessController::class, 'rotate'),
            $this->route('api-token-revoke', 'POST', ApiAccessController::class, 'revokeToken'),
            $this->route('api-service-account-enable', 'POST', ApiAccessController::class, 'enableAccount'),
            $this->route('api-service-account-disable', 'POST', ApiAccessController::class, 'disableAccount'),
            $this->route('api-service-account-revoke', 'POST', ApiAccessController::class, 'revokeAccount'),
            $this->route('api-enable', 'POST', ApiAccessController::class, 'enableApi'),
            $this->route('api-disable', 'POST', ApiAccessController::class, 'disableApi'),
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
            $this->nav(
                'operations',
                'Candidate opportunities',
                ['placement.reports.view', 'placement.sensitive.view'],
                5,
            ),
            $this->nav('board', 'Board', 'placement.board.view', 10, '/'),
            $this->nav('records', 'Records', 'placement.records.view', 20),
            $this->nav('reports', 'Reports', 'placement.reports.view', 30),
            $this->nav('import', 'Import', 'placement.import.manage', 40),
            $this->nav('notifications', 'Notifications', 'placement.notifications.view', 50),
            $this->nav('preferences', 'Preferences', 'placement.preferences.view', 60),
            $this->nav('wanted', 'Wanted', 'placement.wanted.view', 70),
            $this->nav('admin', 'Admin', 'portal.settings.manage', 80),
            $this->nav('integrations', 'Integrations', 'portal.integrations.manage', 85),
            $this->nav('api-access', 'API Access', 'portal.integrations.manage', 86),
            $this->nav('system', 'System', 'placement.system.view', 90),
            $this->nav('public', 'Public', 'portal.access', 100),
        ];
    }

    private function route(string $name, string $method, string $controller, string $action): array
    {
        return compact('name', 'method', 'controller', 'action');
    }

    /** @param string|list<string> $capability */
    private function nav(string $route, string $label, string|array $capability, int $order, ?string $href = null): array
    {
        if (is_array($capability)) {
            return [
                'route' => $route,
                'label' => $label,
                'capabilities' => $capability,
                'order' => $order,
                'href' => $href,
            ];
        }
        return compact('route', 'label', 'capability', 'order', 'href');
    }
}
