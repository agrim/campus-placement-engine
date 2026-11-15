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
  <h2 id="report-table-0">Application Status</h2>
  <div class="table-scroll" role="region" aria-labelledby="report-table-0" tabindex="0">
  <table class="table">
    <thead><tr><th scope="col">Status</th><th scope="col">Count</th></tr></thead>
    <tbody>
    <?php foreach (($summary['applicationStatusCounts'] ?? []) as $row): ?>
      <tr><td><?= h($row['label']) ?></td><td><?= h($row['count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2 id="report-table-1">Placements By Company</h2>
  <div class="table-scroll" role="region" aria-labelledby="report-table-1" tabindex="0">
  <table class="table">
    <thead><tr><th scope="col">Company</th><th scope="col">Placed</th></tr></thead>
    <tbody>
    <?php foreach (($summary['placementsByCompany'] ?? []) as $row): ?>
      <tr><td><?= h($row['code']) ?> - <?= h($row['name']) ?></td><td><?= h($row['placed_count']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($summary['placementsByCompany'])): ?><tr><td colspan="2">No placements recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2 id="report-table-2">Candidates By Program</h2>
  <div class="table-scroll" role="region" aria-labelledby="report-table-2" tabindex="0">
  <table class="table">
    <thead><tr><th scope="col">Program</th><th scope="col">Total</th><th scope="col">Placed</th></tr></thead>
    <tbody>
    <?php foreach (($summary['candidatesByProgram'] ?? []) as $row): ?>
      <tr><td><?= h($row['program']) ?></td><td><?= h($row['candidate_count']) ?></td><td><?= h($row['placed_count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2 id="report-table-3">Candidates By Location</h2>
  <div class="table-scroll" role="region" aria-labelledby="report-table-3" tabindex="0">
  <table class="table">
    <thead><tr><th scope="col">Location</th><th scope="col">Candidates</th></tr></thead>
    <tbody>
    <?php foreach (($summary['candidatesByLocation'] ?? []) as $row): ?>
      <tr><td><?= h($row['current_location']) ?></td><td><?= h($row['candidate_count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
