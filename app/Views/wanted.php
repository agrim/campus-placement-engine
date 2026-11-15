<?php

use App\Security\Csrf;

$title = 'Wanted Alerts';
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Wanted Alerts</h1>
    <p class="muted">Track missing or urgently needed candidates.</p>
  </div>
</div>

<section class="panel">
  <h2>Create alert</h2>
  <form method="post" action="<?= h(url('wanted')) ?>" data-field-validation>
    <?= Csrf::input() ?>
    <label for="wanted_candidate_id">Candidate (required)</label>
    <select id="wanted_candidate_id" name="candidate_id" required data-error="wanted-candidate-error">
      <?php foreach ($candidates as $candidate): ?>
        <option value="<?= h($candidate['id']) ?>"><?= h($candidate['external_id']) ?> - <?= h($candidate['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p id="wanted-candidate-error" class="field-error" hidden>Error: Select an available candidate.</p>
    <label for="wanted_reason">Reason (required)</label>
    <input id="wanted_reason" name="reason" required data-error="wanted-reason-error" placeholder="Needed at panel, missing from room, urgent callback...">
    <p id="wanted-reason-error" class="field-error" hidden>Error: Enter a reason for the alert.</p>
    <p><button class="primary" type="submit">Create wanted alert</button></p>
  </form>
</section>

<section class="panel">
  <h2 id="wanted-alerts-heading">Alerts</h2>
  <div class="table-scroll" role="region" aria-labelledby="wanted-alerts-heading" tabindex="0">
  <table class="table">
    <thead><tr><th scope="col">Candidate</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Created</th><th scope="col">Action</th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $alert): ?>
      <tr>
        <td><?= h($alert['external_id']) ?> - <?= h($alert['candidate_name']) ?></td>
        <td><?= h($alert['status']) ?></td>
        <td><?= h($alert['reason']) ?></td>
        <td><?= h($alert['created_at']) ?></td>
        <td>
          <?php if ($alert['status'] === 'open'): ?>
            <form method="post" action="<?= h(url('wanted-resolve')) ?>">
              <?= Csrf::input() ?>
              <input type="hidden" name="alert_id" value="<?= h($alert['id']) ?>">
              <button type="submit">Resolve</button>
            </form>
          <?php else: ?>
            Resolved
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$alerts): ?><tr><td colspan="5">No wanted alerts yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
