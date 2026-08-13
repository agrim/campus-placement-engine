<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;

final class InstallController
{
    public function show(): void
    {
        if (Database::isInstalled()) {
            redirect('/');
        }
        $requirements = new SystemRequirements();
        view('install', [
            'requirements' => $requirements->checks(),
            'requirementsOk' => $requirements->isReady(),
            'databasePath' => Database::path(),
        ]);
    }

    public function install(): void
    {
        try {
            Csrf::verify($_POST['_token'] ?? null);
            (new SystemRequirements())->assertReady();
            $adminId = (new Installer())->install($_POST);
            Auth::loginById($adminId);
            Flash::add('success', !empty($_POST['seed_demo']) ? 'Installation complete. Dummy placement drive is ready.' : 'Installation complete.');
            redirect('/');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
            redirect('/install.php');
        }
    }
}
