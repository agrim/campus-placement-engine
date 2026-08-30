<?php

$title = 'Maximise candidate opportunities';
$summary = $workspace['summary'];
$queues = $workspace['queues'];
$notes = $workspace['evidence_notes'];
$actionAccess = $actionAccess ?? ['candidate' => false, 'records' => false, 'advising' => false];
$queueNotice = static function (array $queue): string {
    return !empty($queue['truncated']) ? ' Showing the first 100 by priority.' : '';
};
$candidateLink = static fn (array $row): string => url('candidate', ['id' => (int) ($row['candidate_id'] ?? 0)]);
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Maximise candidate opportunities</h1>
    <p class="muted">Help more candidates reach the right opportunities by acting on the most important gaps first.</p>
  </div>
  <div class="grid-stats">
    <div class="stat"><strong><?= (int) $summary['coverage_needed'] ?></strong>Coverage needed</div>
    <div class="stat"><strong><?= (int) $summary['schedule_clashes'] ?></strong>Schedule clashes</div>
    <div class="stat"><strong><?= (int) $summary['attendance_follow_up'] ?></strong>Attendance follow-up</div>
    <div class="stat"><strong><?= (int) $summary['adviser_actions_due'] ?></strong>Adviser actions</div>
  </div>
</div>

<section class="panel">
  <div class="section-head"><div><h2>Interview and assessment clashes</h2><p class="muted"><?= h($notes['clashes']) ?></p></div><strong><?= (int) $queues['schedule_clashes']['count'] ?></strong></div>
  <?php if ($queues['schedule_clashes']['rows'] === []): ?>
    <p>No overlapping active slot assignments are recorded.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Candidate</th><th>Clash</th><th>First slot</th><th>Second slot</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($queues['schedule_clashes']['rows'] as $row): ?>
        <tr>
          <td><?= h($row['external_id']) ?> · <?= h($row['candidate_name']) ?></td>
          <td><strong><?= h($row['clash_kind']) ?></strong><br><span class="muted"><?= h($row['schedule_day'] !== '' ? $row['schedule_day'] : 'Same placement day') ?></span></td>
          <td><?= h($row['first_company_code']) ?> · <?= h($row['first_round_label']) ?><br><?= h($row['first_starts_at']) ?>–<?= h($row['first_ends_at']) ?></td>
          <td><?= h($row['second_company_code']) ?> · <?= h($row['second_round_label']) ?><br><?= h($row['second_starts_at']) ?>–<?= h($row['second_ends_at']) ?></td>
          <td><?php if ($actionAccess['candidate']): ?><a class="button" href="<?= h($candidateLink($row)) ?>">Review candidate</a><?php endif; ?><?php if ($actionAccess['records']): ?> <a class="button" href="<?= h(url('records')) ?>">Adjust slots</a><?php endif; ?><?php if (!$actionAccess['candidate'] && !$actionAccess['records']): ?>Ask an authorized operator<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted"><?= h($queueNotice($queues['schedule_clashes'])) ?></p>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="section-head"><div><h2>Attendance and confirmation follow-up</h2><p class="muted"><?= h($notes['attendance']) ?></p></div><strong><?= (int) $queues['attendance_follow_up']['count'] ?></strong></div>
  <?php if ($queues['attendance_follow_up']['rows'] === []): ?>
    <p>No slot-assignment status currently needs follow-up.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Candidate</th><th>Opportunity</th><th>Slot</th><th>Recorded status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($queues['attendance_follow_up']['rows'] as $row): ?>
        <tr>
          <td><?= h($row['external_id']) ?> · <?= h($row['candidate_name']) ?></td>
          <td><?= h($row['company_code']) ?> · <?= h($row['round_label']) ?></td>
          <td><?= h(trim(($row['schedule_day'] !== '' ? $row['schedule_day'] . ' · ' : '') . $row['starts_at'] . '–' . $row['ends_at'], ' ·–')) ?></td>
          <td><strong><?= h($row['follow_up']) ?></strong><br><span class="muted"><?= h($row['assignment_status'] !== '' ? $row['assignment_status'] : 'Missing') ?></span></td>
          <td><?php if ($actionAccess['candidate']): ?><a class="button" href="<?= h($candidateLink($row)) ?>">Review candidate</a><?php endif; ?><?php if ($actionAccess['records']): ?> <a class="button" href="<?= h(url('records')) ?>">Update attendance</a><?php endif; ?><?php if (!$actionAccess['candidate'] && !$actionAccess['records']): ?>Ask an authorized operator<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted"><?= h($queueNotice($queues['attendance_follow_up'])) ?></p>
  <?php endif; ?>
</section>

<div class="two-col">
  <section class="panel">
    <div class="section-head"><div><h2>Candidate coverage needed</h2><p class="muted"><?= h($notes['coverage']) ?></p></div><strong><?= (int) $queues['coverage_needed']['count'] ?></strong></div>
    <?php if ($queues['coverage_needed']['rows'] === []): ?><p>Every in-scope candidate has an active application.</p><?php else: ?>
      <table class="table"><thead><tr><th>Candidate</th><th>Recorded links</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($queues['coverage_needed']['rows'] as $row): ?><tr><td><?= h($row['external_id']) ?> · <?= h($row['candidate_name']) ?><br><span class="muted"><?= h($row['program'] !== '' ? $row['program'] : 'Program missing') ?></span></td><td><?= (int) $row['application_count'] ?></td><td><?php if ($actionAccess['candidate']): ?><a href="<?= h($candidateLink($row)) ?>">Review</a><?php endif; ?><?php if ($actionAccess['records']): ?><?= $actionAccess['candidate'] ? ' · ' : '' ?><a href="<?= h(url('records')) ?>">Link opportunity</a><?php endif; ?><?php if (!$actionAccess['candidate'] && !$actionAccess['records']): ?>Ask an authorized operator<?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table><p class="muted"><?= h($queueNotice($queues['coverage_needed'])) ?></p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="section-head"><div><h2>Eligibility evidence to review</h2><p class="muted"><?= h($notes['eligibility']) ?></p></div><strong><?= (int) $queues['eligibility_review']['count'] ?></strong></div>
    <?php if ($queues['eligibility_review']['rows'] === []): ?><p>No missing baseline eligibility evidence is visible.</p><?php else: ?>
      <table class="table"><thead><tr><th>Candidate</th><th>Why review</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($queues['eligibility_review']['rows'] as $row): ?><tr><td><?= h($row['external_id']) ?> · <?= h($row['candidate_name']) ?></td><td><?= h($row['reason']) ?></td><td><?php if ($actionAccess['records']): ?><a href="<?= h(url('records')) ?>">Correct records</a><?php else: ?>Ask an authorized operator<?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table><p class="muted"><?= h($queueNotice($queues['eligibility_review'])) ?></p>
    <?php endif; ?>
  </section>
</div>

<div class="two-col">
  <section class="panel">
    <div class="section-head"><div><h2>Configured process deadlines</h2><p class="muted"><?= h($notes['deadlines']) ?></p></div><strong><?= (int) $queues['configured_deadlines']['count'] ?></strong></div>
    <?php if ($queues['configured_deadlines']['rows'] === []): ?><p>No configured process cut-off is approaching or needs date setup.</p><?php else: ?>
      <table class="table"><thead><tr><th>Opportunity</th><th>Deadline</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($queues['configured_deadlines']['rows'] as $row): ?><tr><td><?= h($row['company_code']) ?> · <?= h($row['company_name']) ?></td><td><?= h($row['deadline_display']) ?></td><td><?= h($row['deadline_status']) ?></td><td><?php if ($actionAccess['records']): ?><a href="<?= h(url('records')) ?>">Review process</a><?php else: ?>Ask an authorized operator<?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table><p class="muted"><?= h($queueNotice($queues['configured_deadlines'])) ?></p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="section-head"><div><h2>Adviser action due next</h2><p class="muted">Open advising tasks due within seven days appear only when the Career Advising module and your permissions support them.</p></div><strong><?= (int) $queues['adviser_actions_due']['count'] ?></strong></div>
    <?php if (!$workspace['advising_available']): ?><p>Career Advising tasks are unavailable because the module or your permission is not active.</p><?php elseif ($queues['adviser_actions_due']['rows'] === []): ?><p>No adviser task is due in the current window.</p><?php else: ?>
      <table class="table"><thead><tr><th>Candidate</th><th>Task</th><th>Due</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($queues['adviser_actions_due']['rows'] as $row): ?><tr><td><?= h($row['external_id'] ?: $row['subject_reference']) ?><?= $row['candidate_name'] ? ' · ' . h($row['candidate_name']) : '' ?></td><td><?= h($row['title']) ?></td><td><?= h($row['due_on']) ?> · <?= h($row['due_status']) ?></td><td><a href="<?= h(url('advising')) ?>">Open advising</a></td></tr><?php endforeach; ?>
      </tbody></table><p class="muted"><?= h($queueNotice($queues['adviser_actions_due'])) ?></p>
    <?php endif; ?>
  </section>
</div>

<div class="two-col">
  <section class="panel">
    <div class="section-head"><div><h2>Repeated no-progress signals</h2><p class="muted">Candidates with two or more applications that previously moved but are back at the workflow's initial state.</p></div><strong><?= (int) $queues['repeated_no_progress']['count'] ?></strong></div>
    <?php if ($queues['repeated_no_progress']['rows'] === []): ?><p>No repeated no-progress signal is recorded.</p><?php else: ?>
      <table class="table"><thead><tr><th>Candidate</th><th>Applications back at start</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($queues['repeated_no_progress']['rows'] as $row): ?><tr><td><?= h($row['external_id']) ?> · <?= h($row['candidate_name']) ?></td><td><?= (int) $row['no_progress_count'] ?></td><td><?php if ($actionAccess['candidate']): ?><a href="<?= h($candidateLink($row)) ?>">Review history</a><?php else: ?>Ask an authorized operator<?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table><p class="muted"><?= h($queueNotice($queues['repeated_no_progress'])) ?></p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="section-head"><div><h2>Opportunities without recorded coverage</h2><p class="muted"><?= h($notes['low_coverage']) ?></p></div><strong><?= (int) $queues['low_coverage_opportunities']['count'] ?></strong></div>
    <?php if ($queues['low_coverage_opportunities']['rows'] === []): ?><p>Every open opportunity has at least one candidate link.</p><?php else: ?>
      <table class="table"><thead><tr><th>Opportunity</th><th>Process</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($queues['low_coverage_opportunities']['rows'] as $row): ?><tr><td><?= h($row['company_code']) ?> · <?= h($row['company_name']) ?></td><td><?= h($row['process_type'] !== '' ? $row['process_type'] : 'Not specified') ?></td><td><?php if ($actionAccess['records']): ?><a href="<?= h(url('records')) ?>">Review candidate links</a><?php else: ?>Ask an authorized operator<?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table><p class="muted"><?= h($queueNotice($queues['low_coverage_opportunities'])) ?></p>
    <?php endif; ?>
  </section>
</div>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
