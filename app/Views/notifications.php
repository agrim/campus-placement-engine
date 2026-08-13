<?php

use App\Security\Csrf;
use App\Support\Auth;

$title = 'Notifications';
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Notifications</h1>
    <p class="muted">In-app operational notices for this role and scope.</p>
  </div>
  <div class="grid-stats">
    <div class="stat"><strong><?= h($openCount) ?></strong>Open</div>
  </div>
</div>

<section class="panel">
  <h2>Notification Center</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Status</th>
        <th>Recipient</th>
        <th>Notice</th>
        <th>Created</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($notifications as $notification): ?>
      <tr>
        <td><span class="badge <?= h($notification['status'] === 'open' ? 'warn' : 'ok') ?>"><?= h(strtoupper($notification['status'])) ?></span></td>
        <td>
          <?= h($notification['recipient_role'] ?: 'all') ?>
          <?php if (!empty($notification['recipient_scope_value'])): ?>
            / <?= h($notification['recipient_scope_value']) ?>
          <?php endif; ?>
        </td>
        <td>
          <strong><?= h($notification['subject']) ?></strong>
          <?php if (!empty($notification['body'])): ?>
            <div class="muted"><?= h($notification['body']) ?></div>
          <?php endif; ?>
          <?php if (!empty($notification['source_type'])): ?>
            <div class="card-meta"><?= h($notification['source_type']) ?> #<?= h($notification['source_id'] ?? '') ?></div>
          <?php endif; ?>
        </td>
        <td><?= h($notification['created_at']) ?></td>
        <td>
          <?php if ($notification['status'] === 'open' && Auth::hasCapability($user, 'placement.notifications.manage')): ?>
            <form method="post" action="<?= h(url('notification-acknowledge')) ?>">
              <?= Csrf::input() ?>
              <input type="hidden" name="notification_id" value="<?= h($notification['id']) ?>">
              <button type="submit">Acknowledge</button>
            </form>
          <?php elseif (!empty($notification['acknowledged_at'])): ?>
            Acknowledged <?= h($notification['acknowledged_at']) ?>
          <?php else: ?>
            -
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$notifications): ?><tr><td colspan="5">No notifications yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
