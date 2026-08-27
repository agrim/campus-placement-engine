<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Modules\Placement\Application\PlacementService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Flash;

final class WantedController
{
    public function show(): void
    {
        Auth::requireCapability('placement.wanted.view', 'Your role cannot open Wanted.');
        $service = new PlacementService();
        view('wanted', ['alerts' => $service->wantedAlerts(), 'candidates' => $service->candidates()]);
    }

    public function create(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.wanted.manage')) {
                throw new UserVisibleException('WANTED_CREATE_FORBIDDEN', 'Your role cannot create wanted alerts.');
            }
            (new PlacementService())->createWantedAlert((int) ($_POST['candidate_id'] ?? 0), (string) ($_POST['reason'] ?? ''), (int) $user['id']);
            Flash::add('success', 'Wanted alert created.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_WANTED_CREATE_FAILURE', 'wanted.create');
        }
        redirect(url('wanted'));
    }

    public function resolve(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.wanted.manage')) {
                throw new UserVisibleException('WANTED_RESOLVE_FORBIDDEN', 'Your role cannot resolve wanted alerts.');
            }
            (new PlacementService())->resolveWantedAlert((int) ($_POST['alert_id'] ?? 0), (int) $user['id']);
            Flash::add('success', 'Wanted alert resolved.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_WANTED_RESOLVE_FAILURE', 'wanted.resolve');
        }
        redirect(url('wanted'));
    }
}
