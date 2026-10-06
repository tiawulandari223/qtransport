<?php
$title = 'Lane Management';
$cluster = req_cluster();
$action = (string)($_GET['a'] ?? '');

$fields = ['code', 'name', 'origin', 'destination', 'cluster', 'mode', 'distance_km', 'lead_time_hours',
    'frequency', 'carrier', 'supplier_id', 'temp_range', 'packaging_type', 'data_logger', 'max_break_minutes', 'season_notes'];

$read = function (array $src) use ($fields): array {
    $v = [];
    foreach ($fields as $f) $v[$f] = $src[$f] ?? '';
    $v['distance_km'] = $v['distance_km'] === '' ? null : (float)$v['distance_km'];
    $v['lead_time_hours'] = $v['lead_time_hours'] === '' ? null : (float)$v['lead_time_hours'];
    $v['max_break_minutes'] = $v['max_break_minutes'] === '' ? null : (float)$v['max_break_minutes'];
    $sid = $src['supplier_id'] ?? null;
    $v['supplier_id'] = ($sid === null || $sid === '') ? null : (int)$sid;
    $v['data_logger'] = !empty($v['data_logger']) ? 1 : 0;
    return $v;
};

if (is_post() && $action === 'save') {
    csrf_check();
    deny_unless(can('lane', 'write'), 'Role Anda tidak boleh mengubah data lane.');
    $id = (int)($_POST['id'] ?? 0);
    $v = $read($_POST);
    $errors = [];
    if ($v['code'] === '') $errors[] = 'Kode lane wajib diisi.';
    if ($v['name'] === '') $errors[] = 'Nama lane wajib diisi.';
    if (!in_array($v['cluster'], ['cold', 'ambient'], true)) $errors[] = 'Cluster wajib dipilih (Cold Chain/Ambient).';
    $dup = one('SELECT id FROM lanes WHERE code = ? AND id != ?', [$v['code'], $id]);
    if ($dup) $errors[] = 'Kode lane sudah dipakai lane lain.';
    if ($errors) {
        flash(implode(' ', $errors), 'err');
    } else {
        $cols = ['code', 'name', 'origin', 'destination', 'cluster', 'mode', 'distance_km', 'lead_time_hours',
            'frequency', 'carrier', 'supplier_id', 'temp_range', 'packaging_type', 'data_logger', 'max_break_minutes', 'season_notes'];
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $names = implode(', ', $cols);
        $vals = array_map(fn($c) => $v[$c], $cols);
        if ($id) {
            $old = one('SELECT * FROM lanes WHERE id = ?', [$id]);
            if (!$old) { flash('Lane tidak ditemukan.', 'err'); redirect('lane'); }
            q("UPDATE lanes SET $set, updated_at = datetime('now','localtime') WHERE id = ?", array_merge($vals, [$id]));
            $new = one('SELECT * FROM lanes WHERE id = ?', [$id]);
            $diff = changed_fields($old, $new, ['updated_at']);
            if ($diff) audit_log('lane', (string)$id, 'update', $old, $diff);
            flash('Lane "' . $v['name'] . '" diperbarui.');
        } else {
            q("INSERT INTO lanes($names) VALUES(" . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
            $newId = (int)db()->lastInsertId();
            audit_log('lane', (string)$newId, 'create', null, $v);
            flash('Lane "' . $v['name'] . '" ditambahkan.');
        }
        redirect('lane', ['cluster' => $v['cluster']]);
    }
}

if (is_post() && $action === 'status') {
    csrf_check();
    deny_unless(can('lane', 'write'), 'Role Anda tidak boleh mengubah data lane.');
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
    $old = one('SELECT * FROM lanes WHERE id = ?', [$id]);
    if ($old) {
        q('UPDATE lanes SET status = ?, updated_at = datetime(\'now\',\'localtime\') WHERE id = ?', [$status, $id]);
        audit_log('lane', (string)$id, $status === 'active' ? 'restore' : 'deactivate', ['status' => $old['status']], ['status' => $status]);
        flash('Status lane diperbarui menjadi ' . ($status === 'active' ? 'aktif' : 'nonaktif (soft-delete)') . '.');
    }
    redirect('lane', ['cluster' => $cluster]);
}

// ---------- detail ----------
$detailId = (int)($_GET['id'] ?? 0);
if ($detailId) {
    $l = one('SELECT l.*, s.name supplier_name FROM lanes l LEFT JOIN suppliers s ON s.id = l.supplier_id WHERE l.id = ?', [$detailId]);
    if (!$l) { flash('Lane tidak ditemukan.', 'err'); redirect('lane'); }
    $a = latest_assessment($detailId);
    $events = lane_events($detailId, 12);
    $trail = all('SELECT * FROM audit_log WHERE entity = ? AND entity_id = ? ORDER BY id DESC LIMIT 30', ['lane', (string)$detailId]);
    $devCount = count(array_filter($events, fn($e) => $e['type'] === 'deviation'));
    ?>
    <div class="bar-nav"><a href="<?= e(url('lane')) ?>">← Kembali ke daftar lane</a></div>
    <div class="panel">
      <h3><?= e($l['code']) ?> — <?= e($l['name']) ?> <span class="hint"><?= $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient' ?> · <?= $l['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></h3>
      <dl class="dl">
        <dt>Rute</dt><dd><?= e(($l['origin'] ?: '-') . ' → ' . ($l['destination'] ?: '-')) ?></dd>
        <dt>Moda transportasi</dt><dd><?= e($l['mode'] ?: '-') ?></dd>
        <dt>Jarak / Lead time</dt><dd><?= e(($l['distance_km'] !== null ? $l['distance_km'] . ' km' : '—')) ?> · <?= e(($l['lead_time_hours'] !== null ? $l['lead_time_hours'] . ' jam' : '—')) ?></dd>
        <dt>Frekuensi</dt><dd><?= e($l['frequency'] ?: '-') ?></dd>
        <dt>Carrier</dt><dd><?= e($l['carrier'] ?: '-') ?></dd>
        <dt>Supplier transportasi</dt><dd><?= e($l['supplier_name'] ?: '-') ?></dd>
        <dt>Rentang suhu</dt><dd><?= e($l['temp_range'] ?: '-') ?></dd>
        <dt>Kemasan / TPS</dt><dd><?= e($l['packaging_type'] ?: '-') ?></dd>
        <dt>Data logger</dt><dd><?= $l['data_logger'] ? 'Tersedia' : 'Tidak tersedia' ?></dd>
        <dt>Max cold chain break</dt><dd><?= e($l['max_break_minutes'] !== null ? $l['max_break_minutes'] . ' menit' : '—') ?></dd>
        <dt>Faktor musiman/geografis</dt><dd><?= e($l['season_notes'] ?: '-') ?></dd>
        <dt>RPN tertinggi</dt><dd><?= $a ? '<b>' . (int)$a['max_rpn'] . '</b> ' . badge($a['category']) : 'Belum dinilai' ?></dd>
        <dt>Deviasi 12 bulan</dt><dd><?= $devCount ?> kejadian</dd>
      </dl>
      <?php if (can('lane', 'write')): ?>
      <div class="rowbtns" style="margin-top:16px">
        <a class="btn" href="<?= e(url('lane', ['edit' => (int)$l['id']])) ?>">Edit lane</a>
        <a class="btn ghost" href="<?= e(url('risk', ['lane' => (int)$l['id']])) ?>">Buka risk assessment</a>
      </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Penilaian risiko terakhir</h3>
      <?php if (!$a): ?><div class="empty">Belum ada penilaian.</div><?php else: ?>
      <p class="muted">Versi <?= (int)$a['version'] ?> · <?= e(fmt_date($a['assessed_at'])) ?>
        · TPS/kemasan: <?= e($a['tps_label']) ?></p>
      <div class="tablewrap"><table>
        <tr><th>Risiko</th><th class="right">S</th><th class="right">O</th><th class="right">D</th>
          <th class="right">RPN</th><th>Kategori</th><th>Respons</th></tr>
        <?php foreach ($a['scores'] as $s): ?>
        <tr><td><?= e($s['risk_name']) ?></td><td class="right"><?= (int)$s['s'] ?></td><td class="right"><?= (int)$s['o'] ?></td>
          <td class="right"><?= (int)$s['d'] ?></td><td class="right"><b><?= (int)$s['rpn'] ?></b></td>
          <td><?= badge($s['category']) ?></td><td><?= e($s['response']) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Event &amp; deviasi (12 bulan)</h3>
      <?php if (!$events): ?><div class="empty">Belum ada event.</div><?php else: ?>
      <div class="tablewrap"><table>
        <tr><th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Keparahan</th><th>TPS</th><th>Status</th><th>PIC</th></tr>
        <?php foreach ($events as $ev): ?>
        <tr><td class="nowrap"><?= e(fmt_date($ev['event_date'])) ?></td>
          <td><span class="tag"><?= $ev['type'] === 'deviation' ? 'Deviation' : 'Normal' ?></span></td>
          <td><?= e($ev['risk_name'] ?: '-') ?></td><td><?= sev_badge($ev['severity']) ?></td>
          <td><?= e($ev['tps_id'] ? (one('SELECT name FROM tps_units WHERE id=?', [$ev['tps_id']])['name'] ?? '-') : '-') ?></td>
          <td><?= $ev['type'] === 'deviation' ? capa_badge((string)$ev['status']) : '-' ?></td>
          <td><?= e($ev['pic'] ?: '-') ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Audit trail lane ini</h3>
      <?php if (!$trail): ?><div class="empty">Belum ada perubahan tercatat.</div><?php else: ?>
      <div class="tablewrap"><table>
        <tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Perubahan</th></tr>
        <?php foreach ($trail as $t): ?>
        <tr><td class="nowrap"><?= e($t['created_at']) ?></td><td><?= e($t['user_name']) ?></td>
          <td><span class="tag"><?= e($t['action']) ?></span></td>
          <td><?php
            $d = $t['after_json'] ? json_decode($t['after_json'], true) : null;
            if (is_array($d)) {
                foreach ($d as $k => $ch) {
                    if (is_array($ch) && array_key_exists('before', $ch)) {
                        echo '<b>' . e($k) . '</b>: ' . e((string)$ch['before']) . ' → ' . e((string)$ch['after']) . '<br>';
                    }
                }
            } else { echo '<span class="muted">—</span>'; }
          ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>
    <?php
    return;
}

// ---------- form ----------
$editId = (int)($_GET['edit'] ?? 0);
$e2 = $editId ? one('SELECT * FROM lanes WHERE id = ?', [$editId]) : null;
if ($editId && !$e2) { flash('Lane tidak ditemukan.', 'err'); redirect('lane'); }
$suppliers = all('SELECT id, name FROM suppliers ORDER BY name');
$canWrite = can('lane', 'write');
if ($editId) $l = $e2;
?>
<?php if ($canWrite): ?>
<div class="panel">
  <h3><?= $editId ? 'Edit lane ' . e($e2['code']) : 'Tambah lane baru' ?></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="save">
    <input type="hidden" name="id" value="<?= (int)($e2['id'] ?? 0) ?>">
    <div><label>Kode lane *</label><input name="code" required value="<?= e($e2['code'] ?? '') ?>" placeholder="L007"></div>
    <div><label>Nama lane (origin - destination) *</label><input name="name" required value="<?= e($e2['name'] ?? '') ?>" placeholder="Jakarta DC - Semarang Hospital"></div>
    <div><label>Cluster *</label>
      <select name="cluster" id="lane_cluster" required><?= sel_options(['cold' => 'Cold Chain', 'ambient' => 'Ambient'], $e2['cluster'] ?? 'cold') ?></select></div>
    <div><label>Origin</label><input name="origin" value="<?= e($e2['origin'] ?? '') ?>"></div>
    <div><label>Destination</label><input name="destination" value="<?= e($e2['destination'] ?? '') ?>"></div>
    <div><label>Moda transportasi</label><input name="mode" value="<?= e($e2['mode'] ?? '') ?>" placeholder="Darat / Laut / Udara"></div>
    <div><label>Jarak (km)</label><input type="number" step="0.1" name="distance_km" value="<?= e((string)($e2['distance_km'] ?? '')) ?>"></div>
    <div><label>Lead time (jam)</label><input type="number" step="0.1" name="lead_time_hours" value="<?= e((string)($e2['lead_time_hours'] ?? '')) ?>"></div>
    <div><label>Frekuensi pengiriman</label><input name="frequency" value="<?= e($e2['frequency'] ?? '') ?>" placeholder="Harian / 2x minggu"></div>
    <div><label>Carrier</label><input name="carrier" value="<?= e($e2['carrier'] ?? '') ?>"></div>
    <div><label>Supplier transportasi</label>
      <select name="supplier_id"><option value="">— pilih —</option>
        <?php foreach ($suppliers as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (int)($e2['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div data-cluster="cold" style="display:none"><label>Cold chain — rentang suhu wajib</label><input name="temp_range" value="<?= e($e2['temp_range'] ?? '') ?>" placeholder="2-8°C"></div>
    <div data-cluster="cold" style="display:none"><label>Max cold chain break (menit)</label><input type="number" step="1" name="max_break_minutes" value="<?= e((string)($e2['max_break_minutes'] ?? '')) ?>"></div>
    <div data-cluster="ambient" style="display:none"><label>Ambient — jenis packaging</label><input name="packaging_type" value="<?= e($e2['packaging_type'] ?? '') ?>" placeholder="Dus standar + pallet"></div>
    <div data-cluster="cold" style="display:none"><label>Data logger</label>
      <select name="data_logger"><option value="0" <?= empty($e2['data_logger']) ? 'selected' : '' ?>>Tidak tersedia</option>
        <option value="1" <?= !empty($e2['data_logger']) ? 'selected' : '' ?>>Tersedia</option></select></div>
    <div style="grid-column:1/-1"><label>Faktor musiman/geografis</label><input name="season_notes" value="<?= e($e2['season_notes'] ?? '') ?>" placeholder="Rawan banjir, rawan macet, dsb."></div>
    <div class="rowbtns">
      <button class="btn" type="submit"><?= $editId ? 'Simpan perubahan' : 'Simpan lane' ?></button>
      <a class="btn ghost" href="<?= e(url('lane')) ?>">Batal</a>
      <span class="muted">Field bertanda * wajib diisi (validasi juga dijalankan di server).</span>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Daftar lane</h3>
  <form class="filters" method="get">
    <input type="hidden" name="p" value="lane">
    <div class="f"><label>Cari</label><input name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="kode / nama / origin"></div>
    <div class="f"><label>Cluster</label><select name="cluster"><?= sel_options(['all' => 'Semua', 'cold' => 'Cold Chain', 'ambient' => 'Ambient'], $cluster) ?></select></div>
    <div class="f"><label>Status</label><select name="status"><?= sel_options(['all' => 'Semua', 'active' => 'Aktif', 'inactive' => 'Nonaktif'], (string)($_GET['status'] ?? 'all')) ?></select></div>
    <div class="f"><label>Level risiko</label><select name="cat"><?= sel_options(['all' => 'Semua', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'], (string)($_GET['cat'] ?? 'all')) ?></select></div>
    <div class="f"><label>&nbsp;</label><button class="btn" type="submit">Filter</button></div>
  </form>

  <?php
    $qw = (string)($_GET['q'] ?? '');
    $statusF = (string)($_GET['status'] ?? 'all');
    $catF = (string)($_GET['cat'] ?? 'all');
    $lanes = lane_risk_overview($cluster);
    if ($statusF !== 'all') $lanes = array_values(array_filter($lanes, fn($l) => $l['status'] === $statusF));
    if ($catF !== 'all') $lanes = array_values(array_filter($lanes, fn($l) => $l['category'] === $catF));
    if ($qw !== '') {
        $q2 = strtolower($qw);
        $lanes = array_values(array_filter($lanes, fn($l) =>
            stripos($l['code'], $q2) !== false || stripos($l['name'], $q2) !== false
            || stripos((string)$l['origin'], $q2) !== false || stripos((string)$l['destination'], $q2) !== false));
    }
  ?>
  <div class="tablewrap">
  <table>
    <tr><th>Kode</th><th>Nama lane</th><th>Cluster</th><th>Moda</th><th class="right">Lead time</th>
      <th class="right">RPN</th><th>Kategori</th><th>Status</th><?php if ($canWrite): ?><th></th><?php endif; ?></tr>
    <?php if (!$lanes): ?><tr><td colspan="9" class="empty">Tidak ada lane sesuai filter.</td></tr><?php endif; ?>
    <?php foreach ($lanes as $l): ?>
    <tr>
      <td class="nowrap"><a href="<?= e(url('lane', ['id' => (int)$l['id']])) ?>"><?= e($l['code']) ?></a></td>
      <td><a href="<?= e(url('lane', ['id' => (int)$l['id']])) ?>"><?= e($l['name']) ?></a></td>
      <td><span class="tag"><?= $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient' ?></span></td>
      <td><?= e($l['mode'] ?: '-') ?></td>
      <td class="right nowrap"><?= e($l['lead_time_hours'] !== null ? $l['lead_time_hours'] . ' j' : '-') ?></td>
      <td class="right"><?= $l['max_rpn'] !== null ? (int)$l['max_rpn'] : '-' ?></td>
      <td><?= badge($l['category']) ?></td>
      <td><?= $l['status'] === 'active' ? '<span class="badge b-low">Aktif</span>' : '<span class="badge b-neutral">Nonaktif</span>' ?></td>
      <?php if ($canWrite): ?>
      <td class="nowrap">
        <a class="btn sm ghost" href="<?= e(url('lane', ['edit' => (int)$l['id']])) ?>">Edit</a>
        <form method="post" style="display:inline"<?= confirm_form($l['status'] === 'active'
            ? 'Nonaktifkan lane ini? Data historis tetap tersimpan (soft-delete).' : 'Aktifkan kembali lane ini?') ?>>
          <?= csrf_field() ?><input type="hidden" name="a" value="status">
          <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <input type="hidden" name="status" value="<?= $l['status'] === 'active' ? 'inactive' : 'active' ?>">
          <button class="btn sm ghost" type="submit"><?= $l['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
