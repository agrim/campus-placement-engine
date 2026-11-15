<?php

use App\Security\Csrf;

$title = 'Sign in';
ob_start();
?>
<section class="panel" style="max-width:420px">
  <h1>Sign in</h1>
  <form data-field-validation method="post" action="<?= h(url('login')) ?>">
    <?= Csrf::input() ?>
    <label for="email">Email (required)</label>
    <input id="email" type="email" name="email" autocomplete="username" spellcheck="false" autocorrect="off" autocapitalize="none" required autofocus data-error="email-error">
    <p id="email-error" class="field-error" hidden>Error: Enter a valid email address.</p>
    <label for="password">Password (required)</label>
    <input id="password" type="password" name="password" autocomplete="current-password" required data-error="password-error">
    <p id="password-error" class="field-error" hidden>Error: Enter your password.</p>
    <p><button class="primary" type="submit">Sign in</button></p>
  </form>
  <?php if (!empty($ssoEnabled)): ?>
    <p><a class="button" href="<?= h(url('sso')) ?>">Institutional sign-in</a></p>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
