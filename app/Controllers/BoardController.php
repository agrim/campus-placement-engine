<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Domain\Workflow;
use App\Modules\Placement\Application\PlacementService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Flash;

final class BoardController
{
    public function index(): void
    {
        $user = Auth::requireCapability('placement.board.view', 'Your role cannot open the placement board.');
        $service = new PlacementService();
        $requestFilters = $this->filtersFromRequest($_GET);
        $savedPreference = $service->boardPreferenceForUser((int) $user['id']);
        $savedFilters = $this->filterSubset($savedPreference);
        $usingSavedFilters = !$this->hasExplicitFilterRequest($_GET) && $savedPreference !== [];
        $filters = $usingSavedFilters ? $savedFilters : $requestFilters;
        view('board', [
            'user' => $user,
            'workflow' => new Workflow(),
            'groups' => $service->dashboard($user, $filters),
            'stats' => $service->stats(),
            'roleContext' => $service->roleContext($user),
            'filters' => $filters,
            'filterOptions' => $service->boardFilterOptions($user),
            'boardViews' => $service->boardViewPresets($user),
            'boardCardFields' => array_fill_keys($service->boardCardFields(), true),
            'boardRefreshSeconds' => $service->boardRefreshSeconds(),
            'savedFilters' => $savedFilters,
            'savedPreference' => $savedPreference,
            'staleMinutes' => $service->staleMinutesForUser((int) $user['id']),
            'usingSavedFilters' => $usingSavedFilters,
            'wantedByCandidate' => $service->openWantedAlertsByCandidate(),
        ]);
    }

    public function move(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.application.transition')) {
                throw new UserVisibleException('BOARD_TRANSITION_FORBIDDEN', 'Auditors cannot change candidate status.');
            }
            $service = new PlacementService();
            $applicationId = (int) ($_POST['application_id'] ?? 0);
            $outcome = $service->applyBoardMove(
                $applicationId,
                (int) $user['id'],
                (string) $user['role'],
                trim((string) ($_POST['to_status'] ?? '')),
                trim((string) ($_POST['transition_key'] ?? '')),
                trim((string) ($_POST['note'] ?? '')),
                trim((string) ($_POST['expected_status'] ?? '')),
                (string) ($_POST['idempotency_key'] ?? ''),
                $user
            );
            if ($outcome['duplicate']) {
                Flash::add('success', 'Duplicate move ignored.');
                redirect('/');
            }
            Flash::add('success', 'Moved to ' . (new Workflow())->statusLabel($outcome['status']) . '.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_BOARD_MOVE_FAILURE', 'board.move');
        }
        redirect('/');
    }

    public function returnToIdle(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.application.correct')) {
                throw new UserVisibleException('BOARD_CORRECTION_FORBIDDEN', 'This user cannot correct candidate status.');
            }
            $service = new PlacementService();
            $applicationId = (int) ($_POST['application_id'] ?? 0);
            $outcome = $service->applyBoardReturnToIdle(
                $applicationId,
                (int) $user['id'],
                (string) $user['role'],
                trim((string) ($_POST['reason'] ?? 'operator_return')),
                trim((string) ($_POST['note'] ?? '')),
                trim((string) ($_POST['expected_status'] ?? '')),
                (string) ($_POST['idempotency_key'] ?? ''),
                $user
            );
            if ($outcome['duplicate']) {
                Flash::add('success', 'Duplicate return ignored.');
                redirect('/');
            }
            Flash::add('success', 'Returned application to idle.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_BOARD_CORRECTION_FAILURE', 'board.return_to_idle');
        }
        redirect('/');
    }

    public function preferences(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $service = new PlacementService();
            $action = (string) ($_POST['preference_action'] ?? 'save');
            if ($action === 'clear') {
                $service->clearBoardPreference((int) $user['id']);
                Flash::add('success', 'Board default cleared.');
            } else {
                $service->saveBoardPreference((int) $user['id'], $this->preferenceFromRequest($_POST));
                Flash::add('success', 'Board default saved.');
            }
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_BOARD_PREFERENCE_FAILURE', 'board.preferences');
        }
        redirect('/');
    }

    private function filtersFromRequest(array $source): array
    {
        $flag = (string) ($source['flag'] ?? '');
        if (!in_array($flag, ['', 'wanted', 'preference', 'opted_out', 'waitlist', 'stale', 'conflict'], true)) {
            $flag = '';
        }
        return [
            'q' => trim((string) ($source['q'] ?? '')),
            'company' => strtoupper(trim((string) ($source['company'] ?? ''))),
            'status' => trim((string) ($source['status'] ?? '')),
            'flag' => $flag,
            'actionable' => !empty($source['actionable']) ? '1' : '',
            'compact' => !empty($source['compact']) ? '1' : '',
        ];
    }

    private function preferenceFromRequest(array $source): array
    {
        return [
            ...$this->filtersFromRequest($source),
            'stale_minutes' => (string) ($source['stale_minutes'] ?? ''),
        ];
    }

    private function filterSubset(array $preference): array
    {
        if ($preference === []) {
            return [];
        }
        return array_intersect_key($preference, array_flip(['q', 'company', 'status', 'flag', 'actionable', 'compact']));
    }

    private function hasExplicitFilterRequest(array $source): bool
    {
        foreach (['view', 'q', 'company', 'status', 'flag', 'actionable', 'compact'] as $key) {
            if (array_key_exists($key, $source)) {
                return true;
            }
        }
        return false;
    }
}
