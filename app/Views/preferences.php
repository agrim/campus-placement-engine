<?php

use App\Security\Csrf;
use App\Support\Flash;

$title = 'Preference Backloop';
$preferenceErrors = ['preference.create' => [], 'preference.resolve' => []];
$layoutFlashes = [];
foreach (Flash::pull() as $flash) {
    $origin = $flash['origin'] ?? '';
    if ($flash['type'] === 'error' && isset($preferenceErrors[$origin])) {
        $preferenceErrors[$origin][] = $flash;
    } else {
        $layoutFlashes[] = $flash;
    }
}
$createErrors = $preferenceErrors['preference.create'];
$resolveErrors = $preferenceErrors['preference.resolve'];
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Preference Backloop</h1>
    <p class="muted">Ask and resolve candidate choice when multiple companies conflict.</p>
  </div>
</div>

<section class="panel">
  <h2 id="preference-create-heading">Create request</h2>
  <form method="post" action="<?= h(url('preferences')) ?>" data-field-validation aria-labelledby="preference-create-heading"<?= $createErrors ? ' aria-describedby="preference-create-errors"' : '' ?>>
    <?php if ($createErrors): ?>
      <div id="preference-create-errors" role="group" tabindex="-1" autofocus aria-label="Create request failed" aria-describedby="preference-create-messages">
        <div id="preference-create-messages">
        <?php foreach ($createErrors as $flash): ?>
          <p class="flash error"><?= h($flash['message']) ?></p>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
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

<section class="panel" aria-labelledby="preference-requests-heading"<?= $resolveErrors ? ' aria-describedby="preference-resolve-errors"' : '' ?>>
  <h2 id="preference-requests-heading">Requests</h2>
  <?php if ($resolveErrors): ?>
    <div id="preference-resolve-errors" role="group" tabindex="-1"<?= !$createErrors ? ' autofocus' : '' ?> aria-label="Resolve request failed" aria-describedby="preference-resolve-messages">
      <div id="preference-resolve-messages">
      <?php foreach ($resolveErrors as $flash): ?>
        <p class="flash error"><?= h($flash['message']) ?></p>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="table-scroll" role="region" tabindex="0" aria-label="Preference requests table">
  <table class="table">
    <thead><tr><th scope="col">Candidate</th><th scope="col">Status</th><th scope="col">Options</th><th scope="col">Decision</th><th scope="col">Note</th></tr></thead>
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
  </div>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
