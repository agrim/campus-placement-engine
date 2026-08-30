<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Core\Install\InstallationState;
use App\Core\Install\InstallationStateUnavailable;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Security\Csrf;
use App\Security\SetupAuthorization;
use App\Security\SetupAuthorizationDenied;
use App\Security\SetupHttp;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;
use App\Support\IncidentReporter;

final class InstallController
{
    public function show(SetupAuthorization $authorization): void
    {
        if (($authorization->accessState()['state'] ?? '') !== SetupAuthorization::ACCESS_AUTHORIZED) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }
        $state = $this->installationState($authorization);
        if ($state['state'] === InstallationState::INSTALLED) {
            redirect('/', 303);
        }
        $requirements = new SystemRequirements();
        view('install', [
            'requirements' => $requirements->checks(),
            'requirementsOk' => $requirements->isReady(),
        ]);
    }

    public function install(SetupAuthorization $authorization): void
    {
        if (($authorization->accessState()['state'] ?? '') !== SetupAuthorization::ACCESS_AUTHORIZED) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }
        try {
            $state = $this->installationState($authorization);
            if ($state['state'] === InstallationState::INSTALLED) {
                throw new UserVisibleException('SETUP_ALREADY_COMPLETE', 'Setup is already complete.');
            }
            $csrf = $_POST['_token'] ?? null;
            try {
                Csrf::verify(is_string($csrf) ? $csrf : null);
            } catch (\Throwable $e) {
                throw new UserVisibleException(
                    'SETUP_FORM_EXPIRED',
                    'The setup form expired. Please retry.',
                    $e,
                );
            }
            (new SystemRequirements())->assertReady();
            $input = SetupHttp::installInput($_POST);
            $adminId = $authorization->runAuthorized(
                static fn (): int => (new Installer())->install($input, $state['authority']),
            );
            Auth::loginById($adminId);
            Flash::add('success', !empty($input['seed_demo']) ? 'Installation complete. Dummy placement drive is ready.' : 'Installation complete.');
            redirect('/', 303);
        } catch (SetupAuthorizationDenied $e) {
            throw $e;
        } catch (InstallationStateUnavailable $e) {
            throw $e;
        } catch (UserVisibleException $e) {
            Flash::add('error', $e->publicMessage());
            redirect('/install.php', 303);
        } catch (\Throwable $e) {
            $incidentId = IncidentReporter::report(
                $e,
                'CPE_SETUP_INSTALL_FAILED',
                'setup',
                ['operation' => 'install'],
            );
            Flash::add('error', 'Installation failed. Reference: ' . $incidentId);
            redirect('/install.php', 303);
        }
    }

    /** @return array{state: string, authority: ?\App\Security\SetupRecoveryAuthority} */
    private function installationState(SetupAuthorization $authorization): array
    {
        try {
            return ['state' => Database::installationStateStrict(), 'authority' => null];
        } catch (InstallationStateUnavailable) {
            $authority = $authorization->issueRecoveryAuthority();
            return [
                'state' => Database::installationStateForAuthorizedSetupStrict($authority),
                'authority' => $authority,
            ];
        }
    }
}
