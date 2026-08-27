<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Modules\Placement\Application\PlacementService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Flash;

final class PreferenceController
{
    public function show(): void
    {
        Auth::requireCapability('placement.preferences.view', 'Your role cannot open Preferences.');
        $service = new PlacementService();
        view('preferences', [
            'requests' => $service->preferenceRequests(),
            'candidates' => $service->candidates(),
            'companies' => $service->companies(),
            'service' => $service,
        ]);
    }

    public function create(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.preferences.manage')) {
                throw new UserVisibleException('PREFERENCE_CREATE_FORBIDDEN', 'Your role cannot create preference requests.');
            }
            (new PlacementService())->createPreferenceRequest((int) ($_POST['candidate_id'] ?? 0), $_POST['company_ids'] ?? [], (int) $user['id'], trim((string) ($_POST['note'] ?? '')));
            Flash::add('success', 'Preference request created.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_PREFERENCE_CREATE_FAILURE', 'preference.create');
        }
        redirect(url('preferences'));
    }

    public function resolve(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.preferences.manage')) {
                throw new UserVisibleException('PREFERENCE_RESOLVE_FORBIDDEN', 'Your role cannot resolve preference requests.');
            }
            (new PlacementService())->resolvePreferenceRequest((int) ($_POST['request_id'] ?? 0), (int) ($_POST['company_id'] ?? 0), (int) $user['id']);
            Flash::add('success', 'Preference decision recorded.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_PREFERENCE_RESOLVE_FAILURE', 'preference.resolve');
        }
        redirect(url('preferences'));
    }
}
