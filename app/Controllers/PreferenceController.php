<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Placement\Application\PlacementService;
use App\Security\Csrf;
use App\Support\Auth;
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
                throw new \RuntimeException('Your role cannot create preference requests.');
            }
            (new PlacementService())->createPreferenceRequest((int) ($_POST['candidate_id'] ?? 0), $_POST['company_ids'] ?? [], (int) $user['id'], trim((string) ($_POST['note'] ?? '')));
            Flash::add('success', 'Preference request created.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('preferences'));
    }

    public function resolve(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.preferences.manage')) {
                throw new \RuntimeException('Your role cannot resolve preference requests.');
            }
            (new PlacementService())->resolvePreferenceRequest((int) ($_POST['request_id'] ?? 0), (int) ($_POST['company_id'] ?? 0), (int) $user['id']);
            Flash::add('success', 'Preference decision recorded.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('preferences'));
    }
}
