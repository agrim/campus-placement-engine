<?php

use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;
use App\Modules\Placement\Application\PlacementService;

$title = $title ?? cpe_config('app.name');
$user = Auth::user();
$college = cpe_config('settings.college_name');
$siteName = cpe_site_name();
$siteTagline = cpe_site_tagline();
$metaRefreshSeconds = isset($boardRefreshSeconds) ? max(0, (int) $boardRefreshSeconds) : 0;
if (Database::isInstalled()) {
    try {
        $college = cpe_context()->institution()->name();
    } catch (Throwable) {
    }
}
$notificationCount = 0;
$navigation = [];
if ($user && Database::isInstalled()) {
    try {
        if (cpe_context()->modules()->isEnabled('placement')) {
            $notificationCount = (new PlacementService())->notificationCountForUser($user);
        }
        $navigation = cpe_context()->moduleManager()->navigation($user);
    } catch (Throwable) {
        $notificationCount = 0;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if ($metaRefreshSeconds > 0): ?>
    <meta http-equiv="refresh" content="<?= h($metaRefreshSeconds) ?>">
  <?php endif; ?>
  <title><?= h($title) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
  <header class="topbar">
    <div>
      <div class="brand"><?= h($college) ?></div>
      <div class="muted"><?= h($siteName) ?><?= $siteTagline !== '' ? ' / ' . h($siteTagline) : '' ?></div>
    </div>
    <?php if ($user): ?>
      <nav class="nav" aria-label="Primary">
        <?php if (Auth::hasCapability($user, 'portal.modules.manage')): ?>
          <a href="<?= h(url('modules')) ?>">Modules</a>
        <?php endif; ?>
        <?php foreach ($navigation as $item): ?>
          <?php $navLabel = $item['label'] . (($item['route'] ?? '') === 'notifications' && $notificationCount > 0 ? ' (' . $notificationCount . ')' : ''); ?>
          <a href="<?= h($item['href'] ?? url($item['route'])) ?>"><?= h($navLabel) ?></a>
        <?php endforeach; ?>
        <span><?= h($user['name']) ?> / <?= h($user['role']) ?></span>
        <form class="inline" method="post" action="<?= h(url('logout')) ?>">
          <?= \App\Security\Csrf::input() ?>
          <button type="submit">Sign out</button>
        </form>
      </nav>
    <?php endif; ?>
    <?php if (!$user && \App\Support\Database::isInstalled() && cpe_context()->modules()->isEnabled('placement')): ?>
      <nav class="nav" aria-label="Public">
        <a href="<?= h(url('public')) ?>"><?= h(cpe_public_placements_title()) ?></a>
        <a href="<?= h(url('login')) ?>">Sign in</a>
      </nav>
    <?php endif; ?>
  </header>
  <main class="shell">
    <?php foreach (Flash::pull() as $flash): ?>
      <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?? '' ?>
  </main>
  <script src="/assets/app.js"></script>
</body>
</html>
