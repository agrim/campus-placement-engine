<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Api\Operations\ApiHealthService;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiScopePolicy;
use App\Api\Security\ApiServiceAccountService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Database;
use App\Support\Flash;

final class ApiAccessController
{
    public function show(): void
    {
        $this->user();
        $this->render();
    }

    public function create(): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $scopes = is_array($_POST['scopes'] ?? null) ? array_values($_POST['scopes']) : [];
            $created = $this->service()->create(
                (string) ($_POST['name'] ?? ''),
                $scopes,
                (int) $user['id'],
                (int) ($_POST['expiry_days'] ?? ApiServiceAccountService::DEFAULT_EXPIRY_DAYS),
            );
            Flash::add('success', 'Service account created. Copy its token now; it will not be shown again.');
            $this->render($created['token'], $created['service_account_id'], $created['expires_at']);
            return;
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_API_SERVICE_ACCOUNT_CREATE_FAILED', 'api.service_account.create');
        }
        redirect(url('api-access'));
    }

    public function rotate(): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $rotated = $this->service()->rotateToken(
                (string) ($_POST['service_account_id'] ?? ''),
                (int) $user['id'],
                (int) ($_POST['expiry_days'] ?? ApiServiceAccountService::DEFAULT_EXPIRY_DAYS),
            );
            Flash::add('success', 'Token rotated. The prior token remains usable for at most 24 hours.');
            $this->render($rotated['token'], $rotated['service_account_id'], $rotated['expires_at']);
            return;
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_API_TOKEN_ROTATE_FAILED', 'api.token.rotate');
        }
        redirect(url('api-access'));
    }

    public function revokeToken(): void
    {
        $this->mutate('token-revoke');
    }

    public function enableAccount(): void
    {
        $this->mutate('account-enable');
    }

    public function disableAccount(): void
    {
        $this->mutate('account-disable');
    }

    public function revokeAccount(): void
    {
        $this->mutate('account-revoke');
    }

    public function enableApi(): void
    {
        $this->mutate('api-enable');
    }

    public function disableApi(): void
    {
        $this->mutate('api-disable');
    }

    private function mutate(string $action): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $service = $this->service();
            match ($action) {
                'token-revoke' => $service->revokeToken((string) ($_POST['token_lookup_id'] ?? ''), (int) $user['id']),
                'account-enable' => $service->setAccountEnabled((string) ($_POST['service_account_id'] ?? ''), true, (int) $user['id']),
                'account-disable' => $service->setAccountEnabled((string) ($_POST['service_account_id'] ?? ''), false, (int) $user['id']),
                'account-revoke' => $service->revokeAccount((string) ($_POST['service_account_id'] ?? ''), (int) $user['id']),
                'api-enable' => $service->setApiEnabled(true, (int) $user['id']),
                'api-disable' => $service->setApiEnabled(false, (int) $user['id']),
            };
            Flash::add('success', match ($action) {
                'token-revoke' => 'Token revoked immediately.',
                'account-enable' => 'Service account enabled.',
                'account-disable' => 'Service account disabled immediately.',
                'account-revoke' => 'Service account and all tokens revoked.',
                'api-enable' => 'Institution-local API controls enabled. No public resource endpoint exists in this phase.',
                'api-disable' => 'Institution-local API disabled immediately.',
            });
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_API_LIFECYCLE_FAILED', 'api.' . $action);
        }
        redirect(url('api-access'));
    }

    private function render(?string $revealedToken = null, string $revealedFor = '', string $revealedExpiry = ''): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
        }
        $pdo = Database::connection();
        view('api-access', [
            'accounts' => $this->service()->listForAdministrator(),
            'health' => (new ApiHealthService($pdo))->snapshot(),
            'keyring' => ApiKeyring::environmentStatus(),
            'supportedScopes' => ApiScopePolicy::supportedScopes(),
            'revealedToken' => $revealedToken,
            'revealedFor' => $revealedFor,
            'revealedExpiry' => $revealedExpiry,
        ]);
    }

    private function service(): ApiServiceAccountService
    {
        return new ApiServiceAccountService(Database::connection());
    }

    private function user(): array
    {
        return Auth::requireCapability(
            'portal.integrations.manage',
            'Only users with integration-management capability can manage API access.',
        );
    }
}
