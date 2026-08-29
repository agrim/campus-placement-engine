<?php

declare(strict_types=1);

namespace App\Operations;

use App\Support\Database;
use PDO;

final class MetricsService
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function snapshot(): array
    {
        $modules = [];
        foreach ($this->pdo->query('SELECT module_key, enabled FROM module_installations ORDER BY module_key')->fetchAll() as $row) {
            $modules[(string) $row['module_key']] = (int) $row['enabled'];
        }
        return [
            'app_version' => (string) cpe_config('app.version', '0.0.0'),
            'database_driver' => Database::driver(),
            'pending_migrations' => count(Database::pendingMigrations()),
            'domain_events_pending' => $this->columnExists('domain_event_outbox', 'public_event_type')
                ? $this->count(
                    'SELECT COUNT(*) FROM domain_event_outbox
                     WHERE public_event_type IS NOT NULL AND processed_at IS NULL AND failed_at IS NULL',
                )
                : 0,
            'domain_events_dead_lettered' => $this->columnExists('domain_event_outbox', 'public_event_type')
                ? $this->count(
                    'SELECT COUNT(*) FROM domain_event_outbox
                     WHERE public_event_type IS NOT NULL AND failed_at IS NOT NULL',
                )
                : 0,
            'notification_deliveries_queued' => $this->count("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'queued'"),
            'notification_deliveries_failed' => $this->count("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'failed'"),
            'open_advising_tasks' => $this->tableExists('advising_tasks') ? $this->count("SELECT COUNT(*) FROM advising_tasks WHERE task_status = 'open'") : 0,
            'modules' => $modules,
        ];
    }

    public function prometheus(): string
    {
        $snapshot = $this->snapshot();
        $lines = [
            '# TYPE cpe_pending_migrations gauge',
            'cpe_pending_migrations ' . $snapshot['pending_migrations'],
            '# TYPE cpe_domain_events_pending gauge',
            'cpe_domain_events_pending ' . $snapshot['domain_events_pending'],
            '# TYPE cpe_domain_events_dead_lettered gauge',
            'cpe_domain_events_dead_lettered ' . $snapshot['domain_events_dead_lettered'],
            '# TYPE cpe_notification_deliveries gauge',
            'cpe_notification_deliveries{status="queued"} ' . $snapshot['notification_deliveries_queued'],
            'cpe_notification_deliveries{status="failed"} ' . $snapshot['notification_deliveries_failed'],
            '# TYPE cpe_advising_tasks_open gauge',
            'cpe_advising_tasks_open ' . $snapshot['open_advising_tasks'],
        ];
        foreach ($snapshot['modules'] as $module => $enabled) {
            $lines[] = 'cpe_module_enabled{module="' . addcslashes($module, "\\\"") . '"} ' . $enabled;
        }
        return implode("\n", $lines) . "\n";
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $this->pdo->query("SELECT {$column} FROM {$table} LIMIT 0");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
