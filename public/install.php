<?php

declare(strict_types=1);

define('CPE_SETUP_HTTP_REQUEST', true);
define('CPE_DEFER_HTTP_SESSION', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\InstallController;
use App\Hosted\HostedContext;
use App\Security\Csrf;
use App\Security\SetupAuthorization;
use App\Security\SetupAuthorizationDenied;
use App\Security\SetupHttp;
use App\Support\Database;
use App\Support\IncidentReporter;

function cpe_setup_not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";
    exit;
}

function cpe_setup_hosted_unavailable(): never
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Hosted setup is unavailable.\n";
    exit;
}

function cpe_setup_session_unavailable(): never
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Browser setup requires file-backed sessions before installation. Run php placement install from the CLI, then start the site.\n";
    exit;
}

function cpe_setup_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function cpe_setup_installed_conflict(): never
{
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Setup is already complete.\n";
    exit;
}

function cpe_setup_unexpected(Throwable $exception, string $diagnosticCode, string $operation): never
{
    $incidentId = IncidentReporter::report(
        $exception,
        $diagnosticCode,
        'setup',
        ['operation' => $operation],
    );
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Setup failed. Reference: ' . $incidentId . "\n";
    exit;
}

$methodValue = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$method = is_string($methodValue) ? strtoupper($methodValue) : '';
try {
    if (HostedContext::isActive()) {
        cpe_setup_hosted_unavailable();
    }

    if (Database::isInstalled()) {
        if ($method === 'GET' || $method === 'HEAD') {
            cpe_setup_redirect('/');
        }
        if ($method === 'POST') {
            cpe_setup_installed_conflict();
        }
        cpe_setup_not_found();
    }
    if ($method !== 'GET' && $method !== 'POST') {
        cpe_setup_not_found();
    }

    try {
        cpe_start_setup_session();
    } catch (Throwable $e) {
        IncidentReporter::report(
            $e,
            'CPE_SETUP_SESSION_UNAVAILABLE',
            'setup',
            ['operation' => 'session'],
        );
        cpe_setup_session_unavailable();
    }

    $configuredToken = getenv('CPE_SETUP_TOKEN');
    $environmentToken = $configuredToken === false ? null : $configuredToken;
    $localCapabilityMode = SetupHttp::localCapabilityModeAvailable($_SERVER);
    $configuredLocalCapability = getenv(SetupHttp::INTERNAL_CAPABILITY_ENV);
    $localCapability = is_string($configuredLocalCapability) ? $configuredLocalCapability : null;
    try {
        $authorization = new SetupAuthorization(
            environmentToken: $environmentToken,
            localCapability: $localCapability,
        );
        $access = $authorization->accessState();
    } catch (SetupAuthorizationDenied) {
        cpe_setup_not_found();
    } catch (Throwable $e) {
        IncidentReporter::report(
            $e,
            'CPE_SETUP_AUTHORIZATION_FAILED',
            'setup',
            ['operation' => 'authorization'],
        );
        cpe_setup_not_found();
    }

    $authorized = ($access['state'] ?? '') === SetupAuthorization::ACCESS_AUTHORIZED;
    if ($authorized
        && ($access['mode'] ?? null) === SetupAuthorization::MODE_LOCAL
        && !SetupHttp::localTopologyAllowed($_SERVER)) {
        cpe_setup_not_found();
    }
    if ($method === 'GET') {
        if ($authorized) {
            (new InstallController())->show($authorization);
            exit;
        }
        if (!$localCapabilityMode && $environmentToken === null) {
            cpe_setup_not_found();
        }
        view('setup-unlock', [
            'setupMode' => $localCapabilityMode
                ? SetupAuthorization::MODE_LOCAL
                : SetupAuthorization::MODE_ENVIRONMENT_TOKEN,
        ]);
        exit;
    }

    $action = $_POST['_setup_action'] ?? null;
    if (!is_string($action)) {
        cpe_setup_not_found();
    }
    if ($action === 'unlock') {
        if ($authorized) {
            cpe_setup_redirect('/install.php');
        }
        if (!$localCapabilityMode && $environmentToken === null) {
            cpe_setup_not_found();
        }
        try {
            $csrf = $_POST['_token'] ?? null;
            try {
                Csrf::verify(is_string($csrf) ? $csrf : null);
            } catch (Throwable) {
                throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_CREDENTIAL);
            }
            if ($localCapabilityMode) {
                $authorization->authorizeLocalCapability($_POST['setup_token'] ?? null);
            } else {
                if (!SetupHttp::environmentUnlockTransportAllowed($_SERVER)) {
                    throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_CREDENTIAL);
                }
                $authorization->unlockWithEnvironmentToken($_POST['setup_token'] ?? null);
            }
        } catch (SetupAuthorizationDenied) {
            cpe_setup_not_found();
        } catch (Throwable $e) {
            IncidentReporter::report(
                $e,
                'CPE_SETUP_UNLOCK_FAILED',
                'setup',
                ['operation' => 'unlock'],
            );
            cpe_setup_not_found();
        }
        cpe_setup_redirect('/install.php');
    }
    if ($action === 'install') {
        if (!$authorized) {
            cpe_setup_not_found();
        }
        try {
            (new InstallController())->install($authorization);
        } catch (SetupAuthorizationDenied) {
            cpe_setup_not_found();
        }
        exit;
    }
    cpe_setup_not_found();
} catch (SetupAuthorizationDenied) {
    cpe_setup_not_found();
} catch (Throwable $e) {
    cpe_setup_unexpected($e, 'CPE_SETUP_REQUEST_FAILED', 'request');
}
