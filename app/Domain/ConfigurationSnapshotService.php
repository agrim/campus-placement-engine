<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Http\UserVisibleException;
use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Portal;
use App\Core\Persistence\TransactionRollbackGuard;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Modules\Placement\Workflow\WorkflowRepository;
use App\Support\Database;
use App\Support\TimezoneValidator;
use App\Import\CsvImporter;
use PDO;
use RuntimeException;

final class ConfigurationSnapshotService
{
    private const SCHEMA = 'cpe.config.v1';

    private const EXPORTABLE_SETTINGS = [
        'college_name',
        'site_name',
        'site_tagline',
        'public_placements_title',
        'candidate_status_title',
        'timezone',
        'cycle_name',
        'cycle_type',
        'cycle_start_date',
        'cycle_end_date',
        'calendar_non_operating_weekdays',
        'calendar_non_operating_dates',
        'audit_request_metadata',
        'configuration_freeze',
        'terminology_candidate_label',
        'terminology_candidates_label',
        'terminology_company_label',
        'terminology_companies_label',
        'workflow',
        'placement_freeze',
        'allow_offer_upgrade',
        'scheduling_buffer_minutes',
        'slot_planner_strategy',
        'slot_optimizer_exact_limit',
        'board_refresh_seconds',
        'board_card_fields',
        'export_profile_custom_datasets',
        'import_header_aliases_json',
        'notification_delivery_channels',
        'notification_message_template',
        'notification_email_subject_template',
        'notification_email_body_template',
        'notification_sms_message_template',
        'notification_sms_payload_template',
        'notification_whatsapp_message_template',
        'notification_whatsapp_payload_template',
    ];

    /** @var array<string, true> */
    private const ROLES = [
        'admin' => true,
        'control' => true,
        'placement' => true,
        'company' => true,
        'mobile' => true,
        'floor' => true,
        'auditor' => true,
    ];

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function export(?string $targetPath = null): array
    {
        $payload = $this->payload();
        $path = $targetPath ?: $this->configDir() . '/config-' . gmdate('Ymd-His') . '.json';
        $this->prepareTarget($path);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Could not write configuration export: ' . $path);
        }
        return [
            'file_reference' => $this->fileReference($path),
            'settings' => count($payload['settings']),
            'status_overrides' => count($payload['workflow_status_overrides']),
            'transition_overrides' => count($payload['workflow_transition_overrides']),
        ];
    }

    public function portabilityPayload(): array
    {
        return $this->payload();
    }

    public function validate(string $path): array
    {
        $payload = $this->readPayload($path);
        $normalized = $this->normalizePayload($payload);
        return [
            'file_reference' => $this->fileReference($path),
            'workflow' => (string) ($normalized['settings']['workflow'] ?? $this->currentWorkflowKey()),
            'settings' => count($normalized['settings']),
            'status_overrides' => count($normalized['workflow_status_overrides']),
            'transition_overrides' => count($normalized['workflow_transition_overrides']),
        ];
    }

    public function import(string $path): array
    {
        $payload = $this->readPayload($path);
        $normalized = $this->normalizePayload($payload);
        return $this->importNormalized($normalized);
    }

    public function importPortabilityPayload(array $payload): array
    {
        return $this->importNormalized($this->normalizePayload($payload), false);
    }

    public function validatePortabilityPayload(array $payload, array $workflowDefinitions = [], array $knownRoleKeys = []): array
    {
        if (($payload['schema'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Configuration payload has an unsupported schema.');
        }
        $normalized = $this->normalizePayload($payload, $workflowDefinitions, $knownRoleKeys);
        return [
            'workflow' => (string) ($normalized['settings']['workflow'] ?? $this->currentWorkflowKey()),
            'settings' => count($normalized['settings']),
            'status_overrides' => count($normalized['workflow_status_overrides']),
            'transition_overrides' => count($normalized['workflow_transition_overrides']),
        ];
    }

    private function importNormalized(array $normalized, bool $createSafetyCopy = true): array
    {
        $this->assertConfigurationMutable();
        $safety = $createSafetyCopy ? $this->safetyCopy() : null;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $settingStmt = $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
            foreach ($normalized['settings'] as $key => $value) {
                $settingStmt->execute([$key, $value]);
            }

            $this->pdo->exec('DELETE FROM workflow_status_overrides');
            $statusStmt = $this->pdo->prepare('INSERT INTO workflow_status_overrides (status_key, label, color) VALUES (?, ?, ?)');
            foreach ($normalized['workflow_status_overrides'] as $row) {
                $statusStmt->execute([$row['status_key'], $row['label'], $row['color']]);
            }

            $this->pdo->exec('DELETE FROM workflow_transition_overrides');
            $transitionStmt = $this->pdo->prepare('INSERT INTO workflow_transition_overrides (from_status, to_status, roles_csv) VALUES (?, ?, ?)');
            foreach ($normalized['workflow_transition_overrides'] as $row) {
                $transitionStmt->execute([$row['from_status'], $row['to_status'], $row['roles_csv']]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                TransactionRollbackGuard::rethrow($this->pdo, $e, 'configuration.import', $safety !== null);
            }
            throw $e;
        }

        (new PortalKernelSynchronizer())->synchronize($this->pdo);
        (new WorkflowPublisher($this->pdo))->publishPortableOverrides(
            (string) ($normalized['settings']['workflow'] ?? $this->currentWorkflowKey()),
            $normalized['workflow_status_overrides'],
            $normalized['workflow_transition_overrides']
        );
        Portal::reset();

        return [
            'safety_reference' => $safety?->reference() ?? '',
            'settings' => count($normalized['settings']),
            'status_overrides' => count($normalized['workflow_status_overrides']),
            'transition_overrides' => count($normalized['workflow_transition_overrides']),
        ];
    }

    public function assertConfigurationMutable(): void
    {
        if ($this->isConfigurationFrozen()) {
            throw new UserVisibleException('CONFIGURATION_FROZEN', 'Configuration changes are frozen. Unfreeze configuration before changing settings, workflow, or importing configuration.');
        }
    }

    public function isConfigurationFrozen(): bool
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['configuration_freeze']);
        return (string) ($stmt->fetchColumn() ?: '0') === '1';
    }

    private function payload(): array
    {
        $settings = [];
        $placeholders = implode(',', array_fill(0, count(self::EXPORTABLE_SETTINGS), '?'));
        $stmt = $this->pdo->prepare("SELECT key, value FROM settings WHERE key IN ({$placeholders}) ORDER BY key");
        $stmt->execute(self::EXPORTABLE_SETTINGS);
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return [
            'schema' => self::SCHEMA,
            'exported_at' => cpe_now(),
            'app' => [
                'name' => cpe_config('app.name'),
                'version' => cpe_config('app.version'),
            ],
            'settings' => $settings,
            'workflow_status_overrides' => $this->pdo
                ->query('SELECT status_key, label, color FROM workflow_status_overrides ORDER BY status_key')
                ->fetchAll(),
            'workflow_transition_overrides' => $this->pdo
                ->query('SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides ORDER BY from_status, to_status')
                ->fetchAll(),
        ];
    }

    private function readPayload(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Provide a readable configuration JSON file.');
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Could not read configuration JSON file.');
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Configuration JSON is not valid.');
        }
        if (($payload['schema'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Configuration JSON has an unsupported schema.');
        }
        return $payload;
    }

    private function normalizePayload(array $payload, array $workflowDefinitions = [], array $knownRoleKeys = []): array
    {
        $settings = $this->normalizeSettings($payload['settings'] ?? [], $workflowDefinitions);
        $workflowKey = (string) ($settings['workflow'] ?? $this->currentWorkflowKey());
        $workflow = $this->workflowValidationShape($workflowKey, $workflowDefinitions);

        return [
            'settings' => $settings,
            'workflow_status_overrides' => $this->normalizeStatusOverrides($payload['workflow_status_overrides'] ?? [], $workflow),
            'workflow_transition_overrides' => $this->normalizeTransitionOverrides($payload['workflow_transition_overrides'] ?? [], $workflow, $knownRoleKeys),
        ];
    }

    private function normalizeSettings(mixed $settings, array $workflowDefinitions = []): array
    {
        if (!is_array($settings)) {
            throw new RuntimeException('Configuration settings must be an object.');
        }
        $allowed = array_fill_keys(self::EXPORTABLE_SETTINGS, true);
        $normalized = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (!isset($allowed[$key])) {
                throw new RuntimeException('Configuration contains a non-portable setting: ' . $key);
            }
            $normalized[$key] = $this->normalizeSettingValue($key, (string) $value, $workflowDefinitions);
        }
        return $normalized;
    }

    private function normalizeSettingValue(string $key, string $value, array $workflowDefinitions = []): string
    {
        $value = trim($value);
        return match ($key) {
            'workflow' => $this->validWorkflowKey($value, $workflowDefinitions),
            'timezone' => TimezoneValidator::normalize($value, 'UTC', 'Configuration setting timezone'),
            'configuration_freeze', 'placement_freeze', 'allow_offer_upgrade' => $value === '1' ? '1' : '0',
            'cycle_type' => $this->normalizeCycleType($value),
            'cycle_start_date', 'cycle_end_date' => $this->normalizeDate($value, $key),
            'calendar_non_operating_weekdays' => $this->normalizeWeekdayList($value),
            'calendar_non_operating_dates' => $this->normalizeDateList($value, $key),
            'audit_request_metadata' => $this->normalizeAuditRequestMetadata($value),
            'site_name' => $this->normalizeIdentityText($value, (string) cpe_config('app.name'), 80, 'site_name'),
            'site_tagline' => $this->normalizeIdentityText($value, '', 120, 'site_tagline', true),
            'public_placements_title' => $this->normalizeIdentityText($value, 'Public Placements', 80, 'public_placements_title'),
            'candidate_status_title' => $this->normalizeIdentityText($value, '', 80, 'candidate_status_title', true),
            'terminology_candidate_label' => $this->normalizeTerminologyLabel($value, 'Candidate'),
            'terminology_candidates_label' => $this->normalizeTerminologyLabel($value, 'Candidates'),
            'terminology_company_label' => $this->normalizeTerminologyLabel($value, 'Company'),
            'terminology_companies_label' => $this->normalizeTerminologyLabel($value, 'Companies'),
            'scheduling_buffer_minutes' => (string) max(0, min(240, (int) $value)),
            'slot_optimizer_exact_limit' => (string) max(0, min(12, (int) $value)),
            'board_refresh_seconds' => (string) (new PlacementService($this->pdo))->normalizeBoardRefreshSeconds($value),
            'slot_planner_strategy' => in_array($value, ['sequence', 'earliest', 'balanced'], true) ? $value : 'sequence',
            'board_card_fields' => (new PlacementService($this->pdo))->normalizeBoardCardFields($value),
            'export_profile_custom_datasets' => (new SnapshotExporter($this->pdo))->normalizeDatasetList($value),
            'import_header_aliases_json' => (new CsvImporter($this->pdo))->normalizeHeaderAliasJson($value),
            'notification_delivery_channels' => $this->normalizeChannels($value),
            default => $value,
        };
    }

    private function validWorkflowKey(string $value, array $workflowDefinitions = []): string
    {
        $value = $value !== '' ? $value : 'default';
        $this->workflowValidationShape($value, $workflowDefinitions);
        return $value;
    }

    private function normalizeCycleType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return 'final';
        }
        if (!in_array($value, ['final', 'internship', 'lateral', 'pooled', 'job_fair', 'other'], true)) {
            throw new RuntimeException('Configuration setting cycle_type must be final, internship, lateral, pooled, job_fair, or other.');
        }
        return $value;
    }

    private function normalizeTerminologyLabel(string $value, string $default): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '') {
            return $default;
        }
        if (mb_strlen($value) > 40) {
            throw new RuntimeException('Terminology labels must be 40 characters or fewer.');
        }
        return $value;
    }

    private function normalizeIdentityText(string $value, string $default, int $maxLength, string $key, bool $allowEmpty = false): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '') {
            return $allowEmpty ? '' : $default;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new RuntimeException('Configuration setting ' . $key . ' must be ' . $maxLength . ' characters or fewer.');
        }
        return $value;
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
                throw new RuntimeException('Configuration setting calendar_non_operating_weekdays contains an unknown weekday: ' . trim($part));
            }
            if (in_array($day, $valid, true)) {
                $weekdays[$day] = true;
            }
        }
        return implode(',', array_values(array_intersect($valid, array_keys($weekdays))));
    }

    private function normalizeDate(string $value, string $key): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Configuration setting ' . $key . ' must use YYYY-MM-DD.');
        }
        return $value;
    }

    private function normalizeDateList(string $value, string $key): string
    {
        $dates = [];
        foreach (preg_split('/[,\n]+/', $value) ?: [] as $part) {
            $date = $this->normalizeDate((string) $part, $key);
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
        if (!in_array($value, ['none', 'ip', 'user_agent', 'both'], true)) {
            throw new RuntimeException('Configuration setting audit_request_metadata must be none, ip, user_agent, or both.');
        }
        return $value;
    }

    private function normalizeChannels(string $value): string
    {
        $channels = array_values(array_unique(array_filter(array_map(
            fn (string $channel): string => strtolower(trim($channel)),
            explode(',', $value)
        ))));
        $channels = array_values(array_filter($channels, fn (string $channel): bool => in_array($channel, ['file', 'webhook', 'email', 'sms', 'whatsapp'], true)));
        return implode(',', $channels);
    }

    private function normalizeStatusOverrides(mixed $rows, array $workflow): array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Workflow status overrides must be a list.');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Workflow status override rows must be objects.');
            }
            $statusKey = (string) ($row['status_key'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));
            $color = trim((string) ($row['color'] ?? ''));
            if (!isset($workflow['statuses'][$statusKey])) {
                throw new RuntimeException('Workflow status override references unknown status: ' . $statusKey);
            }
            if ($label === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new RuntimeException('Workflow status override needs a label and #RRGGBB color.');
            }
            $normalized[] = ['status_key' => $statusKey, 'label' => $label, 'color' => strtolower($color)];
        }
        return $normalized;
    }

    private function normalizeTransitionOverrides(mixed $rows, array $workflow, array $knownRoleKeys = []): array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Workflow transition overrides must be a list.');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Workflow transition override rows must be objects.');
            }
            $from = (string) ($row['from_status'] ?? '');
            $to = (string) ($row['to_status'] ?? '');
            if (!isset($workflow['transitions'][$from][$to])) {
                throw new RuntimeException("Workflow transition override references unknown transition: {$from} -> {$to}");
            }
            $roles = array_values(array_filter(array_map('trim', explode(',', (string) ($row['roles_csv'] ?? '')))));
            foreach ($roles as $role) {
                if (!$this->roleExists($role, $knownRoleKeys)) {
                    throw new RuntimeException('Workflow transition override references unknown role: ' . $role);
                }
            }
            if ($roles === []) {
                throw new RuntimeException("Workflow transition override {$from} -> {$to} has no roles.");
            }
            $normalized[] = ['from_status' => $from, 'to_status' => $to, 'roles_csv' => implode(',', array_values(array_unique($roles)))];
        }
        return $normalized;
    }

    private function workflowValidationShape(string $workflowKey, array $workflowDefinitions = []): array
    {
        $template = cpe_config('workflows.' . $workflowKey);
        if (is_array($template)) {
            return $template;
        }
        if (isset($workflowDefinitions[$workflowKey]) && is_array($workflowDefinitions[$workflowKey])) {
            return $this->definitionValidationShape($workflowDefinitions[$workflowKey]);
        }
        $repository = new WorkflowRepository($this->pdo);
        if ($repository->hasSchema()) {
            $versionId = $repository->activeVersionId($workflowKey);
            $workflow = $versionId === null ? null : $repository->workflowForVersion($versionId);
            if (is_array($workflow)) {
                return $this->runtimeWorkflowValidationShape($workflow);
            }
        }
        throw new RuntimeException('Configuration references an unknown workflow: ' . $workflowKey);
    }

    private function definitionValidationShape(array $definition): array
    {
        $statuses = is_array($definition['states'] ?? null) ? $definition['states'] : [];
        $transitions = [];
        foreach ($definition['transitions'] ?? [] as $transition) {
            if (!is_array($transition)) {
                continue;
            }
            $from = (string) ($transition['from'] ?? '');
            $to = (string) ($transition['to'] ?? '');
            if ($from !== '' && $to !== '') {
                $transitions[$from][$to] = $transition['roles'] ?? [];
            }
        }
        if ($statuses === []) {
            throw new RuntimeException('Portable workflow definition has no states.');
        }
        return ['statuses' => $statuses, 'transitions' => $transitions];
    }

    private function runtimeWorkflowValidationShape(array $workflow): array
    {
        return $this->definitionValidationShape([
            'states' => $workflow['states'] ?? [],
            'transitions' => $workflow['transitions'] ?? [],
        ]);
    }

    private function roleExists(string $role, array $knownRoleKeys): bool
    {
        if (isset(self::ROLES[$role]) || in_array($role, $knownRoleKeys, true)) {
            return true;
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $role) !== 1) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM roles WHERE role_key = ?');
            $stmt->execute([$role]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentWorkflowKey(): string
    {
        $stmt = $this->pdo->query("SELECT value FROM settings WHERE key = 'workflow'");
        return (string) ($stmt->fetchColumn() ?: 'default');
    }

    private function prepareTarget(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create configuration export directory: ' . $dir);
        }
        if (is_dir($path)) {
            throw new RuntimeException('Configuration export target is a directory: ' . $path);
        }
    }

    private function safetyCopy(): \App\Core\Backup\BackupArtifact
    {
        return (new DatabaseBackupService($this->pdo))->create('config-import-safety', $this->configDir());
    }

    private function fileReference(string $path): string
    {
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        return 'config_' . substr(is_string($hash) ? $hash : hash('sha256', basename($path)), 0, 24);
    }

    private function configDir(): string
    {
        $dir = getenv('CPE_CONFIG_SNAPSHOT_DIR') ?: cpe_data_path('config');
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create configuration data directory.');
        }
        return $dir;
    }
}
