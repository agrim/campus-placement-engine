<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Core\Institution\InstitutionRepository;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;

/** Exact API scope grants mapped to durable, enabled Engine capabilities. */
final class ApiScopePolicy
{
    private const SCOPE_CAPABILITIES = [
        'opportunities.read' => 'placement.records.view',
        'applications.read' => 'placement.records.view',
        'applications.transition' => 'placement.application.transition',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public static function supportedScopes(): array
    {
        return array_keys(self::SCOPE_CAPABILITIES);
    }

    public function allows(ApiPrincipal $principal, string $scope): bool
    {
        if (!isset(self::SCOPE_CAPABILITIES[$scope]) || !in_array($scope, $principal->scopes(), true)) {
            return false;
        }
        try {
            $institution = (new InstitutionRepository($this->pdo))->current();
            if ($principal->institutionId() !== $institution->id()
                || !hash_equals($institution->publicId(), $principal->institutionPublicId())) {
                return false;
            }
            $setting = $this->pdo->prepare("SELECT value FROM settings WHERE key = 'api_enabled'");
            $setting->execute();
            if ((string) $setting->fetchColumn() !== '1') {
                return false;
            }
            $account = $this->pdo->prepare(
                'SELECT COUNT(*) FROM api_service_accounts account
                 JOIN api_service_account_scopes grant_row ON grant_row.service_account_id = account.id
                 WHERE account.id = ? AND account.public_id = ? AND account.institution_id = ?
                   AND account.status = ? AND account.disabled_at IS NULL AND account.revoked_at IS NULL
                   AND grant_row.scope = ?',
            );
            $account->execute([
                $principal->serviceAccountId(),
                $principal->serviceAccountPublicId(),
                $principal->institutionId(),
                'enabled',
                $scope,
            ]);
            if ((int) $account->fetchColumn() !== 1) {
                return false;
            }
            return $this->catalogAllows($scope);
        } catch (ApiAuthorizationUnavailable $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new ApiAuthorizationUnavailable('API authorization state is unavailable.', 0, $failure);
        }
    }

    /** @param list<string> $scopes */
    public function assertProvisionable(array $scopes): void
    {
        if ($scopes === [] || count($scopes) !== count(array_unique($scopes))) {
            throw new \App\Core\Http\UserVisibleException(
                'API_SCOPES_INVALID',
                'Choose one or more distinct supported API scopes.',
            );
        }
        foreach ($scopes as $scope) {
            if (!isset(self::SCOPE_CAPABILITIES[$scope])) {
                throw new \App\Core\Http\UserVisibleException(
                    'API_SCOPE_UNSUPPORTED',
                    'The requested API scope is not supported.',
                );
            }
            if (!$this->catalogAllows($scope)) {
                throw new ApiAuthorizationUnavailable('Required API scope capability state is unavailable.');
            }
        }
    }

    private function catalogAllows(string $scope): bool
    {
        try {
            $modules = new ModuleRegistry((array) cpe_config('modules', []), $this->pdo);
            if (!$modules->isEnabled('placement')) {
                return false;
            }
            $capability = self::SCOPE_CAPABILITIES[$scope] ?? '';
            $query = $this->pdo->prepare(
                'SELECT COUNT(*) FROM role_capabilities WHERE capability = ?',
            );
            $query->execute([$capability]);
            return (int) $query->fetchColumn() > 0;
        } catch (Throwable $failure) {
            throw new ApiAuthorizationUnavailable('Required API scope capability state is unavailable.', 0, $failure);
        }
    }
}
