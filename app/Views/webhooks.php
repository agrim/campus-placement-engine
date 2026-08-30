<?php

use App\Integrations\IntegrationState;
use App\Security\Csrf;

$title = 'Integrations';
$deadLettersBySubscription = [];
foreach ($deadLetters as $deadLetter) {
    $deadLettersBySubscription[(string) $deadLetter['subscription_public_id']][] = $deadLetter;
}
ob_start();
?>
<section class="panel">
  <h1>Integration setup</h1>
  <p>Connect an institution-owned system to governed application status changes, then validate and monitor it from one workflow.</p>
  <ol>
    <li>Review the permission and data boundary.</li>
    <li>Configure delivery and select events.</li>
    <li>Validate and activate the Integration.</li>
    <li>Monitor delivery health and resolve failures.</li>
    <li>Rotate credentials, disable access, or use the support reference.</li>
  </ol>
</section>

<?php if ($revealedSecret !== null): ?>
<section class="panel" aria-live="polite">
  <h2>Copy the new signing secret now</h2>
  <p>This is the only reveal for integration <code><?= h($revealedFor) ?></code>. Store it in the receiving system's secret manager.</p>
  <label for="revealed_secret">Signing secret</label>
  <input id="revealed_secret" value="<?= h($revealedSecret) ?>" readonly autocomplete="off" spellcheck="false">
</section>
<?php endif; ?>

<section class="panel">
  <h2>1. Review permission</h2>
  <p><strong>application.status_changed v1</strong> contains the public application identifier, aggregate version, previous status, new status, instance identifier, occurrence time, and correlation identifier.</p>
  <p class="muted">It excludes names, contact details, employer records, and the private domain event. Delivery is at least once: the receiving system must verify the raw-body signature and deduplicate by event ID.</p>
</section>

<section class="panel">
  <h2>2. Configure delivery and events</h2>
  <form method="post" action="<?= h(url('integration-create')) ?>">
    <?= Csrf::input() ?>
    <label for="integration_name">Name</label>
    <input id="integration_name" name="name" maxlength="120" required placeholder="Admissions data warehouse">
    <label for="endpoint_url">HTTPS endpoint</label>
    <input id="endpoint_url" name="endpoint_url" type="url" maxlength="2048" required placeholder="https://integrations.example.edu/cpe">
    <fieldset>
      <legend>Events and network permissions</legend>
      <label><input type="checkbox" name="application_status_changed" value="1" checked> Application status changed (version 1)</label>
      <label><input type="checkbox" name="allow_private_network" value="1"> Self-hosted private-network endpoint</label>
      <p class="muted">Private-network access is an explicit institution administrator policy and is unavailable in managed mode. HTTPS remains the default.</p>
    </fieldset>
    <button type="submit">Save Integration and create signing secret</button>
  </form>
</section>

<section class="panel">
  <h2>Integration delivery prerequisites</h2>
  <p>Encryption key: <?= $keyring['present'] ? 'Configured (' . h($keyring['active_version']) . ')' : 'Setup required' ?></p>
  <?php if (!$keyring['present']): ?><p class="muted"><?= h($keyring['issue']) ?></p><?php endif; ?>
  <?php if (!$health['encryption_key_references_ready']): ?><p class="muted">One or more stored secrets require an unavailable encryption-key version. Restore the external keyring before activation or delivery.</p><?php endif; ?>
  <p>Worker schedule: <?= $health['worker_configured'] ? 'Configured' : ($health['worker_required'] ? 'Setup required' : 'Not required until activation') ?>.</p>
  <?php if ($heartbeat): ?>
    <p>Worker: <?= h($heartbeat['status']) ?>; last finished <?= h($heartbeat['finished_at'] ?: 'not yet') ?>; claimed <?= (int) $heartbeat['claimed_count'] ?>, succeeded <?= (int) $heartbeat['succeeded_count'] ?>, needs review <?= (int) $heartbeat['failed_count'] ?>.</p>
  <?php else: ?>
    <p class="muted">No worker heartbeat is recorded. Schedule <code>php placement work-integrations</code>, then set <code>CPE_INTEGRATION_WORKER_CONFIGURED=1</code>.</p>
  <?php endif; ?>
  <p class="muted"><?= h($health['tls_policy_message']) ?></p>
</section>

<?php foreach ($subscriptions as $subscription): ?>
<section class="panel">
  <h2><?= h($subscription['name']) ?></h2>
  <p><strong><?= h(IntegrationState::label((string) $subscription['lifecycle_state'])) ?></strong> · <?= h($subscription['endpoint_display']) ?></p>
  <p class="muted">Support reference <?= h($subscription['endpoint_support_reference']) ?> · application.status_changed v1 · <?= (int) $subscription['allow_private_network'] === 1 ? 'private-network policy' : 'public-egress policy' ?></p>
  <h3>3. Validate and activate</h3>
  <div class="actions">
    <?php if (!$subscription['has_secret']): ?>
      <form class="inline" method="post" action="<?= h(url('integration-secret-generate')) ?>"><?= Csrf::input() ?><input type="hidden" name="subscription_id" value="<?= h($subscription['public_id']) ?>"><button type="submit">Generate signing secret</button></form>
    <?php else: ?>
      <?php if (in_array($subscription['lifecycle_state'], ['disabled', 'setup_required', 'validating'], true)): ?>
        <form class="inline" method="post" action="<?= h(url('integration-validate')) ?>"><?= Csrf::input() ?><input type="hidden" name="subscription_id" value="<?= h($subscription['public_id']) ?>"><button type="submit">Validate delivery</button></form>
      <?php endif; ?>
      <?php if ($subscription['lifecycle_state'] === 'validating' && $subscription['last_validated_at']): ?>
        <form class="inline" method="post" action="<?= h(url('integration-activate')) ?>"><?= Csrf::input() ?><input type="hidden" name="subscription_id" value="<?= h($subscription['public_id']) ?>"><button type="submit">Activate Integration</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <h3>4. Monitor health and deliveries</h3>
  <table class="table">
    <tbody>
      <tr><th>Last validated</th><td><?= h($subscription['last_validated_at'] ?: 'Not yet') ?></td></tr>
      <tr><th>Last success</th><td><?= h($subscription['last_success_at'] ?: 'Not yet') ?></td></tr>
      <tr><th>Last failure</th><td><?= h($subscription['last_failure_at'] ?: 'None') ?><?= $subscription['last_failure_reference'] ? ' · ' . h($subscription['last_failure_reference']) : '' ?></td></tr>
      <tr><th>Backlog</th><td><?= (int) $subscription['backlog_count'] ?> pending; <?= (int) $subscription['dead_letter_count'] ?> dead-lettered; oldest <?= h($subscription['oldest_pending_at'] ?: 'none') ?></td></tr>
    </tbody>
  </table>
  <?php foreach ($deadLettersBySubscription[(string) $subscription['public_id']] ?? [] as $deadLetter): ?>
    <?php if ($deadLetter['replayable']): ?>
      <form method="post" action="<?= h(url('integration-replay')) ?>">
        <?= Csrf::input() ?>
        <input type="hidden" name="delivery_id" value="<?= h($deadLetter['public_id']) ?>">
        <p>Delivery <code><?= h($deadLetter['public_id']) ?></code> · <?= h($deadLetter['last_error_code']) ?> · <?= h($deadLetter['last_failure_reference']) ?> <button type="submit">Replay exact delivery</button></p>
      </form>
    <?php else: ?>
      <p>Delivery <code><?= h($deadLetter['public_id']) ?></code> · Integration revoked · terminal, not replayable</p>
    <?php endif; ?>
  <?php endforeach; ?>

  <h3>5. Credentials and support</h3>
  <div class="actions">
    <?php if ($subscription['has_secret']): ?>
      <form class="inline" method="post" action="<?= h(url('integration-secret-rotate')) ?>"><?= Csrf::input() ?><input type="hidden" name="subscription_id" value="<?= h($subscription['public_id']) ?>"><button type="submit">Rotate secret</button></form>
    <?php endif; ?>
    <?php if ($subscription['lifecycle_state'] !== 'disabled'): ?>
      <form class="inline" method="post" action="<?= h(url('integration-disable')) ?>" data-confirm="Disable this integration? Retained backlog will pause."><?= Csrf::input() ?><input type="hidden" name="subscription_id" value="<?= h($subscription['public_id']) ?>"><button type="submit">Disable</button></form>
    <?php endif; ?>
    <?php if ($subscription['has_secret']): ?>
      <form class="inline" method="post" action="<?= h(url('integration-revoke')) ?>" data-confirm="Revoke all signing secrets and dead-letter unresolved deliveries?"><?= Csrf::input() ?><input type="hidden" name="subscription_id" value="<?= h($subscription['public_id']) ?>"><button type="submit">Revoke</button></form>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
