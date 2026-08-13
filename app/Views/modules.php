<?php

use App\Security\Csrf;

$title = 'Modules';
ob_start();
?>
<section class="panel">
  <h1>Modules</h1>
  <table class="table">
    <thead><tr><th>Module</th><th>Version</th><th>Requires</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($modules as $module): ?>
      <tr>
        <td><strong><?= h($module['name']) ?></strong><br><span class="muted"><?= h($module['description']) ?></span></td>
        <td><?= h($module['installed_version'] ?? $module['version']) ?></td>
        <td><?= h(implode(', ', $module['requires_modules'])) ?></td>
        <td><?= !$module['entitled'] ? 'Not included in hosted plan' : ($module['enabled'] ? 'Enabled' : ($module['installed'] ? 'Disabled; data retained' : 'Not installed')) ?></td>
        <td>
          <?php if ($module['entitled']): ?>
          <form method="post" action="<?= h(url('modules')) ?>" <?= $module['enabled'] ? 'data-confirm="Disable this module? Its data will be retained."' : '' ?>>
            <?= Csrf::input() ?>
            <input type="hidden" name="module_key" value="<?= h($module['key']) ?>">
            <input type="hidden" name="module_action" value="<?= $module['enabled'] ? 'disable' : 'enable' ?>">
            <button type="submit"><?= $module['enabled'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <?php else: ?>
            <span class="muted">Managed by hosted plan</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Lifecycle history</h2>
  <table class="table">
    <thead><tr><th>Time</th><th>Module</th><th>Event</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($events as $event): ?>
      <tr><td><?= h($event['created_at']) ?></td><td><?= h($event['module_key']) ?></td><td><?= h($event['event_type']) ?></td><td><?= h($event['detail']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
