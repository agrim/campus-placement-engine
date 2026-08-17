<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Install\PortalKernelSynchronizer;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Modules\Placement\Application\PlacementService;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Support\Auth;
use App\Support\Database;
use App\Support\TimezoneValidator;
use PDO;
use RuntimeException;

final class Installer
{
    public function install(array $input): int
    {
        if (Database::isInstalled()) {
            throw new RuntimeException('App is already installed. Use upgrade, configuration import, or a different CPE_DB_PATH for a fresh setup.');
        }

        $college = trim((string) ($input['college_name'] ?? ''));
        $timezone = TimezoneValidator::normalize((string) ($input['timezone'] ?? 'Asia/Kolkata'), 'Asia/Kolkata');
        $name = trim((string) ($input['admin_name'] ?? ''));
        $email = trim((string) ($input['admin_email'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');

        if ($college === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException('College, admin name, valid email, and an 8+ character password are required.');
        }

        Database::migrate();
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
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
            $adminId = Auth::createAdmin($name, $email, $password);
            if (!empty($input['seed_demo'])) {
                (new PlacementService($pdo))->seedDemo();
            }
            (new PortalKernelSynchronizer())->synchronize($pdo);
            (new LegacyDomainSynchronizer())->synchronize($pdo);
            (new WorkflowPublisher($pdo))->synchronize();
            $this->set($pdo, 'installed_at', cpe_now());
            Auth::audit($adminId, 'install', 'system', null, 'Initial installation completed');
            $pdo->commit();
            return $adminId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
                throw new RuntimeException('Non-operating weekdays contain an unknown weekday: ' . trim($part));
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
            throw new RuntimeException('Terminology labels must be 40 characters or fewer.');
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
            throw new RuntimeException($label . ' must be ' . $maxLength . ' characters or fewer.');
        }
        return $value;
    }
}
