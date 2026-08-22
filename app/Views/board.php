<?php

use App\Security\Csrf;
use App\Support\Auth;

$title = $roleContext['title'] ?? 'Control Board';
$boardCardFields = $boardCardFields ?? [];
$cardFieldsDefaultVisible = $boardCardFields === [];
$showCardField = fn (string $field): bool => $cardFieldsDefaultVisible || !empty($boardCardFields[$field]);
$newFormKey = fn (): string => bin2hex(random_bytes(16));
$refreshText = (int) ($boardRefreshSeconds ?? 0) > 0
    ? 'Board refreshes every ' . (int) $boardRefreshSeconds . ' seconds.'
    : 'Board auto-refresh is off.';
ob_start();
?>
<div class="page-head">
  <div>
    <h1><?= h($roleContext['title'] ?? 'Control Board') ?></h1>
    <p class="muted"><?= h($workflow->name()) ?>. <?= h($roleContext['summary'] ?? ucfirst($user['role']) . ' view.') ?> <?= h($refreshText) ?></p>
    <p class="role-note"><?= h($roleContext['focus'] ?? '') ?></p>
  </div>
  <div class="grid-stats">
    <?php foreach ($stats as $label => $value): ?>
      <div class="stat"><strong><?= h($value) ?></strong><?= h(ucfirst($label)) ?></div>
    <?php endforeach; ?>
  </div>
</div>

<section class="panel board-filters">
  <?php $activeFilterValues = array_filter($filters ?? [], fn ($value): bool => $value !== '' && $value !== null); ?>
  <div class="saved-views" aria-label="Saved board views">
    <?php foreach ($boardViews as $view): ?>
      <?php
        $params = $view['params'];
        $active = $activeFilterValues === array_filter($params, fn ($value): bool => $value !== '' && $value !== null);
        $linkParams = array_merge(['view' => $view['key']], $params);
      ?>
      <a class="button <?= $active ? 'primary' : '' ?>" href="<?= h(url('board', $linkParams)) ?>"><?= h($view['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($usingSavedFilters): ?>
    <p class="muted preference-note">Using your saved board default.</p>
  <?php elseif (!empty($savedFilters)): ?>
    <p class="muted preference-note">Saved board default available when opening the board without filters.</p>
  <?php endif; ?>
  <form method="get" action="/">
    <input type="hidden" name="r" value="board">
    <div class="filter-row">
      <label for="q">Search</label>
      <input id="q" name="q" value="<?= h($filters['q'] ?? '') ?>" placeholder="Candidate, company, program">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Any status</option>
        <?php foreach ($filterOptions['statuses'] as $key => $status): ?>
          <option value="<?= h($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= h($workflow->statusLabel($key)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (($user['scope_type'] ?? '') !== 'company' && ($user['role'] ?? '') !== 'company'): ?>
        <label for="company">Company</label>
        <select id="company" name="company">
          <option value="">Any company</option>
          <?php foreach ($filterOptions['companies'] as $company): ?>
            <option value="<?= h($company['code']) ?>" <?= ($filters['company'] ?? '') === $company['code'] ? 'selected' : '' ?>><?= h($company['code']) ?> - <?= h($company['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <label for="flag">Flag</label>
      <select id="flag" name="flag">
        <?php foreach ($filterOptions['flags'] as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['flag'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="checkline">
        <input type="checkbox" name="actionable" value="1" <?= !empty($filters['actionable']) ? 'checked' : '' ?>>
        Actionable
      </label>
      <label class="checkline">
        <input type="checkbox" name="compact" value="1" <?= !empty($filters['compact']) ? 'checked' : '' ?>>
        Compact
      </label>
      <button type="submit">Filter</button>
      <a class="button" href="<?= h(url('board', ['view' => 'all'])) ?>">Reset</a>
    </div>
  </form>
  <form class="preference-actions" method="post" action="<?= h(url('board-preferences')) ?>">
    <?= Csrf::input() ?>
    <?php foreach (['q', 'company', 'status', 'flag', 'actionable', 'compact'] as $key): ?>
      <input type="hidden" name="<?= h($key) ?>" value="<?= h($filters[$key] ?? '') ?>">
    <?php endforeach; ?>
    <label for="stale_minutes">Stale after</label>
    <input id="stale_minutes" class="stale-minutes" type="number" name="stale_minutes" min="15" max="1440" step="5" value="<?= h($staleMinutes ?? 90) ?>">
    <span class="muted">minutes</span>
    <button type="submit" name="preference_action" value="save">Save as my default</button>
    <?php if (!empty($savedPreference)): ?>
      <button type="submit" name="preference_action" value="clear">Clear my default</button>
    <?php endif; ?>
  </form>
</section>

<section class="board <?= !empty($filters['compact']) ? 'board-compact' : '' ?>" aria-label="Placement status board">
  <?php foreach ($groups as $key => $group): ?>
    <div class="column">
      <h2 style="background: <?= h($workflow->statusColor($key)) ?>"><?= h($workflow->statusLabel($key)) ?> (<?= count($group['applications']) ?>)</h2>
      <?php if (!$group['applications']): ?>
        <div class="empty">No candidates</div>
      <?php endif; ?>
      <?php foreach ($group['applications'] as $app): ?>
        <?php $next = $workflow->nextStatus($app['current_status']); ?>
        <?php $workflowActions = $app['workflow_actions'] ?? []; ?>
        <?php $workflowCorrection = $app['workflow_correction'] ?? null; ?>
        <article class="card" style="border-left: 6px solid <?= h($workflow->statusColor($key)) ?>">
          <div class="card-title">
            <a href="<?= h(url('candidate', ['id' => $app['candidate_id']])) ?>"><?= h($app['candidate_name']) ?></a>
          </div>
          <?php
            $candidateMeta = [];
            if ($showCardField('candidate_id')) {
                $candidateMeta[] = h($app['external_id']);
            }
            if ($showCardField('program') && trim((string) ($app['program'] ?? '')) !== '') {
                $candidateMeta[] = h($app['program']);
            }
          ?>
          <?php if ($candidateMeta): ?>
            <div class="card-meta"><?= implode(' / ', $candidateMeta) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('tags') && trim((string) ($app['candidate_tags'] ?? '')) !== ''): ?>
            <div class="card-meta">Tags: <?= h($app['candidate_tags']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('company')): ?>
            <div class="card-meta"><?= h($app['company_code']) ?> - <?= h($app['company_name']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('tags') && trim((string) ($app['company_tags'] ?? '')) !== ''): ?>
            <div class="card-meta detail-optional">Company tags: <?= h($app['company_tags']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('process') && (!empty($app['process_type']) || !empty($app['room']))): ?>
            <div class="card-meta detail-optional">Process: <?= h($app['process_type'] ?: 'General') ?><?= !empty($app['room']) ? ' / Room: ' . h($app['room']) : '' ?></div>
          <?php endif; ?>
          <?php if ($showCardField('tracker') && !empty($app['tracker_name'])): ?>
            <div class="card-meta detail-optional">Tracker: <?= h($app['tracker_name']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('active_cap') && (int) ($app['max_active'] ?? 0) > 0): ?>
            <div class="card-meta detail-optional">Active cap: <?= h($app['max_active']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('rounds') && !empty($app['round_summary'])): ?>
            <div class="card-meta detail-optional">Rounds: <?= h($app['round_summary']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('schedule') && !empty($app['schedule_summary'])): ?>
            <div class="card-meta detail-optional">Schedule: <?= h($app['schedule_summary']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('slot') && !empty($app['slot_assignment_summary'])): ?>
            <div class="card-meta">Slot: <?= h($app['slot_assignment_summary']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('panel') && !empty($app['panel_summary'])): ?>
            <div class="card-meta">Panel: <?= h($app['panel_summary']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('route') && !empty($app['route_summary'])): ?>
            <div class="card-meta">Route: <?= h($app['route_summary']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('location')): ?>
            <div class="card-meta">Location: <?= h($app['current_location']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('accommodation') && !empty($app['accommodation_notes'])): ?>
            <div class="card-meta" style="color:#6b3b00;font-weight:700">Accommodation: <?= h($app['accommodation_notes']) ?></div>
          <?php endif; ?>
          <?php if ($showCardField('custom_fields')): ?>
            <?php if (($app['candidate_custom_fields_json'] ?? '{}') !== '{}'): ?>
              <div class="card-meta detail-optional">Candidate fields: <?= h($app['candidate_custom_fields_json']) ?></div>
            <?php endif; ?>
            <?php if (($app['company_custom_fields_json'] ?? '{}') !== '{}'): ?>
              <div class="card-meta detail-optional">Company fields: <?= h($app['company_custom_fields_json']) ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($showCardField('waitlist') && !empty($app['waitlist_rank'])): ?>
            <div class="card-meta">Waitlist #<?= h($app['waitlist_rank']) ?></div>
          <?php endif; ?>
          <?php if (!empty($app['opted_out'])): ?>
            <div class="card-meta" style="color:#9f1d1d;font-weight:700">Opted out</div>
          <?php endif; ?>
          <?php if ((int) ($app['open_wanted_count'] ?? 0) > 0): ?>
            <div class="card-meta" style="color:#9f1d1d;font-weight:700">Wanted alert open</div>
          <?php endif; ?>
          <?php if ((int) ($app['open_preference_count'] ?? 0) > 0): ?>
            <div class="card-meta" style="color:#7a4c00;font-weight:700">Preference request open</div>
          <?php endif; ?>
          <?php if (!empty($app['has_active_conflict'])): ?>
            <div class="card-meta" style="color:#7a4c00;font-weight:700">Active in <?= h($app['active_company_codes']) ?></div>
          <?php endif; ?>
          <?php if (!empty($app['is_stale'])): ?>
            <div class="card-meta" style="color:#7a4c00;font-weight:700">Stale active item</div>
          <?php endif; ?>
          <?php if ($workflowActions): ?>
            <?php foreach ($workflowActions as $action): ?>
              <form method="post" action="<?= h(url('move')) ?>" data-confirm="<?= h($action['label']) ?> for this candidate?">
                <?= Csrf::input() ?>
                <input type="hidden" name="idempotency_key" value="<?= h($newFormKey()) ?>">
                <input type="hidden" name="application_id" value="<?= h($app['id']) ?>">
                <input type="hidden" name="expected_status" value="<?= h($app['current_status']) ?>">
                <input type="hidden" name="transition_key" value="<?= h($action['key']) ?>">
                <input type="hidden" name="to_status" value="<?= h($action['to']) ?>">
                <button class="primary" type="submit"><?= h($action['label']) ?></button>
              </form>
            <?php endforeach; ?>
          <?php elseif ($next): ?>
            <div class="card-meta">Next: <?= h($workflow->statusLabel($next)) ?> / no permission</div>
          <?php else: ?>
            <div class="card-meta">Final status</div>
          <?php endif; ?>
          <?php if (Auth::hasCapability($user, 'placement.application.correct') && $workflowCorrection !== null): ?>
            <form method="post" action="<?= h(url('return-to-idle')) ?>" data-confirm="<?= h($workflowCorrection['label']) ?>?">
              <?= Csrf::input() ?>
              <input type="hidden" name="idempotency_key" value="<?= h($newFormKey()) ?>">
              <input type="hidden" name="application_id" value="<?= h($app['id']) ?>">
              <input type="hidden" name="expected_status" value="<?= h($app['current_status']) ?>">
              <input type="hidden" name="reason" value="operator_return">
              <button type="submit"><?= h($workflowCorrection['label']) ?></button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
