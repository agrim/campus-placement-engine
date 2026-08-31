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
  <form method="post" action="<?= h(url('wanted')) ?>">
    <?= Csrf::input() ?>
    <label for="wanted_candidate_id">Candidate</label>
    <select id="wanted_candidate_id" name="candidate_id" required>
      <?php foreach ($candidates as $candidate): ?>
        <option value="<?= h($candidate['id']) ?>"><?= h($candidate['external_id']) ?> - <?= h($candidate['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="wanted_reason">Reason</label>
    <input id="wanted_reason" name="reason" required placeholder="Needed at panel, missing from room, urgent callback...">
    <p><button class="primary" type="submit">Create wanted alert</button></p>
  </form>
</section>

<section class="panel">
  <h2>Alerts</h2>
  <table class="table">
    <thead><tr><th>Candidate</th><th>Status</th><th>Reason</th><th>Created</th><th>Action</th></tr></thead>
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
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
