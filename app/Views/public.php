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
  <div class="table-scroll" role="region" aria-labelledby="public-results-caption" tabindex="0">
  <table class="table">
    <caption id="public-results-caption" class="visually-hidden"><?= h($title) ?></caption>
    <thead><tr><th scope="col"><?= h($companyLabel) ?></th><th scope="col">Program</th><th scope="col">Placements</th></tr></thead>
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
  </div>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
