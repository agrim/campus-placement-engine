<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Install\PortalKernelSynchronizer;
use App\Core\Portal;
use App\Domain\ConfigurationSnapshotService;
use App\Domain\SnapshotExporter;
use App\Domain\Workflow;
use App\Import\CsvImporter;
use App\Modules\Placement\Application\PlacementService;
use App\Modules\Placement\Workflow\WorkflowDefinitionValidator;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;
use App\Support\TimezoneValidator;

final class AdminController
{
    public function show(): void
    {
        $user = Auth::requireCapability('portal.settings.manage', 'Only administrators can open Admin.');
        $pdo = Database::connection();
        $placement = new PlacementService($pdo);
        $settings = $pdo->query('SELECT key, value FROM settings ORDER BY key')->fetchAll();
        $transitions = $pdo->query('SELECT * FROM workflow_transition_overrides ORDER BY from_status, to_status')->fetchAll();
        $workflow = new Workflow();
        view('admin', [
            'user' => $user,
            'settings' => $settings,
            'boardCardFieldOptions' => $placement->boardCardFieldOptions(),
            'boardCardFields' => array_fill_keys($placement->boardCardFields(), true),
            'workflow' => $workflow,
            'workflowTransitions' => $workflow->transitionDefinitions(true),
            'workflowVersions' => $pdo->query(
                "SELECT wv.id, wv.version_number, wv.lifecycle_status, wv.source_type, wv.published_at,
                        wd.workflow_key, CASE WHEN wd.active_version_id = wv.id THEN 1 ELSE 0 END AS is_active,
                        (SELECT COUNT(*) FROM applications a WHERE a.workflow_version_id = wv.id) AS application_count
                 FROM workflow_versions wv
                 JOIN workflow_definitions wd ON wd.id = wv.workflow_definition_id
                 ORDER BY wd.workflow_key, wv.version_number DESC"
            )->fetchAll(),
            'workflowSemanticCategories' => WorkflowDefinitionValidator::SEMANTIC_CATEGORIES,
            'workflowGuards' => WorkflowDefinitionValidator::GUARDS,
            'workflowEffects' => WorkflowDefinitionValidator::EFFECTS,
            'roles' => cpe_config('roles'),
            'users' => Auth::users(),
            'companies' => $pdo->query('SELECT code, name FROM companies ORDER BY code')->fetchAll(),
            'transitions' => $transitions,
        ]);
    }

    public function update(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'portal.settings.manage')) {
                throw new \RuntimeException('Only administrators can update settings.');
            }
            $pdo = Database::connection();
            $placement = new PlacementService($pdo);
            $configuration = new ConfigurationSnapshotService($pdo);
            $wasConfigurationFrozen = $configuration->isConfigurationFrozen();
            $settings = $this->normalizedSettingsFromPost($placement, $pdo);
            $this->assertSettingsMutableOrUnfreezeOnly($pdo, $settings);
            foreach (['college_name', 'timezone', 'cycle_name'] as $key) {
                if ($settings[$key] !== '') {
                    $this->set($pdo, $key, $settings[$key]);
                }
            }
            $this->set($pdo, 'cycle_type', $settings['cycle_type']);
            $this->set($pdo, 'cycle_start_date', $settings['cycle_start_date']);
            $this->set($pdo, 'cycle_end_date', $settings['cycle_end_date']);
            $this->set($pdo, 'configuration_freeze', $settings['configuration_freeze']);
            if ($wasConfigurationFrozen) {
                Auth::audit((int) $user['id'], 'settings.configuration_unfreeze', 'system', null, 'Unfroze configuration changes');
                Flash::add('success', 'Configuration changes are unfrozen.');
                redirect(url('admin'));
            }
            foreach ($settings as $key => $value) {
                if (in_array($key, ['college_name', 'timezone', 'cycle_name', 'cycle_type', 'cycle_start_date', 'cycle_end_date', 'configuration_freeze'], true)) {
                    continue;
                }
                $this->set($pdo, $key, $value);
            }
            (new PortalKernelSynchronizer())->synchronize($pdo);
            Portal::reset();
            Auth::audit((int) $user['id'], 'settings.update', 'system', null, 'Updated public settings');
            Flash::add('success', 'Settings updated.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('admin'));
    }

    public function createUser(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'portal.users.manage')) {
                throw new \RuntimeException('Only administrators can create users.');
            }
            $role = (string) ($_POST['role'] ?? '');
            if (!array_key_exists($role, cpe_config('roles'))) {
                throw new \RuntimeException('Unknown role.');
            }
            $id = Auth::createUser(
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['email'] ?? '')),
                (string) ($_POST['password'] ?? ''),
                $role,
                trim((string) ($_POST['scope_type'] ?? '')),
                strtoupper(trim((string) ($_POST['scope_value'] ?? '')))
            );
            Auth::audit((int) $user['id'], 'user.create', 'user', $id, 'Created user');
            Flash::add('success', 'User created.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('admin'));
    }

    public function updateUsers(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'portal.users.manage')) {
                throw new \RuntimeException('Only administrators can update users.');
            }
            Auth::setActiveBulk($_POST['active'] ?? [], (int) $user['id']);
            Flash::add('success', 'User activation settings updated.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('admin'));
    }

    public function resetPassword(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'portal.users.manage')) {
                throw new \RuntimeException('Only administrators can reset passwords.');
            }
            Auth::setPassword((int) ($_POST['user_id'] ?? 0), (string) ($_POST['password'] ?? ''), (int) $user['id']);
            Flash::add('success', 'Password reset.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('admin'));
    }

    public function updateWorkflow(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.workflow.manage')) {
                throw new \RuntimeException('Only administrators can update workflow labels.');
            }
            $pdo = Database::connection();
            (new ConfigurationSnapshotService($pdo))->assertConfigurationMutable();
            $publisher = new WorkflowPublisher($pdo);
            $versionId = isset($_POST['workflow_form']) && $_POST['workflow_form'] === 'full'
                ? $publisher->publishCurrentForm($_POST, (int) $user['id'])
                : $publisher->publishCurrentEdits(
                    is_array($_POST['status'] ?? null) ? $_POST['status'] : [],
                    is_array($_POST['transition'] ?? null) ? $_POST['transition'] : [],
                    (int) $user['id']
                );
            Auth::audit((int) $user['id'], 'workflow.publish', 'workflow_version', $versionId, 'Published immutable workflow version');
            Flash::add('success', 'Workflow version published. Existing applications remain on their original version.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('admin'));
    }

    private function set(\PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
    }

    private function normalizedSettingsFromPost(PlacementService $placement, \PDO $pdo): array
    {
        return [
            'college_name' => trim((string) ($_POST['college_name'] ?? '')),
            'site_name' => $this->normalizeIdentityText((string) ($_POST['site_name'] ?? ''), (string) cpe_config('app.name'), 80, 'Site name'),
            'site_tagline' => $this->normalizeIdentityText((string) ($_POST['site_tagline'] ?? ''), '', 120, 'Site tagline', true),
            'public_placements_title' => $this->normalizeIdentityText((string) ($_POST['public_placements_title'] ?? ''), 'Public Placements', 80, 'Public placements title'),
            'candidate_status_title' => $this->normalizeIdentityText((string) ($_POST['candidate_status_title'] ?? ''), '', 80, 'Candidate status title', true),
            'timezone' => TimezoneValidator::normalize((string) ($_POST['timezone'] ?? ''), 'UTC'),
            'cycle_name' => trim((string) ($_POST['cycle_name'] ?? '')),
            'cycle_type' => $this->normalizeCycleType((string) ($_POST['cycle_type'] ?? 'final')),
            'cycle_start_date' => $this->normalizeDate((string) ($_POST['cycle_start_date'] ?? ''), 'Cycle start date'),
            'cycle_end_date' => $this->normalizeDate((string) ($_POST['cycle_end_date'] ?? ''), 'Cycle end date'),
            'calendar_non_operating_weekdays' => $this->normalizeWeekdayList((string) ($_POST['calendar_non_operating_weekdays'] ?? '')),
            'calendar_non_operating_dates' => $this->normalizeDateList((string) ($_POST['calendar_non_operating_dates'] ?? '')),
            'audit_request_metadata' => $this->normalizeAuditRequestMetadata((string) ($_POST['audit_request_metadata'] ?? 'none')),
            'configuration_freeze' => isset($_POST['configuration_freeze']) ? '1' : '0',
            'terminology_candidate_label' => $this->normalizeTerminologyLabel((string) ($_POST['terminology_candidate_label'] ?? ''), 'Candidate'),
            'terminology_candidates_label' => $this->normalizeTerminologyLabel((string) ($_POST['terminology_candidates_label'] ?? ''), 'Candidates'),
            'terminology_company_label' => $this->normalizeTerminologyLabel((string) ($_POST['terminology_company_label'] ?? ''), 'Company'),
            'terminology_companies_label' => $this->normalizeTerminologyLabel((string) ($_POST['terminology_companies_label'] ?? ''), 'Companies'),
            'notification_delivery_channels' => $this->normalizeNotificationChannels((string) ($_POST['notification_delivery_channels'] ?? '')),
            'notification_file_outbox_path' => trim((string) ($_POST['notification_file_outbox_path'] ?? '')),
            'notification_email_to' => trim((string) ($_POST['notification_email_to'] ?? '')),
            'notification_email_from' => trim((string) ($_POST['notification_email_from'] ?? '')),
            'notification_message_template' => trim((string) ($_POST['notification_message_template'] ?? '')),
            'notification_email_subject_template' => trim((string) ($_POST['notification_email_subject_template'] ?? '')),
            'notification_email_body_template' => trim((string) ($_POST['notification_email_body_template'] ?? '')),
            'notification_sms_gateway_url' => trim((string) ($_POST['notification_sms_gateway_url'] ?? '')),
            'notification_sms_to' => trim((string) ($_POST['notification_sms_to'] ?? '')),
            'notification_sms_message_template' => trim((string) ($_POST['notification_sms_message_template'] ?? '')),
            'notification_sms_payload_template' => trim((string) ($_POST['notification_sms_payload_template'] ?? '')),
            'notification_whatsapp_gateway_url' => trim((string) ($_POST['notification_whatsapp_gateway_url'] ?? '')),
            'notification_whatsapp_to' => trim((string) ($_POST['notification_whatsapp_to'] ?? '')),
            'notification_whatsapp_message_template' => trim((string) ($_POST['notification_whatsapp_message_template'] ?? '')),
            'notification_whatsapp_payload_template' => trim((string) ($_POST['notification_whatsapp_payload_template'] ?? '')),
            'scheduling_buffer_minutes' => (string) max(0, min(240, (int) ($_POST['scheduling_buffer_minutes'] ?? 0))),
            'slot_planner_strategy' => $this->normalizeSlotPlannerStrategy((string) ($_POST['slot_planner_strategy'] ?? 'sequence')),
            'slot_optimizer_exact_limit' => (string) max(0, min(12, (int) ($_POST['slot_optimizer_exact_limit'] ?? 10))),
            'board_refresh_seconds' => (string) $placement->normalizeBoardRefreshSeconds($_POST['board_refresh_seconds'] ?? '45'),
            'board_card_fields' => $placement->normalizeBoardCardFields($_POST['board_card_fields'] ?? []),
            'export_profile_custom_datasets' => (new SnapshotExporter())->normalizeDatasetList((string) ($_POST['export_profile_custom_datasets'] ?? '')),
            'import_header_aliases_json' => (new CsvImporter($pdo))->normalizeHeaderAliasJson((string) ($_POST['import_header_aliases_json'] ?? '')),
            'placement_freeze' => isset($_POST['placement_freeze']) ? '1' : '0',
            'allow_offer_upgrade' => isset($_POST['allow_offer_upgrade']) ? '1' : '0',
        ];
    }

    private function assertSettingsMutableOrUnfreezeOnly(\PDO $pdo, array $settings): void
    {
        if (!(new ConfigurationSnapshotService($pdo))->isConfigurationFrozen()) {
            return;
        }
        if (($settings['configuration_freeze'] ?? '1') !== '0') {
            throw new \RuntimeException('Configuration changes are frozen. Unfreeze configuration before changing settings.');
        }
        $changed = [];
        foreach ($settings as $key => $value) {
            $current = $this->currentSetting($pdo, $key);
            if ($key === 'configuration_freeze') {
                continue;
            }
            if ($value !== $current) {
                $changed[] = $key;
            }
        }
        if ($changed !== []) {
            throw new \RuntimeException('Configuration changes are frozen. Unfreeze configuration before changing other settings.');
        }
    }

    private function currentSetting(\PDO $pdo, string $key): string
    {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value !== false) {
            return (string) $value;
        }
        return match ($key) {
            'college_name', 'timezone' => (string) cpe_config('settings.' . $key, ''),
            'site_name' => (string) cpe_config('settings.site_name', cpe_config('app.name')),
            'site_tagline', 'candidate_status_title' => '',
            'public_placements_title' => 'Public Placements',
            'cycle_type' => 'final',
            'cycle_start_date', 'cycle_end_date' => '',
            'calendar_non_operating_weekdays', 'calendar_non_operating_dates' => '',
            'audit_request_metadata' => 'none',
            'configuration_freeze', 'placement_freeze', 'allow_offer_upgrade' => '0',
            'terminology_candidate_label' => 'Candidate',
            'terminology_candidates_label' => 'Candidates',
            'terminology_company_label' => 'Company',
            'terminology_companies_label' => 'Companies',
            'notification_delivery_channels',
            'notification_file_outbox_path',
            'notification_email_to',
            'notification_email_from',
            'notification_message_template',
            'notification_email_subject_template',
            'notification_email_body_template',
            'notification_sms_gateway_url',
            'notification_sms_to',
            'notification_sms_message_template',
            'notification_sms_payload_template',
            'notification_whatsapp_gateway_url',
            'notification_whatsapp_to',
            'notification_whatsapp_message_template',
            'notification_whatsapp_payload_template' => '',
            'scheduling_buffer_minutes' => '0',
            'slot_planner_strategy' => 'sequence',
            'slot_optimizer_exact_limit' => '10',
            'board_refresh_seconds' => (string) cpe_config('settings.board_refresh_seconds', '45'),
            'board_card_fields' => (string) cpe_config('settings.board_card_fields', ''),
            'export_profile_custom_datasets' => (string) cpe_config('settings.export_profile_custom_datasets', ''),
            'import_header_aliases_json' => '',
            default => '',
        };
    }

    private function normalizeNotificationChannels(string $value): string
    {
        $channels = array_values(array_unique(array_filter(array_map(
            fn (string $channel): string => strtolower(trim($channel)),
            explode(',', $value)
        ))));
        $channels = array_values(array_filter($channels, fn (string $channel): bool => in_array($channel, ['file', 'webhook', 'email', 'sms', 'whatsapp'], true)));
        return implode(',', $channels);
    }

    private function normalizeSlotPlannerStrategy(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['sequence', 'earliest', 'balanced'], true) ? $value : 'sequence';
    }

    private function normalizeTerminologyLabel(string $value, string $default): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '') {
            return $default;
        }
        if (mb_strlen($value) > 40) {
            throw new \RuntimeException('Terminology labels must be 40 characters or fewer.');
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
            throw new \RuntimeException($label . ' must be ' . $maxLength . ' characters or fewer.');
        }
        return $value;
    }

    private function normalizeCycleType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['final', 'internship', 'lateral', 'pooled', 'job_fair', 'other'], true) ? $value : 'final';
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
                throw new \RuntimeException('Non-operating weekdays contain an unknown weekday: ' . trim($part));
            }
            if ($day !== '') {
                $weekdays[$day] = true;
            }
        }
        return implode(',', array_values(array_intersect($valid, array_keys($weekdays))));
    }

    private function normalizeDate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException($label . ' must use YYYY-MM-DD.');
        }
        return $value;
    }

    private function normalizeDateList(string $value): string
    {
        $dates = [];
        foreach (preg_split('/[,\n]+/', $value) ?: [] as $part) {
            $date = $this->normalizeDate((string) $part, 'Non-operating date');
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
            throw new \RuntimeException('Audit request metadata must be none, ip, user_agent, or both.');
        }
        return $value;
    }
}
