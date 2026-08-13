<?php

$title = cpe_public_placements_title();
$companyLabel = cpe_term('company');
ob_start();
?>
<div class="page-head">
  <div>
    <h1><?= h($title) ?></h1>
    <p class="muted">Read-only aggregate placement results.</p>
  </div>
  <?php if (!empty($studentLookupAllowed)): ?>
    <a class="button" href="<?= h(url('student')) ?>"><?= h(cpe_candidate_status_title()) ?></a>
  <?php endif; ?>
</div>

<section class="panel">
  <table class="table">
    <thead><tr><th><?= h($companyLabel) ?></th><th>Program</th><th>Placements</th></tr></thead>
    <tbody>
      <?php foreach ($placements as $placement): ?>
        <tr>
          <td><?= h($placement['company_code']) ?> - <?= h($placement['company_name']) ?></td>
          <td><?= h($placement['program']) ?></td>
          <td><?= h($placement['placed_count']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$placements): ?><tr><td colspan="3">No placements recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
