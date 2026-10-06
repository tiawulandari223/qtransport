<?php
if (!defined('APP_ROOT')) { http_response_code(404); exit; }

$u = auth_user();
$title = $title ?? 'Dashboard';
$cluster = $_GET['cluster'] ?? 'all';
if (!in_array($cluster, ['all', 'cold', 'ambient'], true)) $cluster = 'all';

$nav = [
    'dashboard' => 'Dashboard',
    'lane' => 'Lane Management',
    'tps' => 'TPS &amp; Supplier',
    'event' => 'Event &amp; Deviation Log',
    'risk' => 'Risk Assessment',
    'report' => 'Report Center',
];
if (can('admin')) $nav['admin'] = 'Manajemen Data';
if (can('audit')) $nav['audit'] = 'Audit Trail';

$clusterPages = ['dashboard', 'lane', 'risk', 'event'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> — Q-Transport</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app">
  <nav class="nav">
    <h1>Q-Transport</h1>
    <div class="sub">Quality oversight for transportation management</div>
    <?php foreach ($nav as $p => $label): ?>
      <a class="<?= $page === $p ? 'active' : '' ?>" href="<?= e(url($p, in_array($p, $clusterPages, true) ? ['cluster' => $cluster] : [])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <div class="navuser">
      <div class="nu-name"><?= e($u['name']) ?></div>
      <div class="nu-role"><?= e(ROLE_LABELS[$u['role']]) ?></div>
      <a class="nu-out" href="<?= e(url('logout')) ?>">Keluar</a>
    </div>
  </nav>
  <div class="main">
    <div class="topbar">
      <h2><?= e($title) ?></h2>
      <?php if (in_array($page, $clusterPages, true)): ?>
      <div class="clusterbar">
        <?php foreach (['all' => 'Semua', 'cold' => 'Cold Chain', 'ambient' => 'Ambient'] as $c => $label): ?>
          <a class="<?= $cluster === $c ? 'active' : '' ?>" href="<?= e(url($page, ['cluster' => $c])) ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php foreach (take_flashes() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </div>
</div>
<script>window.QT_RPN = {low: <?= (int)setting('rpn_low_max', '80') ?>, med: <?= (int)setting('rpn_med_max', '250') ?>, high: <?= (int)setting('rpn_high_max', '600') ?>};</script>
<script src="assets/app.js"></script>
</body>
</html>
