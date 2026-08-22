<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Modules\ModuleLifecycleService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;

final class ModuleController
{
    public function show(): void
    {
        $user = Auth::requireCapability('portal.modules.manage', 'Only module administrators can manage modules.');
        $pdo = Database::connection();
        view('modules', [
            'user' => $user,
            'modules' => (new ModuleLifecycleService($pdo))->modules(),
            'events' => $pdo->query('SELECT * FROM module_lifecycle_events ORDER BY id DESC LIMIT 50')->fetchAll(),
        ]);
    }

    public function update(): void
    {
        $user = Auth::requireCapability('portal.modules.manage', 'Only module administrators can manage modules.');
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $moduleKey = strtolower(trim((string) ($_POST['module_key'] ?? '')));
            $action = (string) ($_POST['module_action'] ?? '');
            $service = new ModuleLifecycleService(Database::connection());
            match ($action) {
                'enable' => $service->enable($moduleKey, (int) $user['id']),
                'disable' => $service->disable($moduleKey, (int) $user['id']),
                default => throw new \RuntimeException('Unknown module action.'),
            };
            Flash::add('success', ucfirst($moduleKey) . ' module ' . $action . 'd.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('modules'));
    }
}
