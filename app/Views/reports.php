<?php

$title = 'Reports';
$totals = $summary['totals'] ?? [];
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Reports</h1>
    <p class="muted">Placement counters and operating summaries.</p>
  </div>
</div>

<section class="panel">
  <h2>Placement Counter</h2>
  <div class="grid-stats">
    <div class="stat"><strong><?= h($totals['candidates'] ?? 0) ?></strong>Candidates</div>
    <div class="stat"><strong><?= h($totals['placed_candidates'] ?? 0) ?></strong>Placed</div>
    <div class="stat"><strong><?= h($totals['unplaced_candidates'] ?? 0) ?></strong>Unplaced</div>
    <div class="stat"><strong><?= h($totals['applications'] ?? 0) ?></strong>Applications</div>
    <div class="stat"><strong><?= h($totals['active_applications'] ?? 0) ?></strong>Active</div>
  </div>
</section>

<section class="panel">
  <h2>Application Status</h2>
  <table class="table">
    <thead><tr><th>Status</th><th>Count</th></tr></thead>
    <tbody>
    <?php foreach (($summary['applicationStatusCounts'] ?? []) as $row): ?>
      <tr><td><?= h($row['label']) ?></td><td><?= h($row['count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Placements By Company</h2>
  <table class="table">
    <thead><tr><th>Company</th><th>Placed</th></tr></thead>
    <tbody>
    <?php foreach (($summary['placementsByCompany'] ?? []) as $row): ?>
      <tr><td><?= h($row['code']) ?> - <?= h($row['name']) ?></td><td><?= h($row['placed_count']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($summary['placementsByCompany'])): ?><tr><td colspan="2">No placements recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Candidates By Program</h2>
  <table class="table">
    <thead><tr><th>Program</th><th>Total</th><th>Placed</th></tr></thead>
    <tbody>
    <?php foreach (($summary['candidatesByProgram'] ?? []) as $row): ?>
      <tr><td><?= h($row['program']) ?></td><td><?= h($row['candidate_count']) ?></td><td><?= h($row['placed_count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Candidates By Location</h2>
  <table class="table">
    <thead><tr><th>Location</th><th>Candidates</th></tr></thead>
    <tbody>
    <?php foreach (($summary['candidatesByLocation'] ?? []) as $row): ?>
      <tr><td><?= h($row['current_location']) ?></td><td><?= h($row['candidate_count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
