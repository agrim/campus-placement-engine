<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Http\UserVisibleException;
use App\Core\Install\InstallationState;
use App\Core\Install\InstallationStateUnavailable;
use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Persistence\DatabaseLock;
use App\Core\Persistence\DatabaseLockException;
use App\Core\Persistence\TransactionRollbackGuard;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Application\PlacementService;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Security\SetupRecoveryAuthority;
use App\Support\Auth;
use App\Support\Database;
use App\Support\TimezoneValidator;
use PDO;
use RuntimeException;
use Throwable;

final class Installer
{
    public const HOSTED_INSTALL_CONTRACT_VERSION = 1;
    public const ERROR_ALREADY_INSTALLED = 'CPE_INSTALLATION_ALREADY_COMPLETED';
    public const LOCK_NAMESPACE = 'cpe.engine-installation';

    private const LOCK_TIMEOUT_MILLISECONDS = 60000;

    public function __construct(private readonly ?InstallationStepObserver $stepObserver = null)
    {
    }

    public function install(array $input, ?SetupRecoveryAuthority $recoveryAuthority = null): int
    {
        return $this->performInstall($input, null, $recoveryAuthority);
    }

    /**
     * Installs a hosted data plane and binds its immutable tenant identity in
     * the same transaction as the installation marker.
     */
    public function installHosted(
        array $input,
        string $tenantPublicId,
        ?SetupRecoveryAuthority $recoveryAuthority = null,
    ): int
    {
        if (preg_match('/^tenant_[a-f0-9]{32}$/', $tenantPublicId) !== 1) {
            throw new RuntimeException('Hosted tenant public ID is invalid.');
        }
        return $this->performInstall($input, $tenantPublicId, $recoveryAuthority);
    }

    private function performInstall(
        array $input,
        ?string $tenantPublicId,
        ?SetupRecoveryAuthority $recoveryAuthority,
    ): int
    {
        $college = trim((string) ($input['college_name'] ?? ''));
        try {
            $timezone = TimezoneValidator::normalize((string) ($input['timezone'] ?? 'Asia/Kolkata'), 'Asia/Kolkata');
        } catch (Throwable $e) {
            throw new UserVisibleException(
                'SETUP_TIMEZONE_INVALID',
                'Timezone must be a valid IANA timezone such as Asia/Kolkata.',
                $e,
            );
        }
        $name = trim((string) ($input['admin_name'] ?? ''));
        $email = trim((string) ($input['admin_email'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');

        if ($college === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new UserVisibleException(
                'SETUP_ADMIN_DETAILS_INVALID',
                'College, admin name, valid email, and an 8+ character password are required.',
            );
        }

        // Reject a capability replayed against a different configured target,
        // then classify it while a missing SQLite target can still be proven
        // fresh without creating it. The same target is classified again under
        // the installation lock, where the result is authoritative for
        // concurrent installers.
        $recoveryAuthority?->assertCurrentTarget();
        if ($recoveryAuthority !== null) {
            $this->claimInstallTarget($recoveryAuthority);
        }
        (new SystemRequirements())->assertReady();
        $pdo = Database::connection();
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $institutionPublicId = $tenantPublicId ?? 'inst_' . bin2hex(random_bytes(16));

        try {
            return DatabaseLock::synchronized(
                $pdo,
                self::LOCK_NAMESPACE,
                function (?string $lockBackendPid) use (
                    $pdo,
                    $driver,
                    $input,
                    $tenantPublicId,
                    $institutionPublicId,
                    $college,
                    $timezone,
                    $name,
                    $email,
                    $password,
                    $recoveryAuthority,
                ): int {
                // Serialize the complete fresh-target classification, migration,
                // and marker commit. Otherwise a second installer can observe the
                // exact Engine-owned markerless window created by its winner and
                // return an ambiguous setup-state error instead of the stable
                // already-installed conflict.
                $claimedState = $this->claimInstallTarget($recoveryAuthority);
                if ($driver === 'pgsql') {
                    if ($lockBackendPid === null) {
                        throw DatabaseLockException::sessionChanged();
                    }
                    DatabaseLock::assertPostgresSession($pdo, $lockBackendPid);
                }
                Database::migrate(false);
                if (Database::connection() !== $pdo) {
                    throw DatabaseLockException::sessionChanged();
                }
                if ($driver === 'pgsql') {
                    DatabaseLock::assertPostgresSession($pdo, (string) $lockBackendPid);
                }
                $this->assertClaimedInstallAvailable($claimedState, $recoveryAuthority);
                $started = false;
                try {
                    $pdo->beginTransaction();
                    $started = true;
                    if ($driver === 'pgsql') {
                        if ($lockBackendPid === null) {
                            throw DatabaseLockException::sessionChanged();
                        }
                        DatabaseLock::assertPostgresSession($pdo, $lockBackendPid);
                    }
                    $this->assertClaimedInstallAvailable($claimedState, $recoveryAuthority);

                    // Installation runs every post-migration synchronizer inside
                    // the same transaction as the marker and identity check.
                    $this->set($pdo, 'college_name', $college);
                    $this->set($pdo, 'site_name', $this->normalizeIdentityText((string) ($input['site_name'] ?? cpe_config('settings.site_name', cpe_config('app.name'))), (string) cpe_config('app.name'), 80, 'Site name'));
                    $this->set($pdo, 'site_tagline', $this->normalizeIdentityText((string) ($input['site_tagline'] ?? cpe_config('settings.site_tagline', '')), '', 120, 'Site tagline', true));
                    $this->set($pdo, 'public_placements_title', $this->normalizeIdentityText((string) ($input['public_placements_title'] ?? cpe_config('settings.public_placements_title', 'Public Placements')), 'Public Placements', 80, 'Public placements title'));
                    $this->set($pdo, 'candidate_status_title', $this->normalizeIdentityText((string) ($input['candidate_status_title'] ?? cpe_config('settings.candidate_status_title', '')), '', 80, 'Candidate status title', true));
                    $this->set($pdo, 'timezone', $timezone);
                    $this->set($pdo, 'cycle_name', trim((string) ($input['cycle_name'] ?? $college . ' Placement Cycle')));
                    $this->set($pdo, 'cycle_type', $this->normalizeCycleType((string) ($input['cycle_type'] ?? 'final')));
                    $this->set($pdo, 'cycle_start_date', $this->normalizeDate((string) ($input['cycle_start_date'] ?? '')));
                    $this->set($pdo, 'cycle_end_date', $this->normalizeDate((string) ($input['cycle_end_date'] ?? '')));
                    $this->set($pdo, 'calendar_non_operating_weekdays', $this->normalizeWeekdayList((string) ($input['calendar_non_operating_weekdays'] ?? cpe_config('settings.calendar_non_operating_weekdays', ''))));
                    $this->set($pdo, 'calendar_non_operating_dates', $this->normalizeDateList((string) ($input['calendar_non_operating_dates'] ?? cpe_config('settings.calendar_non_operating_dates', ''))));
                    $this->set($pdo, 'audit_request_metadata', $this->normalizeAuditRequestMetadata((string) ($input['audit_request_metadata'] ?? cpe_config('settings.audit_request_metadata', 'none'))));
                    $workflow = (string) ($input['workflow'] ?? 'default');
                    if (!array_key_exists($workflow, cpe_config('workflows'))) {
                        $workflow = 'default';
                    }
                    $this->set($pdo, 'workflow', $workflow);
                    $this->set($pdo, 'scheduling_buffer_minutes', '0');
                    $this->set($pdo, 'slot_planner_strategy', 'sequence');
                    $this->set($pdo, 'slot_optimizer_exact_limit', '10');
                    $this->set($pdo, 'configuration_freeze', (string) cpe_config('settings.configuration_freeze', '0'));
                    $this->set($pdo, 'terminology_candidate_label', $this->normalizeTerminologyLabel((string) ($input['terminology_candidate_label'] ?? cpe_config('settings.terminology_candidate_label', 'Candidate')), 'Candidate'));
                    $this->set($pdo, 'terminology_candidates_label', $this->normalizeTerminologyLabel((string) ($input['terminology_candidates_label'] ?? cpe_config('settings.terminology_candidates_label', 'Candidates')), 'Candidates'));
                    $this->set($pdo, 'terminology_company_label', $this->normalizeTerminologyLabel((string) ($input['terminology_company_label'] ?? cpe_config('settings.terminology_company_label', 'Company')), 'Company'));
                    $this->set($pdo, 'terminology_companies_label', $this->normalizeTerminologyLabel((string) ($input['terminology_companies_label'] ?? cpe_config('settings.terminology_companies_label', 'Companies')), 'Companies'));
                    $this->set($pdo, 'board_refresh_seconds', (string) cpe_config('settings.board_refresh_seconds', '45'));
                    $this->set($pdo, 'export_profile_custom_datasets', (string) cpe_config('settings.export_profile_custom_datasets', 'placement_totals,application_status_counts,placements_by_company'));
                    $this->set($pdo, 'import_header_aliases_json', (string) cpe_config('settings.import_header_aliases_json', ''));
                    $this->set(
                        $pdo,
                        'board_card_fields',
                        (string) cpe_config('settings.board_card_fields', 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist')
                    );
                    $this->observe(InstallationStepObserver::AFTER_SETTINGS);
                    (new PortalKernelSynchronizer())->synchronize($pdo, $institutionPublicId);
                    $stmt = $pdo->prepare(
                        "UPDATE institutions SET public_id = ?, updated_at = ?
                         WHERE slug = 'default' AND substr(public_id, 1, 8) = 'unbound_'"
                    );
                    $stmt->execute([$institutionPublicId, cpe_now()]);
                    $stmt = $pdo->prepare("SELECT public_id FROM institutions WHERE slug = 'default'");
                    $stmt->execute();
                    $boundPublicId = $stmt->fetchColumn();
                    if (!is_string($boundPublicId) || !hash_equals($institutionPublicId, $boundPublicId)) {
                        throw new RuntimeException(
                            $tenantPublicId !== null
                                ? 'Hosted installation found a data plane reserved for a different tenant.'
                                : 'Installation found an institution identity that is already reserved.',
                        );
                    }
                    $this->observe(InstallationStepObserver::AFTER_IDENTITY);
                    (new WorkflowPublisher($pdo))->synchronize();
                    $adminId = Auth::createAdmin($name, $email, $password);
                    $this->observe(InstallationStepObserver::AFTER_ADMIN);
                    if (!empty($input['seed_demo'])) {
                        (new PlacementService($pdo))->seedDemo();
                    }
                    $this->observe(InstallationStepObserver::AFTER_DEMO_SEED);
                    (new PortalKernelSynchronizer())->synchronize($pdo, $institutionPublicId);
                    (new LegacyDomainSynchronizer())->synchronize($pdo);
                    (new WorkflowPublisher($pdo))->synchronize();
                    $this->observe(InstallationStepObserver::AFTER_SYNCHRONIZERS);
                    $this->set($pdo, 'installed_at', cpe_now());
                    $this->observe(InstallationStepObserver::AFTER_INSTALLED_MARKER);
                    Auth::audit($adminId, 'install', 'system', null, 'Initial installation completed');
                    $this->observe(InstallationStepObserver::AFTER_INSTALL_AUDIT);
                    if ($driver === 'pgsql') {
                        DatabaseLock::assertPostgresSession($pdo, (string) $lockBackendPid);
                    }
                    $pdo->commit();
                    $started = false;
                    return $adminId;
                } catch (Throwable $e) {
                    if ($started) {
                        TransactionRollbackGuard::rollbackOrRethrow(
                            $pdo,
                            $e,
                            'installation',
                            false,
                        );
                    }
                    throw $e;
                }
                },
                self::LOCK_TIMEOUT_MILLISECONDS,
            );
        } catch (Throwable $e) {
            $rollbackUncertain = $this->rollbackUncertainFailure($e);
            if ($rollbackUncertain !== null) {
                Database::reset();
                throw $rollbackUncertain;
            }
            $lockFailure = DatabaseLockException::find($e);
            if ($lockFailure !== null && $lockFailure->requiresConnectionReset()) {
                Database::reset();
            }
            throw $e;
        }
    }

    private function observe(string $stage): void
    {
        if ($this->stepObserver !== null) {
            $this->stepObserver->observe($stage);
        }
    }

    private function rollbackUncertainFailure(Throwable $failure): ?UserVisibleException
    {
        do {
            if ($failure instanceof UserVisibleException
                && $failure->publicCode() === TransactionRollbackGuard::ERROR_ROLLBACK_UNCERTAIN) {
                return $failure;
            }
            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return null;
    }

    private function claimInstallTarget(?SetupRecoveryAuthority $recoveryAuthority): string
    {
        try {
            $state = Database::installationStateStrict();
        } catch (InstallationStateUnavailable $failure) {
            if ($recoveryAuthority === null) {
                throw $failure;
            }
            $state = Database::installationStateForAuthorizedSetupStrict($recoveryAuthority);
        }
        if ($state === InstallationState::INSTALLED) {
            throw new RuntimeException(
                self::ERROR_ALREADY_INSTALLED
                . ': App is already installed. Use upgrade, configuration import, or a different CPE_DB_PATH for a fresh setup.',
            );
        }
        if ($state === InstallationState::FRESH) {
            if ($recoveryAuthority !== null) {
                throw InstallationStateUnavailable::state();
            }
            return $state;
        }
        if ($state !== InstallationState::RECOVERABLE || $recoveryAuthority === null) {
            throw new RuntimeException('Installation target state is unavailable.');
        }
        return $state;
    }

    private function assertClaimedInstallAvailable(
        string $claimedState,
        ?SetupRecoveryAuthority $recoveryAuthority,
    ): void
    {
        if ($claimedState === InstallationState::FRESH && $recoveryAuthority === null) {
            $state = Database::freshInstallContinuationStateStrict();
            if ($state === InstallationState::INSTALLED) {
                $this->alreadyInstalled();
            }
            return;
        }
        if ($claimedState === InstallationState::RECOVERABLE && $recoveryAuthority !== null) {
            try {
                $state = Database::installationStateStrict();
                if ($state === InstallationState::INSTALLED) {
                    $this->alreadyInstalled();
                }
                // FRESH is never valid for a recovery capability. Route every
                // non-installed outcome through the one-state authority probe.
                throw InstallationStateUnavailable::state();
            } catch (InstallationStateUnavailable) {
                Database::installationStateForAuthorizedSetupStrict($recoveryAuthority);
            }
            return;
        }
        throw InstallationStateUnavailable::state();
    }

    private function alreadyInstalled(): never
    {
        throw new RuntimeException(
            self::ERROR_ALREADY_INSTALLED
            . ': App is already installed. Use upgrade, configuration import, or a different CPE_DB_PATH for a fresh setup.',
        );
    }

    private function set(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
    }

    private function normalizeCycleType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['final', 'internship', 'lateral', 'pooled', 'job_fair', 'other'], true) ? $value : 'final';
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function normalizeDateList(string $value): string
    {
        $dates = [];
        foreach (preg_split('/[,\n]+/', $value) ?: [] as $part) {
            $date = $this->normalizeDate($part);
            if ($date !== '') {
                $dates[$date] = true;
            }
        }
        $keys = array_keys($dates);
        sort($keys);
        return implode(',', $keys);
    }

    private function normalizeAuditRequestMetadata(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['none', 'ip', 'user_agent', 'both'], true) ? $value : 'none';
    }

    private function normalizeWeekdayList(string $value): string
    {
        $aliases = [
            'monday' => 'mon',
            'tuesday' => 'tue',
            'wednesday' => 'wed',
            'thursday' => 'thu',
            'friday' => 'fri',
            'saturday' => 'sat',
            'sunday' => 'sun',
        ];
        $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $weekdays = [];
        foreach (preg_split('/[,\n]+/', strtolower($value)) ?: [] as $part) {
            $day = trim($part);
            $day = $aliases[$day] ?? $day;
            if ($day !== '' && !in_array($day, $valid, true)) {
                throw new UserVisibleException(
                    'SETUP_WEEKDAY_INVALID',
                    'Non-operating weekdays contain an unknown weekday. Use Mon, Tue, Wed, Thu, Fri, Sat, or Sun.',
                );
            }
            if ($day !== '') {
                $weekdays[$day] = true;
            }
        }
        return implode(',', array_values(array_intersect($valid, array_keys($weekdays))));
    }

    private function normalizeTerminologyLabel(string $value, string $default): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '') {
            return $default;
        }
        if (mb_strlen($value) > 40) {
            throw new UserVisibleException(
                'SETUP_TERMINOLOGY_TOO_LONG',
                'Terminology labels must be 40 characters or fewer.',
            );
        }
        return $value;
    }

    private function normalizeIdentityText(string $value, string $default, int $maxLength, string $label, bool $allowEmpty = false): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '') {
            return $allowEmpty ? '' : $default;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new UserVisibleException(
                'SETUP_TEXT_TOO_LONG',
                $label . ' must be ' . $maxLength . ' characters or fewer.',
            );
        }
        return $value;
    }
}
