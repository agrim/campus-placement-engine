<?php

use App\Security\Csrf;

$title = 'Sign in';
ob_start();
?>
<section class="panel" style="max-width:420px">
  <h1>Sign in</h1>
  <form method="post" action="<?= h(url('login')) ?>">
    <?= Csrf::input() ?>
    <label for="email">Email</label>
    <input id="email" type="email" name="email" autocomplete="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>
    <p><button class="primary" type="submit">Sign in</button></p>
  </form>
  <?php if (!empty($ssoEnabled)): ?>
    <p><a class="button" href="<?= h(url('sso')) ?>">Institutional sign-in</a></p>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
