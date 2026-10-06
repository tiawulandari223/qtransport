<?php
$title = 'Audit Trail';
deny_unless(can('audit'), 'Role Anda tidak memiliki akses ke audit trail.');

$entity = (string)($_GET['entity'] ?? 'all');
$userF = (string)($_GET['user'] ?? 'all');
$actionF = (string)($_GET['action'] ?? 'all');
[$from, $to] = date_filter();

$w = [];
$p = [];
if ($entity !== 'all') { $w[] = 'entity = ?'; $p[] = $entity; }
if ($userF !== 'all') { $w[] = 'user_name = ?'; $p[] = $userF; }
if ($actionF !== 'all') { $w[] = 'action = ?'; $p[] = $actionF; }
if ($from) { $w[] = 'created_at >= ?'; $p[] = $from . ' 00:00:00'; }
if ($to) { $w[] = 'created_at <= ?'; $p[] = $to . ' 23:59:59'; }

$sql = 'SELECT * FROM audit_log' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT 300';
$rows = all($sql, $p);

$entities = array_map(fn($r) => $r['entity'], all('SELECT DISTINCT entity FROM audit_log ORDER BY entity'));
$users = array_map(fn($r) => $r['user_name'], all('SELECT DISTINCT user_name FROM audit_log ORDER BY user_name'));
$actions = array_map(fn($r) => $r['action'], all('SELECT DISTINCT action FROM audit_log ORDER BY action'));
$total = (int)scalar('SELECT COUNT(*) FROM audit_log');
?>
<div class="panel">
  <h3>Filter <span class="hint">— tabel hanya-append, tidak bisa diubah/dihapus lewat aplikasi</span></h3>
  <form class="filters" method="get">
    <input type="hidden" name="p" value="audit">
    <div class="f"><label>Entitas</label>
      <select name="entity"><?= sel_options(array_merge(['all' => 'Semua'], array_combine($entities, $entities)), $entity) ?></select></div>
    <div class="f"><label>User</label>
      <select name="user"><?= sel_options(array_merge(['all' => 'Semua'], array_combine($users, $users)), $userF) ?></select></div>
    <div class="f"><label>Aksi</label>
      <select name="action"><?= sel_options(array_merge(['all' => 'Semua'], array_combine($actions, $actions)), $actionF) ?></select></div>
    <div class="f"><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="f"><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="f"><label>&nbsp;</label><button class="btn" type="submit">Filter</button></div>
  </form>
</div>

<div class="panel">
  <h3>Log aktivitas <span class="hint">— menampilkan <?= count($rows) ?> dari <?= $total ?> entri</span></h3>
  <div class="tablewrap">
  <table>
    <tr><th>Waktu</th><th>User</th><th>Entitas</th><th>ID</th><th>Aksi</th><th>Perubahan (sebelum → sesudah)</th></tr>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada log sesuai filter.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap"><?= e($r['created_at']) ?></td>
      <td><?= e($r['user_name']) ?></td>
      <td><span class="tag"><?= e($r['entity']) ?></span></td>
      <td class="nowrap"><?= e($r['entity_id'] ?: '-') ?></td>
      <td><?= e($r['action']) ?></td>
      <td><?php
        $d = $r['after_json'] ? json_decode($r['after_json'], true) : null;
        if (!is_array($d)) { echo '<span class="muted">—</span>'; }
        else {
            foreach ($d as $k => $ch) {
                if (is_array($ch) && array_key_exists('before', $ch)) {
                    echo '<b>' . e($k) . '</b>: ' . e((string)$ch['before']) . ' → ' . e((string)$ch['after']) . '<br>';
                } elseif (is_array($ch)) {
                    echo '<b>' . e($k) . '</b>: ' . e(json_encode($ch, JSON_UNESCAPED_UNICODE)) . '<br>';
                } else {
                    echo '<b>' . e($k) . '</b>: ' . e((string)$ch) . '<br>';
                }
            }
        }
      ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
