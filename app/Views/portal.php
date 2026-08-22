<?php

$title = 'Career Services Portal';
ob_start();
?>
<section class="panel">
  <h1>Career Services Portal</h1>
  <table class="table">
    <thead><tr><th>Section</th><th>Status</th><th>Version</th></tr></thead>
    <tbody>
    <?php foreach ($modules as $module): ?>
      <tr>
        <td><?= h($module['name']) ?></td>
        <td><?= $module['enabled'] ? 'Enabled' : ($module['installed'] ? 'Disabled' : 'Not installed') ?></td>
        <td><?= h($module['installed_version'] ?? $module['version']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (\App\Support\Auth::hasCapability($user, 'portal.modules.manage')): ?>
    <p><a class="button primary" href="<?= h(url('modules')) ?>">Manage modules</a></p>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
