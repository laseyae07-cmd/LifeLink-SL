<?php
/** @var string $pageTitle */
$authUser = Auth::user();
$flashes = pull_flashes();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(($pageTitle ?? 'LifeLink SL') . ' | LifeLink SL') ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/styles.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="container nav-wrap">
    <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="LifeLink SL home"><img class="brand-logo" src="<?=e(url('assets/images/lifelink-logo.svg'))?>" alt=""><span>LifeLink <strong>SL</strong><small>Donor Coordination</small></span></a>
    <button class="nav-toggle" type="button" aria-label="Toggle navigation">☰</button>
    <nav class="main-nav">
      <a href="<?= e(url('index.php')) ?>">Home</a>
      <a href="<?= e(url('platform.php')) ?>">Platform</a>
      <a href="<?= e(url('workflow.php')) ?>">Workflow</a>
      <a href="<?= e(url('security.php')) ?>">Security</a>
      <a href="<?= e(url('about.php')) ?>">About</a>
      <?php if (!$authUser): ?>
        <a href="<?= e(url('login.php')) ?>">Sign in</a>
        <a class="btn btn-sm" href="<?= e(url('register.php')) ?>">Become a Donor</a>
      <?php else: ?>
        <a href="<?= e(url(Auth::dashboardPath($authUser))) ?>">Workspace</a>
        <a href="<?= e(url('logout.php')) ?>">Sign out</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main>
<?php if ($flashes): ?><div class="container flash-stack"><?php foreach ($flashes as $f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?></div><?php endif; ?>
