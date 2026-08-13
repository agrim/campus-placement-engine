<?php

$candidate = $candidateData['candidate'];
$title = 'Candidate Trace - ' . $candidate['name'];
ob_start();
?>
<div class="page-head">
  <div>
    <h1><?= h($candidate['name']) ?></h1>
    <p class="muted"><?= h($candidate['external_id']) ?> / <?= h($candidate['program']) ?> / Location: <?= h($candidate['current_location']) ?><?= !empty($candidate['opted_out']) ? ' / Opted out' : '' ?></p>
    <?php if (!empty($candidate['accommodation_notes'])): ?>
      <p class="muted"><strong>Accommodation:</strong> <?= h($candidate['accommodation_notes']) ?></p>
    <?php endif; ?>
    <?php if (!empty($candidate['tags'])): ?>
      <p class="muted"><strong>Tags:</strong> <?= h($candidate['tags']) ?></p>
    <?php endif; ?>
    <?php if (($candidate['custom_fields_json'] ?? '{}') !== '{}'): ?>
      <p class="muted"><strong>Custom fields:</strong> <?= h($candidate['custom_fields_json']) ?></p>
    <?php endif; ?>
  </div>
  <a class="button" href="/">Back to board</a>
</div>

<section class="panel">
  <h2>Applications</h2>
  <table class="table">
    <thead><tr><th>Company</th><th>Status</th><th>Route</th><th>Slot</th><th>Waitlist</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($candidateData['applications'] as $app): ?>
      <tr>
        <td><?= h($app['company_code']) ?> - <?= h($app['company_name']) ?></td>
        <td><?= h($workflow->statusLabel($app['current_status'])) ?></td>
        <td><?= h($app['route_summary'] ?? '') ?></td>
        <td><?= h($app['slot_assignment_summary'] ?? '') ?></td>
        <td><?= h($app['waitlist_rank'] ?? '') ?></td>
        <td><?= h($app['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Transition history</h2>
  <table class="table">
    <thead><tr><th>Time</th><th>Company</th><th>Move</th><th>Actor</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($candidateData['events'] as $event): ?>
      <tr>
        <td><?= h($event['created_at']) ?></td>
        <td><?= h($event['company_code']) ?></td>
        <td><?= h($workflow->statusLabel($event['from_status'])) ?> -> <?= h($workflow->statusLabel($event['to_status'])) ?></td>
        <td><?= h($event['actor_name'] ?: $event['actor_role']) ?></td>
        <td><?= h($event['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$candidateData['events']): ?>
      <tr><td colspan="5">No transitions yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
