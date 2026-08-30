<?php

use App\Security\Csrf;
use App\Support\Auth;

$title = 'System';
$systemUser = Auth::user();
$demoData = $demoData ?? [];
$demoTotal = array_sum(array_map('intval', $demoData));
ob_start();
?>
<div class="page-head">
  <div>
    <h1>System</h1>
    <p class="muted">Local runtime and workflow checks.</p>
  </div>
</div>

<section class="panel">
  <h2>Runtime</h2>
  <table class="table">
    <tr><th>PHP</th><td><?= h($phpVersion) ?></td></tr>
    <tr><th>Database</th><td><?= h($databaseDescription ?? 'Configured relational database') ?></td></tr>
    <tr><th>Writable data folder</th><td><?= is_writable(cpe_data_path()) ? 'Yes' : 'No' ?></td></tr>
  </table>
</section>

<section class="panel system-action">
  <h2>Dummy data cleanup</h2>
  <p class="muted">
    The installer can load a fully live synthetic placement drive for local
    testing. Clear it here before importing actual college data.
  </p>
  <table class="table">
    <tr><th>Dummy candidates</th><td><?= h((string) ($demoData['candidates'] ?? 0)) ?></td></tr>
    <tr><th>Dummy companies</th><td><?= h((string) ($demoData['companies'] ?? 0)) ?></td></tr>
    <tr><th>Dummy applications</th><td><?= h((string) ($demoData['applications'] ?? 0)) ?></td></tr>
    <tr><th>Dummy rounds/schedules/panelists</th><td><?= h((string) (($demoData['rounds'] ?? 0) + ($demoData['schedules'] ?? 0) + ($demoData['panelists'] ?? 0))) ?></td></tr>
    <tr><th>Dummy slot assignments</th><td><?= h((string) ($demoData['slot_assignments'] ?? 0)) ?></td></tr>
    <tr><th>Demo users</th><td><?= h((string) ($demoData['demo_users'] ?? 0)) ?></td></tr>
  </table>
  <p class="muted">
    This removes reserved synthetic records: <code>C001</code>-<code>C005</code>,
    <code>ATLAS</code>/<code>NOVA</code>/<code>RIVER</code>,
    <code>QAC###</code>/<code>QA##</code>, related operational rows, and demo
    role accounts. It keeps the installed app, admin users, settings, workflows,
    migrations, and real non-demo records.
  </p>
  <?php if (Auth::hasCapability($systemUser, 'placement.demo.clear')): ?>
    <form method="post" action="<?= h(url('system-clear-demo')) ?>" data-confirm="Clear all dummy placement data? This keeps configuration and admin users, but removes the synthetic demo drive.">
      <?= Csrf::input() ?>
      <input type="hidden" name="confirm" value="clear-demo-data">
      <button class="danger" type="submit" <?= $demoTotal > 0 ? '' : 'disabled' ?>>Clear dummy data</button>
    </form>
  <?php else: ?>
    <p class="muted">Only administrators can clear dummy data.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Integration delivery operations</h2>
  <?php $integrationHealth = $readiness['webhookIntegrations']; ?>
  <table class="table">
    <tr><th>Worker required / configured</th><td><?= $integrationHealth['worker_required'] ? 'Yes' : 'No' ?> / <?= $integrationHealth['worker_configured'] ? 'Yes' : 'No' ?></td></tr>
    <tr><th>Worker heartbeat</th><td><?= h($integrationHealth['scheduler_freshness']) ?> · last run <?= h($integrationHealth['worker_status']) ?><?= $integrationHealth['worker_heartbeat_age_seconds'] === null ? '' : ' · ' . (int) $integrationHealth['worker_heartbeat_age_seconds'] . ' seconds ago' ?></td></tr>
    <tr><th>Delivery backlog</th><td><?= (int) $integrationHealth['pending'] ?> pending · <?= (int) $integrationHealth['dead_lettered'] ?> dead-lettered · oldest <?= $integrationHealth['oldest_pending_age_seconds'] === null ? 'none' : (int) $integrationHealth['oldest_pending_age_seconds'] . ' seconds' ?></td></tr>
    <tr><th>Webhook TLS policy</th><td><?= h($integrationHealth['tls_policy_message']) ?></td></tr>
    <tr><th>External encryption key</th><td><?= $integrationHealth['encryption_key_present'] ? 'Present' : 'Not configured' ?> · referenced versions <?= $integrationHealth['encryption_key_references_ready'] ? 'ready' : 'need review' ?></td></tr>
    <tr><th>Database driver</th><td><?= h($integrationHealth['database_driver']) ?> · <?= $integrationHealth['database_driver_ready'] ? 'Ready' : 'Unavailable' ?></td></tr>
  </table>
  <p class="muted">Set <code>CPE_INTEGRATION_WORKER_CONFIGURED=1</code> only after the documented cron or scheduler entry is installed. A heartbeat proves a run; the setting records the operator's scheduler attestation.</p>
</section>

<section class="panel">
  <h2>Workflow validation</h2>
  <?php if (!$workflowErrors): ?>
    <p class="flash success">Workflow configuration is valid.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($workflowErrors as $error): ?>
        <li><?= h($error) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Live-day readiness</h2>
  <table class="table">
    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($readiness['checks'] as $check): ?>
      <tr>
        <td><?= h($check['label']) ?></td>
        <td><span class="badge <?= h($check['status']) ?>"><?= h(strtoupper($check['status'])) ?></span></td>
        <td><?= h($check['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!empty($readiness['backup']['present'])): ?>
    <p class="muted">Latest backup is present in the configured backup directory.</p>
  <?php endif; ?>
  <?php if (!empty($readiness['staleApplications']['rows'])): ?>
    <h3>Stale active applications</h3>
    <table class="table">
      <thead><tr><th>Candidate</th><th>Company</th><th>Status</th><th>Last change</th></tr></thead>
      <tbody>
      <?php foreach ($readiness['staleApplications']['rows'] as $row): ?>
        <tr>
          <td><?= h($row['external_id']) ?> - <?= h($row['candidate_name']) ?></td>
          <td><?= h($row['company_code']) ?></td>
          <td><?= h($row['current_status']) ?></td>
          <td><?= h($row['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if (!empty($readiness['capacityAlerts']['rows'])): ?>
    <h3>Company capacity alerts</h3>
    <table class="table">
      <thead><tr><th>Company</th><th>Active</th><th>Cap</th></tr></thead>
      <tbody>
      <?php foreach ($readiness['capacityAlerts']['rows'] as $row): ?>
        <tr>
          <td><?= h($row['code']) ?> - <?= h($row['name']) ?></td>
          <td><?= h($row['active_count']) ?></td>
          <td><?= h($row['max_active']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if (!empty($readiness['calendarWarnings']['rows'])): ?>
    <h3>Calendar guardrail alerts</h3>
    <table class="table">
      <thead><tr><th>Company</th><th>Round</th><th>Schedule</th><th>Date</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($readiness['calendarWarnings']['rows'] as $row): ?>
        <tr>
          <td><?= h($row['company_code']) ?></td>
          <td><?= h($row['round_sequence']) ?>. <?= h($row['round_label']) ?></td>
          <td><?= h($row['schedule_sequence']) ?> / <?= h($row['room']) ?> / <?= h($row['starts_at']) ?></td>
          <td><?= h($row['resolved_date']) ?> <?= h($row['weekday']) ?></td>
          <td><?= h($row['reason']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if (!empty($readiness['activeConflicts']['rows'])): ?>
    <h3>Active company conflicts</h3>
    <table class="table">
      <thead><tr><th>Candidate</th><th>Active companies</th><th>Count</th></tr></thead>
      <tbody>
      <?php foreach ($readiness['activeConflicts']['rows'] as $row): ?>
        <tr>
          <td><?= h($row['external_id']) ?> - <?= h($row['candidate_name']) ?></td>
          <td><?= h($row['company_codes']) ?></td>
          <td><?= h($row['active_count']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Recent audit log</h2>
  <?php
  ?>
  <table class="table">
    <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $row): ?>
      <tr>
        <td><?= h($row['created_at']) ?></td>
        <td><?= h($row['actor_name'] ?: 'system') ?></td>
        <td><?= h($row['action']) ?></td>
        <td><?= h($row['subject_type']) ?> #<?= h($row['subject_id'] ?? '') ?></td>
        <td><?= h($row['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
