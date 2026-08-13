<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Placement\Application\PlacementService;
use App\Domain\Workflow;
use App\Support\Auth;
use App\Support\Flash;

final class CandidateController
{
    public function show(): void
    {
        $user = Auth::requireCapability('placement.board.view', 'Your role cannot open placement candidate records.');
        $data = (new PlacementService())->candidate((int) ($_GET['id'] ?? 0), $user);
        if (!$data) {
            Flash::add('error', 'Candidate not found.');
            redirect('/');
        }
        view('candidate', ['candidateData' => $data, 'workflow' => new Workflow()]);
    }
}
