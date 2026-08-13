<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Placement\Application\PlacementService;
use App\Security\Csrf;
use App\Support\Auth;
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
                throw new \RuntimeException('Your role cannot create wanted alerts.');
            }
            (new PlacementService())->createWantedAlert((int) ($_POST['candidate_id'] ?? 0), (string) ($_POST['reason'] ?? ''), (int) $user['id']);
            Flash::add('success', 'Wanted alert created.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('wanted'));
    }

    public function resolve(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.wanted.manage')) {
                throw new \RuntimeException('Your role cannot resolve wanted alerts.');
            }
            (new PlacementService())->resolveWantedAlert((int) ($_POST['alert_id'] ?? 0), (int) $user['id']);
            Flash::add('success', 'Wanted alert resolved.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('wanted'));
    }
}
