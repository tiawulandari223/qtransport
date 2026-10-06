<?php
$title = 'TPS & Supplier Performance';
$action = (string)($_GET['a'] ?? '');
$canWrite = can('tps', 'write');
$canApprove = can('tps', 'approve');

if (is_post() && $action === 'save') {
    csrf_check();
    deny_unless($canWrite, 'Role Anda tidak boleh mengubah data master TPS.');
    $id = (int)($_POST['id'] ?? 0);
    $cats = array_values(array_intersect((array)($_POST['product_cats'] ?? []), ['A1', 'A2', 'B', 'C']));
    $v = [
        'code' => (string)post('code'), 'name' => (string)post('name'),
        'type' => in_array(post('type'), ['active', 'passive', 'packaging'], true) ? post('type') : 'active',
        'hold_time' => (string)post('hold_time'), 'temp_range' => (string)post('temp_range'),
        'product_cats' => implode(',', $cats),
        'supplier_id' => post_int('supplier_id'),
        'qual_date' => (string)post('qual_date'), 'qual_doc' => (string)post('qual_doc'),
        'requal_due' => (string)post('requal_due'),
    ];
    $errors = [];
    if ($v['code'] === '') $errors[] = 'Kode unit wajib diisi.';
    if ($v['name'] === '') $errors[] = 'Nama unit wajib diisi.';
    if (one('SELECT id FROM tps_units WHERE code = ? AND id != ?', [$v['code'], $id])) $errors[] = 'Kode unit sudah dipakai.';
    foreach (['qual_date', 'requal_due'] as $d) {
        if ($v[$d] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v[$d])) $errors[] = 'Format tanggal tidak valid.';
    }
    if ($v['qual_date'] !== '' && $v['requal_due'] !== '' && $v['requal_due'] < $v['qual_date']) {
        $errors[] = 'Tanggal re-kualifikasi tidak boleh sebelum tanggal kualifikasi.';
    }
    if ($errors) {
        flash(implode(' ', $errors), 'err');
    } else {
        $cols = ['code', 'name', 'type', 'hold_time', 'temp_range', 'product_cats', 'supplier_id', 'qual_date', 'qual_doc', 'requal_due'];
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $names = implode(', ', $cols);
        $vals = array_map(fn($c) => $v[$c], $cols);
        if ($id) {
            $old = one('SELECT * FROM tps_units WHERE id = ?', [$id]);
            if (!$old) { flash('Unit TPS tidak ditemukan.', 'err'); redirect('tps'); }
            q("UPDATE tps_units SET $set, updated_at = datetime('now','localtime') WHERE id = ?", array_merge($vals, [$id]));
            $new = one('SELECT * FROM tps_units WHERE id = ?', [$id]);
            $diff = changed_fields($old, $new, ['updated_at']);
            if ($diff) audit_log('tps', (string)$id, 'update', $old, $diff);
            flash('Unit TPS "' . $v['name'] . '" diperbarui.');
        } else {
            q("INSERT INTO tps_units($names) VALUES(" . implode(',', array_fill(0, count($cols), '?')) . ')', $vals);
            $nid = (int)db()->lastInsertId();
            audit_log('tps', (string)$nid, 'create', null, $v);
            flash('Unit TPS "' . $v['name'] . '" ditambahkan.');
        }
        redirect('tps');
    }
}

if (is_post() && $action === 'status') {
    csrf_check();
    deny_unless($canWrite, 'Role Anda tidak boleh mengubah data master TPS.');
    $id = (int)($_POST['id'] ?? 0);
    $status = post('status') === 'inactive' ? 'inactive' : 'active';
    $old = one('SELECT * FROM tps_units WHERE id = ?', [$id]);
    if ($old) {
        q('UPDATE tps_units SET active = ?, updated_at = datetime(\'now\',\'localtime\') WHERE id = ?', [$status === 'active' ? 1 : 0, $id]);
        audit_log('tps', (string)$id, $status === 'active' ? 'restore' : 'deactivate', ['active' => $old['active']], ['active' => $status === 'active' ? 1 : 0]);
        flash('Status unit TPS diperbarui.');
    }
    redirect('tps');
}

if (is_post() && $action === 'approve') {
    csrf_check();
    deny_unless($canApprove, 'Hanya role Quality/QRM yang boleh memberi persetujuan unit TPS.');
    $id = (int)post('id');
    $old = one('SELECT * FROM tps_units WHERE id = ?', [$id]);
    if (!$old) { flash('Unit TPS tidak ditemukan.', 'err'); redirect('tps'); }
    if ($old['approval_status'] === 'approved') {
        flash('Unit TPS sudah disetujui sebelumnya.');
    } else {
        q("UPDATE tps_units SET approval_status = 'approved', approved_by = ?, approved_at = datetime('now','localtime') WHERE id = ?",
            [auth_user()['id'], $id]);
        audit_log('tps', (string)$id, 'approve', ['approval_status' => 'pending'], ['approval_status' => 'approved']);
        flash('Unit TPS "' . $old['name'] . '" disetujui oleh Quality/QRM.');
    }
    redirect('tps');
}

$editId = (int)($_GET['edit'] ?? 0);
$e2 = $editId ? one('SELECT * FROM tps_units WHERE id = ?', [$editId]) : null;
$suppliers = all('SELECT id, name FROM suppliers ORDER BY name');
$units = all('SELECT t.*, s.name supplier_name FROM tps_units t LEFT JOIN suppliers s ON s.id = t.supplier_id ORDER BY t.name');
$perf = supplier_performance();
?>
<?php if ($canWrite): ?>
<div class="panel">
  <h3><?= $editId ? 'Edit unit TPS ' . e($e2['name'] ?? '') : 'Tambah unit TPS' ?></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="save">
    <input type="hidden" name="id" value="<?= (int)($e2['id'] ?? 0) ?>">
    <div><label>Kode unit *</label><input name="code" required value="<?= e($e2['code'] ?? '') ?>" placeholder="TPS-005"></div>
    <div><label>Nama unit *</label><input name="name" required value="<?= e($e2['name'] ?? '') ?>"></div>
    <div><label>Jenis</label>
      <select name="type"><?= sel_options(['active' => 'Active cooling', 'passive' => 'Passive cooling', 'packaging' => 'Kemasan (ambient)'], $e2['type'] ?? 'active') ?></select></div>
    <div><label>Hold time</label><input name="hold_time" value="<?= e($e2['hold_time'] ?? '') ?>" placeholder="72 jam @2-8°C"></div>
    <div><label>Rentang suhu yang dijaga</label><input name="temp_range" value="<?= e($e2['temp_range'] ?? '') ?>" placeholder="2-8°C"></div>
    <div><label>Supplier/vendor penyedia</label>
      <select name="supplier_id"><option value="">— pilih —</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($e2['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Tanggal kualifikasi</label><input type="date" name="qual_date" value="<?= e($e2['qual_date'] ?? '') ?>"></div>
    <div><label>Nomor dokumen kualifikasi</label><input name="qual_doc" value="<?= e($e2['qual_doc'] ?? '') ?>" placeholder="QUAL-TPS-005"></div>
    <div><label>Jatuh tempo re-kualifikasi</label><input type="date" name="requal_due" value="<?= e($e2['requal_due'] ?? '') ?>"></div>
    <div style="grid-column:1/-1">
      <label style="font-size:12px;color:var(--text-mute);display:block;margin-bottom:6px">Kategori produk yang boleh menggunakan unit ini</label>
      <?php $sel = explode(',', (string)($e2['product_cats'] ?? '')); ?>
      <?php foreach (['A1', 'A2', 'B', 'C'] as $cat): ?>
        <label style="display:inline-block;margin-right:16px;font-size:13px">
          <input type="checkbox" name="product_cats[]" value="<?= $cat ?>" <?= in_array($cat, $sel, true) ? 'checked' : '' ?>> <?= $cat ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="rowbtns">
      <button class="btn" type="submit"><?= $editId ? 'Simpan perubahan' : 'Simpan unit TPS' ?></button>
      <a class="btn ghost" href="<?= e(url('tps')) ?>">Batal</a>
      <span class="muted">Status Berlaku / Segera jatuh tempo / Kadaluarsa dihitung di server dari tanggal jatuh tempo. Unit baru disimpan "Menunggu approval" dan harus disetujui role Quality/QRM.</span>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Unit Temperature Protective System (TPS)</h3>
  <div class="tablewrap">
  <table>
    <tr><th>Kode</th><th>Nama TPS</th><th>Jenis</th><th>Hold time</th><th>Kategori produk</th><th>Supplier</th>
      <th class="right">Success rate</th><th>Tgl kualifikasi</th><th>Dok. kualifikasi</th><th>Jatuh tempo re-kualifikasi</th>
      <th>Status</th><th>Approval</th><?php if ($canWrite): ?><th></th><?php endif; ?></tr>
    <?php if (!$units): ?><tr><td colspan="13" class="empty">Belum ada unit TPS.</td></tr><?php endif; ?>
    <?php foreach ($units as $u):
        $st = tps_status($u['requal_due']);
        $rate = tps_success_rate((int)$u['id']);
    ?>
    <tr>
      <td class="nowrap"><?= e($u['code']) ?></td>
      <td><?= e($u['name']) ?><?= $u['active'] ? '' : ' <span class="tag">nonaktif</span>' ?></td>
      <td><?= e(['active' => 'Active cooling', 'passive' => 'Passive cooling', 'packaging' => 'Kemasan'][(string)$u['type']]) ?></td>
      <td><?= e($u['hold_time'] ?: '-') ?></td>
      <td><?php foreach (array_filter(explode(',', (string)$u['product_cats'])) as $c): ?><span class="tag"><?= e(trim($c)) ?></span> <?php endforeach; ?></td>
      <td><?= e($u['supplier_name'] ?: '-') ?></td>
      <td class="right nowrap"><?= $rate !== null ? '<b>' . $rate . '%</b>' : '—' ?></td>
      <td class="nowrap"><?= e($u['qual_date'] ? fmt_date($u['qual_date']) : '-') ?></td>
      <td class="nowrap"><?= e($u['qual_doc'] ?: '-') ?></td>
      <td class="nowrap"><?= e($u['requal_due'] ? fmt_date($u['requal_due']) : '-') ?></td>
      <td><?= status_badge($st) ?></td>
      <td><?= approval_badge($u['approval_status'] ?? null) ?>
        <?php if ($canApprove && ($u['approval_status'] ?? 'pending') !== 'approved'): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="a" value="approve">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <button class="btn sm" type="submit">Setujui</button>
        </form>
        <?php endif; ?>
      </td>
      <?php if ($canWrite): ?>
      <td class="nowrap">
        <a class="btn sm ghost" href="<?= e(url('tps', ['edit' => (int)$u['id']])) ?>">Edit</a>
        <form method="post" style="display:inline"<?= confirm_form($u['active'] ? 'Nonaktifkan unit TPS ini?' : 'Aktifkan kembali unit ini?') ?>>
          <?= csrf_field() ?><input type="hidden" name="a" value="status">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="status" value="<?= $u['active'] ? 'inactive' : 'active' ?>">
          <button class="btn sm ghost" type="submit"><?= $u['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="muted">Success rate = persentase pengiriman memakai unit ini tanpa deviasi terkait suhu (dihitung dari Event &amp; Deviation Log).</p>
</div>

<div class="panel">
  <h3>Performance supplier <span class="hint">— dihitung dari data deviasi &amp; unit TPS</span></h3>
  <div class="tablewrap">
  <table>
    <tr><th>Supplier</th><th class="right">Unit TPS</th><th class="right">Lane dilayani</th>
      <th class="right">Rata-rata success rate</th><th class="right">Deviasi open</th><th>Rank</th></tr>
    <?php foreach ($perf as $p): ?>
    <tr>
      <td><?= e($p['supplier']['name']) ?></td>
      <td class="right"><?= $p['units'] ?></td>
      <td class="right"><?= $p['lanes'] ?></td>
      <td class="right"><?= $p['avg'] !== null ? '<b>' . $p['avg'] . '%</b>' : '—' ?></td>
      <td class="right"><?= $p['open_dev'] ?></td>
      <td><?= rank_badge($p['rank']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="muted">Threshold ranking: A ≥ <?= e(setting('rank_a_min', '90')) ?>%, B ≥ <?= e(setting('rank_b_min', '75')) ?>%, C &lt; <?= e(setting('rank_b_min', '75')) ?>% (dapat diubah Admin pada Manajemen Data).</p>
</div>
