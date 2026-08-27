<?php

use App\Security\Csrf;
use App\Security\SetupAuthorization;

$title = 'Authorize setup';
$localSetup = ($setupMode ?? null) === SetupAuthorization::MODE_LOCAL;
ob_start();
?>
<div class="setup-layout">
  <section class="panel">
    <h1>Authorize first-run setup</h1>
    <?php if ($localSetup): ?>
      <p class="muted">Enter the one-time setup code shown in the trusted terminal that started this setup server. It expires after 20 minutes and is never placed in a URL.</p>
    <?php else: ?>
      <p class="muted">Enter the one-time setup token provided through your server environment. It is exchanged for a short-lived browser grant and is never placed in a URL.</p>
    <?php endif; ?>
    <form method="post" action="/install.php" autocomplete="off">
      <input type="hidden" name="_setup_action" value="unlock">
      <?= Csrf::input() ?>
      <label for="setup_token"><?= $localSetup ? 'Setup code' : 'Setup token' ?></label>
      <input id="setup_token" name="setup_token" type="password" autocomplete="off" required>
      <p class="setup-submit"><button class="primary" type="submit">Authorize setup</button></p>
    </form>
  </section>
</div>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
