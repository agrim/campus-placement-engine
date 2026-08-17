<?php

$candidateLabel = cpe_term('candidate');
$companyLabel = cpe_term('company');
$title = cpe_candidate_status_title();
ob_start();
?>
<section class="panel" style="max-width:720px">
  <h1><?= h($title) ?></h1>
  <form method="get" action="/">
    <input type="hidden" name="r" value="student">
    <label><?= h($candidateLabel) ?> ID</label>
    <input name="external_id" value="<?= h($externalId) ?>" placeholder="C001">
    <p><button class="primary" type="submit">Check status</button></p>
  </form>
</section>

<?php if ($externalId !== ''): ?>
  <section class="panel">
    <?php if (!$studentData): ?>
      <p>No <?= h(strtolower($candidateLabel)) ?> found for <?= h($externalId) ?>.</p>
    <?php else: ?>
      <h2><?= h($studentData['candidate']['name']) ?></h2>
      <p class="muted"><?= h($studentData['candidate']['external_id']) ?> / Location: <?= h($studentData['candidate']['current_location']) ?></p>
      <table class="table">
        <thead><tr><th><?= h($companyLabel) ?></th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($studentData['applications'] as $app): ?>
          <tr>
            <td><?= h($app['company_code']) ?> - <?= h($app['company_name']) ?></td>
            <td><?= h($workflow->statusLabel($app['current_status'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
