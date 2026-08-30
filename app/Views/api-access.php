<?php

use App\Security\Csrf;

$title = 'API Access';
ob_start();
?>
<section class="panel">
  <h1>API identity controls</h1>
  <p>Create institution-local service accounts and short-lived access tokens for API v1.</p>
  <p class="muted">API v1 can read opportunities and applications and apply one controlled application status transition. Candidate data and all other changes remain unavailable. Cloud does not proxy this traffic.</p>
</section>

<?php if ($revealedToken !== null): ?>
<section class="panel" aria-live="polite">
  <h2>Copy this token now</h2>
  <p>This is the only reveal for <code><?= h($revealedFor) ?></code>. It expires at <?= h($revealedExpiry) ?> UTC. Store it only in the calling system's secret manager.</p>
  <label for="revealed_api_token">Access token</label>
  <input id="revealed_api_token" value="<?= h($revealedToken) ?>" readonly autocomplete="off" spellcheck="false">
</section>
<?php endif; ?>

<section class="panel">
  <h2>Local API switch</h2>
  <p><strong><?= $health['enabled'] ? 'Enabled' : 'Disabled' ?></strong> · <?= h($health['message']) ?></p>
  <p>External keyring: <?= $keyring['present'] ? 'configured' : 'setup required' ?> · usable tokens: <?= (int) $health['usable_tokens'] ?> · missing key versions: <?= (int) $health['missing_key_versions'] ?></p>
  <?php if (!$keyring['present']): ?><p class="muted"><?= h($keyring['issue']) ?></p><?php endif; ?>
  <?php if ($health['enabled']): ?>
    <form method="post" action="<?= h(url('api-disable')) ?>" data-confirm="Disable all institution-local API token authentication immediately?">
      <?= Csrf::input() ?>
      <button type="submit">Disable API</button>
    </form>
  <?php else: ?>
    <form method="post" action="<?= h(url('api-enable')) ?>">
      <?= Csrf::input() ?>
      <button type="submit">Enable API access</button>
    </form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Create a service account</h2>
  <form method="post" action="<?= h(url('api-service-account-create')) ?>">
    <?= Csrf::input() ?>
    <label for="api_account_name">Name</label>
    <input id="api_account_name" name="name" maxlength="120" required placeholder="Institution data warehouse">
    <fieldset>
      <legend>Exact scopes</legend>
      <?php foreach ($supportedScopes as $scope): ?>
        <label><input type="checkbox" name="scopes[]" value="<?= h($scope) ?>"> <?= h($scope) ?></label>
      <?php endforeach; ?>
      <p class="muted">Scopes are exact grants. They do not inherit the creating user's role or administrator wildcard.</p>
    </fieldset>
    <label for="api_expiry_days">Token lifetime in days</label>
    <input id="api_expiry_days" name="expiry_days" type="number" min="1" max="365" value="90" required>
    <button type="submit">Create and reveal token once</button>
  </form>
</section>

<?php if ($accounts === []): ?>
<section class="panel"><p class="muted">No API service account is configured.</p></section>
<?php endif; ?>

<?php foreach ($accounts as $account): ?>
<section class="panel">
  <h2><?= h($account['name']) ?></h2>
  <p><strong><?= h($account['status']) ?></strong> · <code><?= h($account['public_id']) ?></code></p>
  <p>Scopes: <?= h(implode(', ', $account['scopes'])) ?></p>
  <div class="actions">
    <?php if ($account['status'] === 'enabled'): ?>
      <form class="inline" method="post" action="<?= h(url('api-service-account-disable')) ?>"><?= Csrf::input() ?><input type="hidden" name="service_account_id" value="<?= h($account['public_id']) ?>"><button type="submit">Disable</button></form>
    <?php elseif ($account['status'] === 'disabled'): ?>
      <form class="inline" method="post" action="<?= h(url('api-service-account-enable')) ?>"><?= Csrf::input() ?><input type="hidden" name="service_account_id" value="<?= h($account['public_id']) ?>"><button type="submit">Enable</button></form>
    <?php endif; ?>
    <?php if ($account['status'] !== 'revoked'): ?>
      <form class="inline" method="post" action="<?= h(url('api-token-rotate')) ?>">
        <?= Csrf::input() ?>
        <input type="hidden" name="service_account_id" value="<?= h($account['public_id']) ?>">
        <input type="hidden" name="expiry_days" value="90">
        <button type="submit">Rotate token</button>
      </form>
      <form class="inline" method="post" action="<?= h(url('api-service-account-revoke')) ?>" data-confirm="Permanently revoke this service account and every token?">
        <?= Csrf::input() ?>
        <input type="hidden" name="service_account_id" value="<?= h($account['public_id']) ?>">
        <button type="submit">Revoke account</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($account['tokens'] !== []): ?>
    <table class="table">
      <thead><tr><th>Token lookup ID</th><th>Expires</th><th>Grace ends</th><th>Last used</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($account['tokens'] as $token): ?>
        <?php $tokenStatus = $token['revoked_at'] ? 'revoked' : (((string) $token['expires_at'] <= cpe_now()) ? 'expired' : ($token['rotation_grace_expires_at'] ? 'rotation grace' : 'current')); ?>
        <tr>
          <td><code><?= h($token['lookup_id']) ?></code></td>
          <td><?= h($token['expires_at']) ?></td>
          <td><?= h($token['rotation_grace_expires_at'] ?: '—') ?></td>
          <td><?= h($token['last_used_at'] ?: 'Never') ?></td>
          <td><?= h($tokenStatus) ?></td>
          <td>
            <?php if (!$token['revoked_at']): ?>
              <form class="inline" method="post" action="<?= h(url('api-token-revoke')) ?>" data-confirm="Revoke this token immediately?">
                <?= Csrf::input() ?>
                <input type="hidden" name="token_lookup_id" value="<?= h($token['lookup_id']) ?>">
                <button type="submit">Revoke</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
