<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Modules\ModuleLifecycleService;
use App\Support\Auth;
use App\Support\Database;

final class PortalController
{
    public function home(): void
    {
        $user = Auth::requireUser();
        if (cpe_context()->modules()->isEnabled('placement')) {
            redirect(url('board'));
        }
        view('portal', [
            'user' => $user,
            'modules' => (new ModuleLifecycleService(Database::connection()))->modules(),
        ]);
    }
}
