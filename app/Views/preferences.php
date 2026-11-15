<?php

use App\Security\Csrf;

$title = 'Preference Backloop';
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Preference Backloop</h1>
    <p class="muted">Ask and resolve candidate choice when multiple companies conflict.</p>
  </div>
</div>

<section class="panel">
  <h2>Create request</h2>
  <form method="post" action="<?= h(url('preferences')) ?>" data-field-validation>
    <?= Csrf::input() ?>
    <label for="preference_candidate_id">Candidate (required)</label>
    <select id="preference_candidate_id" name="candidate_id" required data-error="preference-candidate-error">
      <?php foreach ($candidates as $candidate): ?>
        <option value="<?= h($candidate['id']) ?>"><?= h($candidate['external_id']) ?> - <?= h($candidate['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p id="preference-candidate-error" class="field-error" hidden>Error: Select an available candidate.</p>
    <label for="preference_company_ids">Company options (required)</label>
    <select id="preference_company_ids" name="company_ids[]" multiple required size="5" data-error="preference-companies-error" data-min-selections="2" aria-describedby="preference-companies-hint">
      <?php foreach ($companies as $company): ?>
        <option value="<?= h($company['id']) ?>"><?= h($company['code']) ?> - <?= h($company['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p id="preference-companies-hint" class="muted">Select at least two companies to compare.</p>
    <p id="preference-companies-error" class="field-error" hidden>Error: Select at least two company options.</p>
    <label for="preference_note">Note</label>
    <input id="preference_note" name="note" placeholder="Why is this decision needed?">
    <p><button class="primary" type="submit">Create preference request</button></p>
  </form>
</section>

<section class="panel">
  <h2>Requests</h2>
  <table class="table">
    <thead><tr><th>Candidate</th><th>Status</th><th>Options</th><th>Decision</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $request): ?>
      <?php $options = $service->preferenceOptions((int) $request['id']); ?>
      <tr>
        <td><?= h($request['external_id']) ?> - <?= h($request['candidate_name']) ?></td>
        <td><?= h($request['status']) ?></td>
        <td>
          <?php foreach ($options as $option): ?>
            <?php if ($request['status'] === 'open'): ?>
              <form class="inline" method="post" action="<?= h(url('preferences-resolve')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="request_id" value="<?= h($request['id']) ?>">
                <input type="hidden" name="company_id" value="<?= h($option['company_id']) ?>">
                <button type="submit"><?= h($option['code']) ?></button>
              </form>
            <?php else: ?>
              <?= h($option['code']) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </td>
        <td><?= h($request['decision_code'] ?: '') ?></td>
        <td><?= h($request['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$requests): ?><tr><td colspan="5">No preference requests yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
