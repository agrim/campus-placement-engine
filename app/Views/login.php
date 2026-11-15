<?php

use App\Security\Csrf;
use App\Support\Flash;

$title = 'Sign in';
// Describe the failed attempt, not a guessed incorrect credential. Consume once.
$loginFlashes = Flash::pull();
$loginErrors = array_values(array_filter($loginFlashes, static fn (array $flash): bool => $flash['type'] === 'error'));
$layoutFlashes = array_values(array_filter($loginFlashes, static fn (array $flash): bool => $flash['type'] !== 'error'));
$loginErrorDescription = $loginErrors ? ' aria-describedby="login-server-errors"' : '';
ob_start();
?>
<section class="panel" style="max-width:420px">
  <h1>Sign in</h1>
  <form data-field-validation<?= $loginErrorDescription ?> method="post" action="<?= h(url('login')) ?>">
    <?php if ($loginErrors): ?>
      <div id="login-server-errors">
        <?php foreach ($loginErrors as $flash): ?>
          <p class="flash error"><?= h($flash['message']) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?= Csrf::input() ?>
    <label for="email">Email (required)</label>
    <input id="email" type="email" name="email" autocomplete="username" spellcheck="false" autocorrect="off" autocapitalize="none" required autofocus<?= $loginErrorDescription ?> data-error="email-error">
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
