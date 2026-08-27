<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Modules\Placement\Application\PlacementService;
use App\Domain\Workflow;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Flash;

final class RecordsController
{
    public function show(): void
    {
        Auth::requireCapability('placement.records.view', 'Your role cannot open Records.');
        $service = new PlacementService();
        view('records', [
            'candidates' => $service->candidates(),
            'companies' => $service->companies(),
            'applications' => $service->applications(),
            'rounds' => $service->companyRounds(),
            'schedules' => $service->roundSchedules(),
            'panelists' => $service->roundPanelists(),
            'assignments' => $service->applicationSlotAssignments(),
            'workflow' => new Workflow(),
        ]);
    }

    public function saveCandidate(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_CANDIDATE_FORBIDDEN', 'Your role cannot edit candidates.');
            }
            (new PlacementService())->saveCandidate($_POST, (int) $user['id']);
            Flash::add('success', 'Candidate saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_CANDIDATE_FAILURE', 'record.candidate');
        }
        redirect(url('records'));
    }

    public function saveCompany(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_COMPANY_FORBIDDEN', 'Your role cannot edit companies.');
            }
            (new PlacementService())->saveCompany($_POST, (int) $user['id']);
            Flash::add('success', 'Company saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_COMPANY_FAILURE', 'record.company');
        }
        redirect(url('records'));
    }

    public function saveRound(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_ROUND_FORBIDDEN', 'Your role cannot edit company rounds.');
            }
            (new PlacementService())->saveCompanyRound($_POST, (int) $user['id']);
            Flash::add('success', 'Company round saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_ROUND_FAILURE', 'record.round');
        }
        redirect(url('records'));
    }

    public function savePanelist(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_PANELIST_FORBIDDEN', 'Your role cannot edit round panelists.');
            }
            (new PlacementService())->saveRoundPanelist($_POST, (int) $user['id']);
            Flash::add('success', 'Round panelist saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_PANELIST_FAILURE', 'record.panelist');
        }
        redirect(url('records'));
    }

    public function saveSchedule(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_SCHEDULE_FORBIDDEN', 'Your role cannot edit round schedules.');
            }
            (new PlacementService())->saveRoundSchedule($_POST, (int) $user['id']);
            Flash::add('success', 'Round schedule saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_SCHEDULE_FAILURE', 'record.schedule');
        }
        redirect(url('records'));
    }

    public function saveSlotAssignment(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_ASSIGNMENT_FORBIDDEN', 'Your role cannot edit slot assignments.');
            }
            (new PlacementService())->saveApplicationSlotAssignment($_POST, (int) $user['id']);
            Flash::add('success', 'Interview slot assignment saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_ASSIGNMENT_FAILURE', 'record.assignment');
        }
        redirect(url('records'));
    }

    public function saveApplication(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.records.manage')) {
                throw new UserVisibleException('RECORD_APPLICATION_FORBIDDEN', 'Your role cannot edit shortlists.');
            }
            $rank = trim((string) ($_POST['waitlist_rank'] ?? ''));
            (new PlacementService())->saveApplication(
                (int) ($_POST['candidate_id'] ?? 0),
                (int) ($_POST['company_id'] ?? 0),
                (string) ($_POST['status'] ?? 'scheduled'),
                $rank === '' ? null : (int) $rank,
                (int) $user['id']
            );
            Flash::add('success', 'Shortlist/application saved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_RECORD_APPLICATION_FAILURE', 'record.application');
        }
        redirect(url('records'));
    }
}
