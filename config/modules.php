<?php

declare(strict_types=1);

return [
    'placement' => [
        'name' => 'Placement Operations',
        'version' => '0.1.0',
        'core_requires' => '>=0.1.0-alpha.1',
        'requires_modules' => [],
        'internal_event_observer_events' => [],
        'capabilities' => [
            'placement.board.view',
            'placement.application.transition',
            'placement.application.correct',
            'placement.records.view',
            'placement.records.manage',
            'placement.reports.view',
            'placement.import.manage',
            'placement.import.rollback',
            'placement.notifications.view',
            'placement.notifications.manage',
            'placement.preferences.view',
            'placement.preferences.manage',
            'placement.wanted.view',
            'placement.wanted.manage',
            'placement.system.view',
            'placement.workflow.manage',
            'placement.sensitive.view',
            'placement.accommodation.view',
            'placement.cross_company.view',
            'portal.integrations.manage',
        ],
        'enabled_by_default' => true,
        'description' => 'Live placement workflows, scheduling, movement, offers, and reporting.',
        'class' => \App\Modules\Placement\PlacementModule::class,
    ],
    'advising' => [
        'name' => 'Career Advising',
        'version' => '0.1.0',
        'core_requires' => '>=0.1.0-alpha.1',
        'requires_modules' => [],
        'internal_event_observer_events' => [
            'placement.offer.accepted',
        ],
        'capabilities' => [
            'advising.appointments.view',
            'advising.appointments.manage',
            'advising.notes.manage',
            'advising.tasks.manage',
        ],
        'enabled_by_default' => false,
        'description' => 'Appointments, staff notes, and career follow-up tasks.',
        'class' => \App\Modules\Advising\AdvisingModule::class,
    ],
];
