<?php
$title = 'Event & Deviation Log';
$cluster = req_cluster();
$action = (string)($_GET['a'] ?? '');
$canWrite = can('event', 'write');
$canClose = can('event', 'close');

if (is_post() && $action === 'save') {
    csrf_check();
    deny_unless($canWrite, 'Role Anda tidak boleh menginput event.');
    $laneId = (int)post('lane_id');
    $type = post('type') === 'deviation' ? 'deviation' : 'normal';
    $v = [
        'event_date' => (string)post('event_date'),
        'lane_id' => $laneId,
        'type' => $type,
        'risk_item_id' => post_int('risk_item_id'),
        'severity' => in_array(post('severity'), ['minor', 'major', 'critical'], true) ? post('severity') : null,
        'duration' => (string)post('duration'),
        'tps_id' => post_int('tps_id'),
        'product_id' => post_int('product_id'),
        'root_cause' => (string)post('root_cause'),
        'action_taken' => (string)post('action_taken'),
        'status' => $type === 'deviation'
            ? (in_array(post('followup_status'), ['open', 'in_progress', 'closed'], true) ? post('followup_status') : 'open')
            : null,
        'pic' => (string)post('pic'),
        'deadline' => (string)post('deadline'),
    ];
    $errors = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['event_date'])) $errors[] = 'Tanggal event wajib diisi.';
    if (!$v['lane_id'] || !one('SELECT id FROM lanes WHERE id = ?', [$v['lane_id']])) $errors[] = 'Lane wajib dipilih.';
    if ($v['type'] === 'deviation') {
        if (!$v['risk_item_id']) $errors[] = 'Kategori deviasi wajib dipilih.';
        if (!$v['severity']) $errors[] = 'Tingkat keparahan wajib dipilih.';
        if ($v['deadline'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['deadline'])) $errors[] = 'Format deadline tidak valid.';
    }
    if ($errors) {
        flash(implode(' ', $errors), 'err');
    } else {
        $cols = ['event_date', 'lane_id', 'type', 'risk_item_id', 'severity', 'duration', 'tps_id', 'product_id',
            'root_cause', 'action_taken', 'status', 'pic', 'deadline'];
        $vals = array_merge(array_map(fn($c) => $v[$c], $cols), [auth_user()['id']]);
        q('INSERT INTO events(' . implode(',', $cols) . ',created_by) VALUES(' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')', $vals);
        $nid = (int)db()->lastInsertId();
        audit_log('event', (string)$nid, 'create', null, $v);
        flash($v['type'] === 'deviation' ? 'Deviasi tercatat.' : 'Event normal tercatat.');
        redirect('event', ['cluster' => $cluster]);
    }
}

if (is_post() && in_array($action, ['status', 'delete'], true)) {
    csrf_check();
    $eid = (int)post('id');
    $old = one('SELECT * FROM events WHERE id = ?', [$eid]);
    if (!$old) { flash('Event tidak ditemukan.', 'err'); redirect('event'); }
    if ($action === 'status') {
        deny_unless($canClose, 'Role Anda tidak boleh mengubah tindak lanjut deviasi.');
        $st = in_array(post('followup_status'), ['open', 'in_progress', 'closed'], true) ? post('followup_status') : 'open';
        $pic = (string)post('pic');
        $dl = (string)post('deadline');
        $ta = (string)post('action_taken');
        if ($dl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl)) { flash('Format deadline tidak valid.', 'err'); redirect('event'); }
        q("UPDATE events SET status = ?, pic = ?, deadline = ?, action_taken = ?, updated_at = datetime('now','localtime') WHERE id = ?",
            [$st, $pic, $dl !== '' ? $dl : null, $ta, $eid]);
        $new = one('SELECT * FROM events WHERE id = ?', [$eid]);
        $diff = changed_fields($old, $new, ['updated_at']);
        if ($diff) audit_log('event', (string)$eid, 'update_followup', $old, $diff);
        flash('Tindak lanjut deviasi diperbarui.');
    } else {
        deny_unless($canWrite, 'Role Anda tidak boleh mengubah event.');
        if ($old['deleted_at']) {
            flash('Event sudah dinonaktifkan.', 'err');
        } else {
            q("UPDATE events SET deleted_at = datetime('now','localtime') WHERE id = ?", [$eid]);
            audit_log('event', (string)$eid, 'soft_delete', ['deleted_at' => null], ['deleted_at' => date('Y-m-d H:i:s')]);
            flash('Event dinonaktifkan (soft-delete). Data tetap tersimpan untuk audit dan trending.');
        }
    }
    redirect('event', ['cluster' => $cluster]);
}

// ---------------- trending ----------------
$trend = trend_breakdown($cluster);
$overall = overall_monthly_deviation();
$overallSig = trend_signal(array_column($overall, 'count'));

$lanes = all("SELECT id, code, name, cluster FROM lanes WHERE status='active'" . ($cluster !== 'all' ? ' AND cluster = ?' : '') . ' ORDER BY code', $cluster !== 'all' ? [$cluster] : []);
$riskItems = all('SELECT id, name, scope FROM risk_items WHERE active = 1 ORDER BY sort_order');
$tpsAll = all('SELECT id, name FROM tps_units WHERE active = 1 ORDER BY name');
$products = all('SELECT id, name, cluster FROM products WHERE active = 1 ORDER BY name');

$showForm = $canWrite;
$openEvents = all(
    "SELECT e.*, l.code lane_code, l.name lane_name, ri.name risk_name, t.name tps_name
     FROM events e
     JOIN lanes l ON l.id = e.lane_id
     LEFT JOIN risk_items ri ON ri.id = e.risk_item_id
     LEFT JOIN tps_units t ON t.id = e.tps_id
     WHERE e.deleted_at IS NULL AND e.type='deviation'
     " . ($cluster !== 'all' ? ' AND l.cluster = ?' : '') . '
     ORDER BY e.event_date DESC, e.id DESC LIMIT 60', $cluster !== 'all' ? [$cluster] : []);
?>
<div class="panel">
  <h3>Trending deviasi keseluruhan (per bulan)</h3>
  <?= bar_chart($overall) ?>
  <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?= badge($overallSig['cat']) ?><span style="font-size:13px"><?= e($overallSig['text']) ?></span>
  </div>
</div>

<div class="panel">
  <h3>Breakdown tren per kategori, lane, TPS &amp; produk terdampak</h3>
  <p class="muted">Sinyal otomatis dihitung di server: naik 3 bulan berturut-turut = Critical; cenderung naik vs awal periode = Medium; stabil/turun = Low.</p>
  <div class="tablewrap">
  <table>
    <tr><th>Kategori risiko</th><th>Lane</th><th>TPS</th><th>Produk terdampak</th>
      <?php foreach ($trend['months'] as $m): ?><th class="right"><?= e($m) ?></th><?php endforeach; ?>
      <th class="right">Total</th><th>Sinyal ke Quality</th></tr>
    <?php if (!$trend['rows']): ?><tr><td colspan="12" class="empty">Belum ada deviasi pada periode ini.</td></tr><?php endif; ?>
    <?php foreach ($trend['rows'] as $r): ?>
    <tr>
      <td><?= e($r['category']) ?></td><td><?= e($r['lane']) ?></td><td><?= e($r['tps']) ?></td><td><?= e($r['product']) ?></td>
      <?php foreach ($r['series'] as $c): ?><td class="right"><?= $c ?></td><?php endforeach; ?>
      <td class="right"><b><?= $r['total'] ?></b></td>
      <td><?= badge($r['signal']['cat']) ?><br><span class="muted"><?= e($r['signal']['text']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel">
  <h3>Input event harian <span class="hint">— ringkas untuk petugas lapangan</span></h3>
  <form class="mini" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="save">
    <div><label>Tanggal *</label><input type="date" name="event_date" required value="<?= date('Y-m-d') ?>"></div>
    <div><label>Lane *</label>
      <select name="lane_id" required><option value="">— pilih lane —</option>
        <?php foreach ($lanes as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['code'] . ' — ' . $l['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Tipe event *</label>
      <select name="type" id="event_type"><option value="normal">Normal</option><option value="deviation">Deviation</option></select></div>
    <div><label>Produk terkait</label>
      <select name="product_id"><option value="">— pilih —</option>
      <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e($pr['name']) ?></option><?php endforeach; ?>
      </select></div>

    <div data-when-deviation style="display:none"><label>Kategori deviasi *</label>
      <select name="risk_item_id"><option value="">— pilih —</option>
        <?php foreach ($riskItems as $ri): ?><option value="<?= (int)$ri['id'] ?>"><?= e($ri['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div data-when-deviation style="display:none"><label>Tingkat keparahan *</label>
      <select name="severity"><option value="">— pilih —</option>
        <option value="minor">Minor</option><option value="major">Major</option><option value="critical">Critical</option></select></div>
    <div data-when-deviation style="display:none"><label>Durasi/besaran deviasi</label>
      <input name="duration" placeholder="mis. 1-4 jam / 3°C di atas batas"></div>
    <div data-when-deviation style="display:none"><label>TPS yang dipakai saat kejadian</label>
      <select name="tps_id"><option value="">— pilih —</option>
        <?php foreach ($tpsAll as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div data-when-deviation style="display:none"><label>Root cause</label>
      <textarea name="root_cause" placeholder="Penyebab utama kejadian"></textarea></div>
    <div data-when-deviation style="display:none"><label>Tindakan saat kejadian</label>
      <textarea name="action_taken" placeholder="Tindakan korektif langsung"></textarea></div>
    <div data-when-deviation style="display:none"><label>Status tindak lanjut</label>
      <select name="followup_status"><option value="open">Open</option><option value="in_progress">In progress</option><option value="closed">Closed</option></select></div>
    <div data-when-deviation style="display:none"><label>PIC</label><input name="pic" placeholder="Nama PIC"></div>
    <div data-when-deviation style="display:none"><label>Deadline</label><input type="date" name="deadline"></div>
    <div class="rowbtns"><button class="btn" type="submit">Simpan event</button>
      <span class="muted">Field deviasi hanya aktif bila tipe = Deviation. Data tidak bisa dihapus permanen.</span></div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Riwayat deviasi &amp; status tindak lanjut</h3>
  <?php if (!$openEvents): ?><div class="empty">Belum ada deviasi tercatat.</div><?php else: ?>
  <div class="tablewrap">
  <table>
    <tr><th>Tanggal</th><th>Lane</th><th>Kategori</th><th>Keparahan</th><th>Durasi</th><th>TPS</th>
      <th>Root cause</th><th>Status</th><th>PIC</th><th>Deadline</th><?php if ($canWrite || $canClose): ?><th></th><?php endif; ?></tr>
    <?php foreach ($openEvents as $ev): ?>
    <tr>
      <td class="nowrap"><?= e(fmt_date($ev['event_date'])) ?></td>
      <td><?= e($ev['lane_code']) ?> — <?= e($ev['lane_name']) ?></td>
      <td><?= e($ev['risk_name'] ?: '-') ?></td>
      <td><?= sev_badge($ev['severity']) ?></td>
      <td><?= e($ev['duration'] ?: '-') ?></td>
      <td><?= e($ev['tps_name'] ?: '-') ?></td>
      <td><?= e($ev['root_cause'] ?: '-') ?></td>
      <td><?= capa_badge((string)$ev['status']) ?></td>
      <td><?= e($ev['pic'] ?: '-') ?></td>
      <td class="nowrap"><?= e($ev['deadline'] ? fmt_date($ev['deadline']) : '-') ?>
        <?php if ($ev['deadline'] && $ev['deadline'] < date('Y-m-d') && $ev['status'] !== 'closed'): ?>
          <span class="badge b-crit">Overdue</span><?php endif; ?></td>
      <?php if ($canWrite || $canClose): ?>
      <td class="nowrap">
        <?php if ($canClose): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="a" value="status">
          <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
          <input type="hidden" name="followup_status" value="<?= $ev['status'] === 'closed' ? 'open' : 'closed' ?>">
          <input type="hidden" name="pic" value="<?= e($ev['pic'] ?: '') ?>">
          <input type="hidden" name="deadline" value="<?= e($ev['deadline'] ?: '') ?>">
          <input type="hidden" name="action_taken" value="<?= e($ev['action_taken'] ?: '') ?>">
          <button class="btn sm ghost" type="submit"><?= $ev['status'] === 'closed' ? 'Buka lagi' : 'Tandai closed' ?></button>
        </form>
        <?php endif; ?>
        <?php if ($canWrite): ?>
        <form method="post" style="display:inline"<?= confirm_form('Nonaktifkan event ini (soft-delete)? Catatan tetap tersimpan untuk audit.') ?>>
          <?= csrf_field() ?><input type="hidden" name="a" value="delete">
          <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
          <button class="btn sm ghost" type="submit">Nonaktifkan</button>
        </form>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
  <p class="muted">Menampilkan 60 deviasi terakhir<?= $cluster !== 'all' ? ' pada cluster ' . e($cluster === 'cold' ? 'Cold Chain' : 'Ambient') : '' ?>.</p>
</div>
