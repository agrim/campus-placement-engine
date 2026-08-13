<?php

use App\Security\Csrf;

$title = 'Import Data';
$samples = [
    'candidates' => "external_id,name,program,tags,current_location,accommodation_notes,opted_out\nC006,Meera Shah,MBA,\"finance,day-one\",CP,Ground-floor room,0",
    'companies' => "code,name,slot,offer_tier,tags\nKITE,Kite Ventures,Day 1 / Slot 1,dream,\"fintech,priority\"",
    'rounds' => "company_code,sequence,label,round_type,room,duration_minutes,instructions\nKITE,1,Case discussion,case,Room A1,45,Carry score sheet",
    'schedules' => "company_code,round_sequence,round_label,sequence,room,schedule_day,starts_at,ends_at,capacity,schedule_status,notes\nKITE,1,Case discussion,1,Room A1,1,09:00,09:45,2,active,Case packets ready",
    'panelists' => "company_code,round_sequence,round_label,sequence,name,role,affiliation,contact,availability_status,notes\nKITE,1,Case discussion,1,Arun Iyer,Lead panelist,Kite Ventures,,active,Case scoring owner",
    'assignments' => "candidate_external_id,company_code,round_sequence,round_label,schedule_sequence,room,schedule_day,starts_at,assignment_sequence,assignment_status,notes\nC006,KITE,1,Case discussion,1,Room A1,1,09:00,1,assigned,First case slot",
    'unavailability' => "candidate_external_id,label,schedule_day,starts_at,ends_at,notes\nC006,Exam block,1,10:00,11:00,Do not schedule during test",
    'shortlists' => "candidate_external_id,company_code,status,waitlist_rank\nC006,KITE,scheduled,",
    'legacy' => "external_id,name,program,company_code,company_name,slot,stat1,stat2,stat3\nC007,Dev Malhotra,MBA,ORBIT,Orbit Labs,Day 1,1,2,3",
];
$selectedType = $selectedType ?? 'candidates';
$csv = $csv ?? ($samples[$selectedType] ?? $samples['candidates']);
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Import</h1>
    <p class="muted">Paste CSV. Common college headers are normalized server-side; no spreadsheet dependency is required.</p>
  </div>
</div>

<div class="split">
  <section class="panel">
    <form method="post" action="<?= h(url('import')) ?>">
      <?= Csrf::input() ?>
      <label for="type">Import type</label>
      <select id="type" name="type">
        <option value="candidates" <?= $selectedType === 'candidates' ? 'selected' : '' ?>>Candidates</option>
        <option value="companies" <?= $selectedType === 'companies' ? 'selected' : '' ?>>Companies</option>
        <option value="rounds" <?= $selectedType === 'rounds' ? 'selected' : '' ?>>Company rounds</option>
        <option value="schedules" <?= $selectedType === 'schedules' ? 'selected' : '' ?>>Round schedule</option>
        <option value="panelists" <?= $selectedType === 'panelists' ? 'selected' : '' ?>>Round panelists</option>
        <option value="assignments" <?= $selectedType === 'assignments' ? 'selected' : '' ?>>Interview slot assignments</option>
        <option value="unavailability" <?= $selectedType === 'unavailability' ? 'selected' : '' ?>>Candidate unavailable windows</option>
        <option value="shortlists" <?= $selectedType === 'shortlists' ? 'selected' : '' ?>>Shortlists</option>
        <option value="legacy" <?= $selectedType === 'legacy' ? 'selected' : '' ?>>Legacy wide table</option>
      </select>
      <label for="csv">CSV</label>
      <textarea id="csv" name="csv" required><?= h($csv) ?></textarea>
      <p class="button-row">
        <button type="submit" name="action" value="preview">Preview CSV</button>
        <button class="primary" type="submit" name="action" value="import">Import CSV</button>
      </p>
    </form>
    <?php if (!empty($report)): ?>
      <div class="import-report">
        <h2>Preview Report</h2>
        <div class="grid-stats">
          <div class="stat"><strong><?= h($report['rows']) ?></strong>Rows</div>
          <div class="stat"><strong><?= h($report['creates']) ?></strong>Create</div>
          <div class="stat"><strong><?= h($report['updates']) ?></strong>Update</div>
          <div class="stat"><strong><?= h(count($report['warnings'])) ?></strong>Warnings</div>
          <div class="stat"><strong><?= h(count($report['errors'])) ?></strong>Errors</div>
        </div>
        <p>
          <span class="badge <?= $report['valid'] ? 'ok' : 'fail' ?>"><?= $report['valid'] ? 'READY' : 'FIX CSV' ?></span>
        </p>
        <?php if (!empty($report['errors'])): ?>
          <h3>Errors</h3>
          <ul class="compact-list">
            <?php foreach ($report['errors'] as $error): ?>
              <li><?= h($error) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($report['warnings'])): ?>
          <h3>Warnings</h3>
          <ul class="compact-list">
            <?php foreach ($report['warnings'] as $warning): ?>
              <li><?= h($warning) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($report['samples'])): ?>
          <h3>Sample actions</h3>
          <ul class="compact-list">
            <?php foreach ($report['samples'] as $sample): ?>
              <li><?= h($sample) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
  <aside class="panel">
    <h2>Templates</h2>
    <?php foreach ($samples as $name => $sample): ?>
      <h3><?= h(ucfirst($name)) ?></h3>
      <pre><?= h($sample) ?></pre>
    <?php endforeach; ?>
  </aside>
</div>

<section class="panel">
  <h2>Recent Import Rollback Snapshots</h2>
  <?php if (empty($recentImports)): ?>
    <p class="muted">No import rollback snapshots yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>ID</th><th>Type</th><th>Rows</th><th>Created</th><th>Status</th><th>Rollback</th></tr></thead>
      <tbody>
      <?php foreach ($recentImports as $import): ?>
        <tr>
          <td><?= h($import['id']) ?></td>
          <td><?= h($import['type']) ?></td>
          <td><?= h($import['rows']) ?></td>
          <td><?= h($import['created_at']) ?></td>
          <td>
            <?php if (!empty($import['restored_at'])): ?>
              Restored <?= h($import['restored_at']) ?>
            <?php elseif (empty($import['backup_exists'])): ?>
              Missing snapshot
            <?php else: ?>
              Ready
            <?php endif; ?>
          </td>
          <td>
            <?php if (empty($import['restored_at']) && !empty($import['backup_exists'])): ?>
              <form method="post" action="<?= h(url('import-rollback')) ?>" data-confirm="Restore the whole database to this pre-import snapshot?">
                <?= Csrf::input() ?>
                <input type="hidden" name="id" value="<?= h($import['id']) ?>">
                <button type="submit">Rollback</button>
              </form>
            <?php else: ?>
              <span class="muted">Unavailable</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">Rollback restores the whole application database to the pre-import snapshot. Use it immediately after a mistaken import.</p>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
