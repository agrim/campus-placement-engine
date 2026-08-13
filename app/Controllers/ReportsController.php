<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Placement\Application\PlacementService;
use App\Support\Auth;

final class ReportsController
{
    public function show(): void
    {
        Auth::requireCapability('placement.reports.view', 'Your role cannot open Reports.');
        view('reports', ['summary' => (new PlacementService())->placementSummary()]);
    }
}
