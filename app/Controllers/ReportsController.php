<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Placement\Application\PlacementService;
use App\Modules\Placement\Application\UniversityOperationsWorkspace;
use App\Support\Auth;

final class ReportsController
{
    public function show(): void
    {
        Auth::requireCapability('placement.reports.view', 'Your role cannot open Reports.');
        view('reports', ['summary' => (new PlacementService())->placementSummary()]);
    }

    public function operations(): void
    {
        $user = Auth::requireCapability(
            'placement.reports.view',
            'Your role cannot open the university operations workspace.',
        );
        Auth::requireCapability(
            'placement.sensitive.view',
            'Candidate-level operations require sensitive-record access.',
        );
        $includeAdvising = cpe_context()->modules()->isEnabled('advising')
            && Auth::hasCapability($user, 'advising.tasks.manage');
        view('operations', [
            'workspace' => (new UniversityOperationsWorkspace())->snapshot($includeAdvising),
            'actionAccess' => [
                'candidate' => Auth::hasCapability($user, 'placement.board.view'),
                'records' => Auth::hasCapability($user, 'placement.records.manage'),
                'advising' => $includeAdvising,
            ],
        ]);
    }
}
