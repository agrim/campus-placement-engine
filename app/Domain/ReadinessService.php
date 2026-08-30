<?php

declare(strict_types=1);

namespace App\Domain;

use App\Api\Operations\ApiHealthService;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\BackupMetadata;
use App\Core\Backup\DatabaseRestoreService;
use App\Core\Http\UserVisibleException;
use App\Install\SystemRequirements;
use App\Integrations\Webhooks\WebhookHealthService;
use App\Support\Database;
use PDO;

final class ReadinessService
{
    private const MAX_BACKUP_DIRECTORY_ENTRIES = 512;
    private const MAX_BACKUP_CANDIDATES = 16;
    private const MAX_BACKUP_VERIFICATION_BYTES = 536870912;

    public function __construct(private ?PDO $pdo = null, private ?Workflow $workflow = null)
    {
        $this->pdo ??= Database::connection();
        $this->workflow ??= new Workflow();
    }

    public function snapshot(): array
    {
        $runtimeFailures = array_values(array_map(
            static fn (array $check): string => (string) $check['key'],
            array_filter(
                (new SystemRequirements())->runtimeChecks(),
                static fn (array $check): bool => !$check['ok'],
            ),
        ));
        $backup = $this->latestBackup();
        $openWanted = $this->count("SELECT COUNT(*) FROM wanted_alerts WHERE status = 'open'");
        $openPreferences = $this->count("SELECT COUNT(*) FROM preference_requests WHERE status = 'open'");
        $openNotifications = $this->count("SELECT COUNT(*) FROM notifications WHERE status = 'open'");
        $staleApplications = $this->staleApplications();
        $activeConflicts = $this->activeConflicts();
        $capacityAlerts = $this->capacityAlerts();
        $calendarWarnings = $this->calendarWarnings();
        $deliveryStatus = $this->notificationDeliveryStatus();
        $gatewayCertification = $this->notificationGatewayCertification();
        $activeUsers = $this->count('SELECT COUNT(*) FROM users WHERE active = 1');
        $configurationFrozen = $this->setting('configuration_freeze') === '1';
        $workflowErrors = $this->workflow->validate();
        $webhookHealth = (new WebhookHealthService($this->pdo))->snapshot();
        $apiHealth = (new ApiHealthService($this->pdo))->snapshot();

        return [
            'checks' => [
                $this->check(
                    'Runtime requirements',
                    $runtimeFailures === [] ? 'ok' : 'fail',
                    $runtimeFailures === []
                        ? 'Required PHP version and extensions are available.'
                        : 'Missing or unsupported runtime requirements: ' . implode(', ', $runtimeFailures) . '.',
                ),
                $this->check(
                    'Workflow configuration',
                    $workflowErrors ? 'fail' : 'ok',
                    $workflowErrors ? implode('; ', $workflowErrors) : 'All configured statuses and transitions validate.'
                ),
                $this->check(
                    'Latest backup',
                    $backup['status'],
                    $backup['message']
                ),
                $this->check(
                    'Configuration freeze',
                    $configurationFrozen ? 'ok' : 'warn',
                    $configurationFrozen
                        ? 'Configuration changes are frozen for live operations.'
                        : 'Configuration changes are not frozen.'
                ),
                $this->check(
                    'Open wanted alerts',
                    $openWanted > 0 ? 'warn' : 'ok',
                    $openWanted === 0 ? 'No open wanted alerts.' : "{$openWanted} open wanted alert(s) need floor/control follow-up."
                ),
                $this->check(
                    'Open preference requests',
                    $openPreferences > 0 ? 'warn' : 'ok',
                    $openPreferences === 0 ? 'No open preference requests.' : "{$openPreferences} open preference request(s) need a decision."
                ),
                $this->check(
                    'Open in-app notifications',
                    $openNotifications > 0 ? 'warn' : 'ok',
                    $openNotifications === 0 ? 'No open in-app notifications.' : "{$openNotifications} open notification(s) need acknowledgement."
                ),
                $this->check(
                    'Stale active applications',
                    $staleApplications['count'] > 0 ? 'warn' : 'ok',
                    $staleApplications['count'] === 0
                        ? 'No active application has been untouched for more than 90 minutes.'
                        : "{$staleApplications['count']} active application(s) have not changed for more than 90 minutes."
                ),
                $this->check(
                    'Active company conflicts',
                    $activeConflicts['count'] > 0 ? 'warn' : 'ok',
                    $activeConflicts['count'] === 0
                        ? 'No candidate is active with multiple companies.'
                        : "{$activeConflicts['count']} candidate(s) are active with multiple companies."
                ),
                $this->check(
                    'Company active capacity',
                    $capacityAlerts['count'] > 0 ? 'warn' : 'ok',
                    $capacityAlerts['count'] === 0
                        ? 'No company is above its configured active-candidate cap.'
                        : "{$capacityAlerts['count']} compan" . ($capacityAlerts['count'] === 1 ? 'y is' : 'ies are') . ' above active-candidate cap.'
                ),
                $this->check(
                    'Calendar guardrails',
                    ($calendarWarnings['count'] + $calendarWarnings['unresolved']) > 0 ? 'warn' : 'ok',
                    $this->calendarWarningMessage($calendarWarnings)
                ),
                $this->check(
                    'External notification deliveries',
                    ($deliveryStatus['queued'] + $deliveryStatus['failed'] + $deliveryStatus['dead-lettered']) > 0 ? 'warn' : 'ok',
                    ($deliveryStatus['queued'] + $deliveryStatus['failed'] + $deliveryStatus['dead-lettered']) === 0
                        ? 'No queued, retrying, or dead-lettered external notification deliveries.'
                        : "{$deliveryStatus['queued']} queued, {$deliveryStatus['failed']} retrying, and {$deliveryStatus['dead-lettered']} dead-lettered external notification deliveries."
                ),
                $this->check(
                    'External notification gateway configuration',
                    $gatewayCertification['status'],
                    $gatewayCertification['message']
                ),
                $this->check(
                    'Signed webhook integrations',
                    $webhookHealth['status'],
                    $webhookHealth['message'],
                ),
                $this->check(
                    'Institution-local API identity',
                    $apiHealth['status'],
                    $apiHealth['message'],
                ),
                $this->check(
                    'Active users',
                    $activeUsers > 0 ? 'ok' : 'fail',
                    $activeUsers > 0 ? "{$activeUsers} active user(s)." : 'No active users are available.'
                ),
            ],
            'backup' => $backup,
            'openWanted' => $openWanted,
            'openPreferences' => $openPreferences,
            'openNotifications' => $openNotifications,
            'staleApplications' => $staleApplications,
            'activeConflicts' => $activeConflicts,
            'capacityAlerts' => $capacityAlerts,
            'calendarWarnings' => $calendarWarnings,
            'notificationDeliveries' => $deliveryStatus,
            'notificationGatewayCertification' => $gatewayCertification,
            'webhookIntegrations' => $webhookHealth,
            'apiIdentity' => $apiHealth,
            'activeUsers' => $activeUsers,
            'configurationFrozen' => $configurationFrozen,
        ];
    }

    private function notificationDeliveryStatus(): array
    {
        try {
            return (new NotificationDeliveryService($this->pdo))->deliveryStatus();
        } catch (\Throwable) {
            return ['queued' => 0, 'failed' => 0, 'delivered' => 0];
        }
    }

    private function notificationGatewayCertification(): array
    {
        $channels = array_values(array_intersect($this->enabledNotificationChannels(), ['sms', 'whatsapp']));
        if ($channels === []) {
            return [
                'status' => 'ok',
                'message' => 'No SMS/WhatsApp external notification gateway is enabled.',
                'channels' => [],
            ];
        }

        $service = new NotificationDeliveryService($this->pdo);
        $issues = [];
        $reports = [];
        foreach ($channels as $channel) {
            $report = $service->certificationReport($channel);
            $reports[$channel] = $report;
            foreach ($report['checks'] as $check) {
                if (($check['status'] ?? '') === 'error') {
                    $issues[] = strtoupper($channel) . ' ' . $check['key'] . ': ' . $check['message'];
                }
            }
        }

        if ($issues !== []) {
            return [
                'status' => 'warn',
                'message' => count($issues) . ' SMS/WhatsApp gateway configuration issue(s): ' . implode('; ', array_slice($issues, 0, 3)) . (count($issues) > 3 ? '; ...' : ''),
                'channels' => $reports,
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Enabled SMS/WhatsApp gateway handoff settings pass local certification preflight.',
            'channels' => $reports,
        ];
    }

    private function enabledNotificationChannels(): array
    {
        $value = $this->setting('notification_delivery_channels');
        $channels = array_values(array_unique(array_filter(array_map(
            fn (string $channel): string => strtolower(trim($channel)),
            explode(',', $value)
        ))));
        return array_values(array_filter($channels, fn (string $channel): bool => in_array($channel, ['file', 'webhook', 'email', 'sms', 'whatsapp'], true)));
    }

    private function setting(string $key): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function latestBackup(): array
    {
        $directory = DatabaseBackupService::configuredDirectory();
        if (!file_exists($directory) && !is_link($directory)) {
            return $this->missingBackup();
        }

        if (!DatabaseBackupService::directoryIsSafe($directory) || !is_readable($directory)) {
            return $this->unavailableBackupStorage();
        }

        $handle = @opendir($directory);
        if (!is_resource($handle)) {
            return $this->unavailableBackupStorage();
        }

        $candidates = [];
        $invalidSetSeen = false;
        $entryCount = 0;
        try {
            while (($entry = readdir($handle)) !== false) {
                if (++$entryCount > self::MAX_BACKUP_DIRECTORY_ENTRIES) {
                    return $this->unavailableBackupStorage();
                }
                if (!str_ends_with($entry, '.sqlite') && !str_ends_with($entry, '.pgdump')) {
                    continue;
                }
                $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $entry;
                $stat = @lstat($path);
                if ($stat === false) {
                    return $this->unavailableBackupStorage();
                }
                if ((($stat['mode'] ?? 0) & 0170000) !== 0100000
                    || (int) ($stat['size'] ?? 0) <= 0) {
                    $invalidSetSeen = true;
                    continue;
                }
                $candidates[] = $path;
            }
        } finally {
            closedir($handle);
        }

        if ($candidates === []) {
            return $invalidSetSeen ? $this->invalidBackup() : $this->missingBackup();
        }

        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        try {
            $liveIdentity = BackupMetadata::databaseIdentity($this->pdo, $driver);
        } catch (\Throwable) {
            return $this->unavailableBackupStorage();
        }
        $indexer = new DatabaseRestoreService();
        $prioritizedCandidates = [];
        foreach ($candidates as $candidate) {
            $invalidSetSeen = true;
            try {
                $createdAt = $indexer->candidateCreatedAt($candidate, $driver, $liveIdentity);
            } catch (\Throwable) {
                continue;
            }
            $prioritizedCandidates[] = ['path' => $candidate, 'created_at' => $createdAt];
        }
        usort(
            $prioritizedCandidates,
            static fn (array $left, array $right): int => $right['created_at'] <=> $left['created_at'],
        );

        $verifier = new DatabaseRestoreService(null, self::MAX_BACKUP_VERIFICATION_BYTES);
        foreach (array_slice($prioritizedCandidates, 0, self::MAX_BACKUP_CANDIDATES) as $candidate) {
            try {
                $verified = $verifier->verifiedCandidate($candidate['path'], $driver, $liveIdentity);
                $createdAt = BackupMetadata::createdAtTimestamp($verified['metadata']);
            } catch (UserVisibleException $e) {
                if ($e->publicCode() === 'DATABASE_BACKUP_VERIFICATION_LIMIT') {
                    return $this->unavailableBackupStorage();
                }
                continue;
            } catch (\Throwable) {
                continue;
            }
            $ageHours = max(0, (int) floor((time() - $createdAt) / 3600));
            return [
                'present' => true,
                'storage' => 'configured_backup_directory',
                'ageHours' => $ageHours,
                'status' => $ageHours <= 24 ? 'ok' : 'warn',
                'message' => 'Latest verified backup is about ' . $ageHours . ' hour(s) old.',
            ];
        }

        return $invalidSetSeen ? $this->invalidBackup() : $this->missingBackup();
    }

    private function missingBackup(): array
    {
        return [
            'present' => false,
            'storage' => 'configured_backup_directory',
            'ageHours' => null,
            'status' => 'warn',
            'message' => 'No backup found. Run php placement backup before live operations.',
        ];
    }

    private function unavailableBackupStorage(): array
    {
        return [
            'present' => false,
            'storage' => 'configured_backup_directory',
            'ageHours' => null,
            'status' => 'warn',
            'message' => 'Configured backup storage could not be inspected. Verify backup storage access.',
        ];
    }

    private function invalidBackup(): array
    {
        return [
            'present' => false,
            'storage' => 'configured_backup_directory',
            'ageHours' => null,
            'status' => 'warn',
            'message' => 'No complete verified backup found. Run php placement backup before live operations.',
        ];
    }

    private function staleApplications(): array
    {
        $activeStatuses = $this->activeStatuses();
        if (!$activeStatuses) {
            return ['count' => 0, 'rows' => []];
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 90 * 60);
        $activeApplicationSql = $this->activeApplicationSql('a');
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.current_status, a.updated_at, c.external_id, c.name AS candidate_name, co.code AS company_code
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             WHERE {$activeApplicationSql} AND a.updated_at < ?
             ORDER BY a.updated_at ASC
             LIMIT 20"
        );
        $stmt->execute([$cutoff]);
        $rows = $stmt->fetchAll();
        return ['count' => count($rows), 'rows' => $rows];
    }

    private function capacityAlerts(): array
    {
        $activeStatuses = $this->activeStatuses();
        if (!$activeStatuses) {
            return ['count' => 0, 'rows' => []];
        }
        $activeApplicationSql = $this->activeApplicationSql('a');
        $stmt = $this->pdo->prepare(
            "SELECT co.code, co.name, co.max_active, COUNT(a.id) AS active_count
             FROM companies co
             JOIN applications a ON a.company_id = co.id
             WHERE co.max_active > 0 AND {$activeApplicationSql}
             GROUP BY co.id, co.code, co.name, co.max_active
             HAVING COUNT(a.id) > co.max_active
             ORDER BY COUNT(a.id) - co.max_active DESC, co.code"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return ['count' => count($rows), 'rows' => $rows];
    }

    private function activeConflicts(): array
    {
        $activeStatuses = $this->activeStatuses();
        if (!$activeStatuses) {
            return ['count' => 0, 'rows' => []];
        }
        $activeApplicationSql = $this->activeApplicationSql('a');
        $companyCodes = Database::groupConcat('co.code');
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS candidate_id, c.external_id, c.name AS candidate_name,
                    COUNT(a.id) AS active_count, {$companyCodes} AS company_codes
             FROM candidates c
             JOIN applications a ON a.candidate_id = c.id
             JOIN companies co ON co.id = a.company_id
             WHERE {$activeApplicationSql}
             GROUP BY c.id, c.external_id, c.name
             HAVING COUNT(a.id) > 1
             ORDER BY active_count DESC, c.external_id
             LIMIT 20"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return ['count' => count($rows), 'rows' => $rows];
    }

    private function calendarWarnings(): array
    {
        $nonOperatingWeekdays = $this->csvSet($this->setting('calendar_non_operating_weekdays'));
        $nonOperatingDates = $this->csvSet($this->setting('calendar_non_operating_dates'));
        if ($nonOperatingWeekdays === [] && $nonOperatingDates === []) {
            return ['count' => 0, 'unresolved' => 0, 'rows' => []];
        }

        $cycleStart = $this->dateFromSetting('cycle_start_date');
        $stmt = $this->pdo->query(
            "SELECT rs.id, co.code AS company_code, cr.sequence AS round_sequence, cr.label AS round_label,
                    rs.sequence AS schedule_sequence, rs.room, rs.schedule_day, rs.starts_at, rs.ends_at
             FROM round_schedules rs
             JOIN company_rounds cr ON cr.id = rs.round_id
             JOIN companies co ON co.id = cr.company_id
             WHERE trim(rs.schedule_day) <> ''
             ORDER BY co.code, cr.sequence, rs.schedule_day, rs.sequence, rs.starts_at, rs.id"
        );

        $rows = [];
        $unresolved = 0;
        foreach ($stmt->fetchAll() as $row) {
            $resolvedDate = $this->resolveScheduleDate((string) $row['schedule_day'], $cycleStart);
            if ($resolvedDate === null) {
                if ($nonOperatingWeekdays !== [] && preg_match('/^\d+$/', trim((string) $row['schedule_day'])) && $cycleStart === null) {
                    $unresolved++;
                }
                continue;
            }
            $weekday = strtolower($resolvedDate->format('D'));
            $date = $resolvedDate->format('Y-m-d');
            $reasons = [];
            if (isset($nonOperatingDates[$date])) {
                $reasons[] = 'date';
            }
            if (isset($nonOperatingWeekdays[$weekday])) {
                $reasons[] = 'weekday';
            }
            if ($reasons !== []) {
                $rows[] = [
                    ...$row,
                    'resolved_date' => $date,
                    'weekday' => $weekday,
                    'reason' => implode(',', $reasons),
                ];
            }
        }

        return ['count' => count($rows), 'unresolved' => $unresolved, 'rows' => array_slice($rows, 0, 20)];
    }

    private function calendarWarningMessage(array $calendarWarnings): string
    {
        if ($calendarWarnings['count'] === 0 && $calendarWarnings['unresolved'] === 0) {
            $configured = $this->setting('calendar_non_operating_weekdays') !== '' || $this->setting('calendar_non_operating_dates') !== '';
            return $configured
                ? 'No round schedules fall on configured non-operating calendar days.'
                : 'No non-operating calendar guardrails are configured.';
        }
        $parts = [];
        if ($calendarWarnings['count'] > 0) {
            $parts[] = "{$calendarWarnings['count']} round schedule(s) fall on configured non-operating calendar days";
        }
        if ($calendarWarnings['unresolved'] > 0) {
            $parts[] = "{$calendarWarnings['unresolved']} numeric schedule day(s) need a cycle start date before weekday rules can be checked";
        }
        return implode('; ', $parts) . '.';
    }

    private function resolveScheduleDate(string $scheduleDay, ?\DateTimeImmutable $cycleStart): ?\DateTimeImmutable
    {
        $scheduleDay = trim($scheduleDay);
        if ($scheduleDay === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDay)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $scheduleDay, new \DateTimeZone('UTC'));
            return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $scheduleDay ? $date : null;
        }
        if ($cycleStart && preg_match('/^\d+$/', $scheduleDay)) {
            $offset = max(0, (int) $scheduleDay - 1);
            return $cycleStart->modify('+' . $offset . ' days') ?: null;
        }
        return null;
    }

    private function dateFromSetting(string $key): ?\DateTimeImmutable
    {
        $value = $this->setting($key);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return array<string, true> */
    private function csvSet(string $value): array
    {
        $set = [];
        foreach (explode(',', $value) as $part) {
            $part = strtolower(trim($part));
            if ($part !== '') {
                $set[$part] = true;
            }
        }
        return $set;
    }

    private function activeStatuses(): array
    {
        $statuses = $this->workflow->statuses();
        if (!$statuses) {
            return [];
        }
        uasort($statuses, fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));
        $keys = array_keys($statuses);
        $terminal = end($keys);
        return array_values(array_filter($keys, fn (string $key): bool => $key !== 'idle' && $key !== $terminal));
    }

    private function activeApplicationSql(string $alias): string
    {
        $statuses = implode(', ', array_map(fn (string $status): string => $this->pdo->quote($status), $this->activeStatuses()));
        if ($statuses === '') {
            $statuses = "''";
        }
        return "({$alias}.current_status IN ({$statuses}) AND NOT ({$alias}.current_status = 'sent' AND {$alias}.next_company_id IS NOT NULL))";
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function check(string $label, string $status, string $message): array
    {
        return ['label' => $label, 'status' => $status, 'message' => $message];
    }
}
