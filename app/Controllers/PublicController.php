<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Placement\Application\PlacementService;
use App\Domain\Workflow;
use App\Support\Auth;

final class PublicController
{
    public function placements(): void
    {
        $user = Auth::user();
        view('public', [
            'placements' => (new PlacementService())->publicPlacements(),
            'studentLookupAllowed' => Auth::hasCapability($user, 'placement.board.view'),
        ]);
    }

    public function student(): void
    {
        $user = Auth::requireCapability('placement.board.view', 'Sign in with placement access to check candidate status.');
        $externalId = trim((string) ($_GET['external_id'] ?? ''));
        $studentData = $externalId !== '' ? (new PlacementService())->studentStatus($externalId, $user) : null;
        view('student', ['externalId' => $externalId, 'studentData' => $studentData, 'workflow' => new Workflow()]);
    }
}
