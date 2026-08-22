<?php

declare(strict_types=1);

namespace App\Modules\Advising;

use App\Core\Events\DomainEvent;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleLifecycleHooks;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModulePortabilityHandler;
use App\Core\Modules\ModulePrivacyHandler;
use App\Core\Modules\ProvidesEventSubscribers;
use App\Core\Modules\ProvidesPortability;
use App\Core\Modules\ProvidesPrivacy;
use App\Modules\Advising\Application\AdvisingService;
use App\Modules\Advising\Http\AdvisingController;
use App\Modules\Advising\Portability\AdvisingPortabilityHandler;
use App\Modules\Advising\Privacy\AdvisingPrivacyHandler;

final class AdvisingModule implements Module, ModuleLifecycleHooks, ProvidesEventSubscribers, ProvidesPortability, ProvidesPrivacy
{
    public function key(): string
    {
        return 'advising';
    }

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromArray($this->key(), cpe_config('modules.' . $this->key(), []));
    }

    public function routes(): array
    {
        return [
            $this->route('advising', 'GET', 'show'),
            $this->route('advising-appointment', 'POST', 'createAppointment'),
            $this->route('advising-status', 'POST', 'updateStatus'),
            $this->route('advising-note', 'POST', 'addNote'),
            $this->route('advising-task', 'POST', 'completeTask'),
        ];
    }

    public function navigation(): array
    {
        return [[
            'route' => 'advising',
            'label' => 'Advising',
            'capability' => 'advising.appointments.view',
            'order' => 15,
        ]];
    }

    public function eventSubscribers(): array
    {
        return [
            'placement.offer.accepted' => [
                static fn (DomainEvent $event) => (new AdvisingService())->recordOfferFollowUp($event),
            ],
        ];
    }

    public function portabilityHandler(): ModulePortabilityHandler
    {
        return new AdvisingPortabilityHandler();
    }

    public function privacyHandler(): ModulePrivacyHandler
    {
        return new AdvisingPrivacyHandler();
    }

    public function onInstall(): void
    {
    }

    public function onEnable(): void
    {
    }

    public function onDisable(): void
    {
    }

    private function route(string $name, string $method, string $action): array
    {
        return ['name' => $name, 'method' => $method, 'controller' => AdvisingController::class, 'action' => $action];
    }
}
