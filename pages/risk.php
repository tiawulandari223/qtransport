<?php
$title = 'Risk Assessment & Scoring (FMEA)';
$cluster = req_cluster();
$action = (string)($_GET['a'] ?? '');
$canSave = can('risk', 'save');
$canApprove = can('risk', 'approve');

if (is_post() && $action === 'save') {
    csrf_check();
    deny_unless($canSave, 'Role Anda tidak boleh menyimpan penilaian risiko.');

    $laneId = (int)post('lane_id');
    $lane = $laneId ? one('SELECT * FROM lanes WHERE id = ? AND status = ?', [$laneId, 'active']) : null;
    $productIds = (array)post('risk_id');
    $s = (array)post('s'); $o = (array)post('o'); $d = (array)post('d');
    $ratS = (array)post('rat_s'); $ratO = (array)post('rat_o'); $ratD = (array)post('rat_d');
    $resp = (array)post('response'); $mit = (array)post('mitigation');

    $errors = [];
    if (!$lane) $errors[] = 'Lane wajib dipilih (dan harus aktif).';
    if (!$productIds) $errors[] = 'Minimal satu risiko harus dinilai.';
    if ($lane && $lane['cluster'] === 'cold' && post('tps_label') === '') $errors[] = 'TPS wajib dipilih untuk lane Cold Chain.';
    if ($lane && $lane['cluster'] === 'ambient' && post('tps_label') === '') $errors[] = 'Jenis kemasan wajib dipilih untuk lane Ambient.';

    $rows = [];
    foreach ($productIds as $rid) {
        $rid = (int)$rid;
        $item = one('SELECT * FROM risk_items WHERE id = ? AND active = 1', [$rid]);
        if (!$item) { $errors[] = 'Risiko tidak dikenal.'; continue; }
        if ($lane && !in_array($item['scope'], ['both', $lane['cluster']], true)) {
            $errors[] = 'Risiko "' . $item['name'] . '" tidak berlaku untuk cluster lane ini.';
            continue;
        }
        $sv = (int)($s[$rid] ?? 0); $ov = (int)($o[$rid] ?? 0); $dv = (int)($d[$rid] ?? 0);
        foreach ([['Severity', $sv], ['Occurrence', $ov], ['Detection', $dv]] as [$n, $vv]) {
            if (!in_array($vv, SCORE_VALUES, true)) $errors[] = "$n untuk \"" . $item['name'] . '" harus salah satu dari 2/4/6/8/10.';
        }
        $rs = trim((string)($ratS[$rid] ?? ''));
        $ro = trim((string)($ratO[$rid] ?? ''));
        $rd = trim((string)($ratD[$rid] ?? ''));
        if ($rs === '') $errors[] = 'Rationale Severity wajib diisi untuk "' . $item['name'] . '".';
        if ($ro === '') $errors[] = 'Rationale Occurrence wajib diisi untuk "' . $item['name'] . '".';
        if ($rd === '') $errors[] = 'Rationale Detection wajib diisi untuk "' . $item['name'] . '".';
        if ($errors) continue;

        $rpn = $sv * $ov * $dv;                       // computed server-side
        $cat = rpn_category($rpn);
        $response = trim((string)($resp[$rid] ?? ''));
        if ($response === '' || !in_array($response, RESPONSE_OPTIONS, true)) $response = response_for($cat);
        $rows[] = ['item' => $item, 's' => $sv, 'o' => $ov, 'd' => $dv,
            'rat_s' => $rs, 'rat_o' => $ro, 'rat_d' => $rd,
            'rpn' => $rpn, 'cat' => $cat, 'response' => $response,
            'mitigation' => trim((string)($mit[$rid] ?? '')) ?: $item['mitigation']];
    }

    if ($errors) {
        flash(implode(' ', $errors), 'err');
    } else {
        $productId = post_int('product_id');
        $tpsLabel = (string)post('tps_label');
        $tpsId = null;
        if ($lane['cluster'] === 'cold') {
            $t = one('SELECT id FROM tps_units WHERE name = ?', [$tpsLabel]);
            $tpsId = $t ? (int)$t['id'] : null;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $version = (int)scalar('SELECT COALESCE(MAX(version),0) + 1 FROM assessments WHERE lane_id = ?', [$laneId]);
            q('INSERT INTO assessments(lane_id,tps_label,tps_id,product_id,version,notes,assessed_by) VALUES(?,?,?,?,?,?,?)',
                [$laneId, $tpsLabel, $tpsId, $productId, $version, trim((string)post('notes')), auth_user()['id']]);
            $aid = (int)db()->lastInsertId();
            $summary = [];
            foreach ($rows as $r) {
                q('INSERT INTO risk_scores(assessment_id,risk_item_id,s,o,d,rat_s,rat_o,rat_d,rpn,category,response,mitigation) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$aid, $r['item']['id'], $r['s'], $r['o'], $r['d'], $r['rat_s'], $r['rat_o'], $r['rat_d'],
                        $r['rpn'], $r['cat'], $r['response'], $r['mitigation']]);
                $summary[] = ['risk' => $r['item']['name'], 'rpn' => $r['rpn'], 'category' => $r['cat'], 'response' => $r['response']];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        audit_log('assessment', (string)$aid, 'create', null, [
            'lane' => $lane['name'], 'version' => $version, 'tps' => $tpsLabel,
            'product_id' => $productId, 'scores' => $summary,
        ]);
        flash('Penilaian tersimpan sebagai versi ' . $version . ' ke risk register (menunggu approval Quality/QRM). Penilaian sebelumnya tetap tersimpan.');
        redirect('risk', ['cluster' => $lane['cluster']]);
    }
}

if (is_post() && $action === 'approve') {
    csrf_check();
    deny_unless($canApprove, 'Hanya role Quality/QRM yang boleh approve penilaian risiko.');
    $id = (int)post('id');
    $old = one('SELECT * FROM assessments WHERE id = ?', [$id]);
    if (!$old) { flash('Penilaian tidak ditemukan.', 'err'); redirect('risk'); }
    if ($old['approval_status'] === 'approved') {
        flash('Penilaian sudah disetujui sebelumnya.');
    } else {
        q("UPDATE assessments SET approval_status = 'approved', approved_by = ?, approved_at = datetime('now','localtime') WHERE id = ?",
            [auth_user()['id'], $id]);
        audit_log('assessment', (string)$id, 'approve', ['approval_status' => 'pending'], ['approval_status' => 'approved']);
        flash('Penilaian lane versi ' . (int)$old['version'] . ' disetujui oleh Quality/QRM.');
    }
    redirect('risk', ['cluster' => $cluster, 'lane' => (int)$old['lane_id']]);
}

// ---------------- data ----------------
$lanes = all("SELECT id, code, name, cluster, mode, lead_time_hours, origin, destination, supplier_id, temp_range, packaging_type
              FROM lanes WHERE status='active'" . ($cluster !== 'all' ? ' AND cluster = ?' : '') . ' ORDER BY code',
    $cluster !== 'all' ? [$cluster] : []);
$selectedLane = (int)($_GET['lane'] ?? 0);
if (!$selectedLane && $lanes) $selectedLane = (int)$lanes[0]['id'];
$lane = $selectedLane ? one('SELECT * FROM lanes WHERE id = ?', [$selectedLane]) : null;

$tpsUnits = all('SELECT id, name FROM tps_units WHERE active = 1 ORDER BY name');
$products = all('SELECT id, name, cluster FROM products WHERE active = 1 ORDER BY name');
$riskScope = $lane ? ($lane['cluster'] === 'cold' ? ['cold', 'both'] : ['ambient', 'both']) : ['both'];
$ph = implode(',', array_fill(0, count($riskScope), '?'));
$checklist = all("SELECT * FROM risk_items WHERE active = 1 AND scope IN ($ph) ORDER BY sort_order", $riskScope);
$devCount = $lane ? lane_deviation_count((int)$lane['id']) : 0;

// register (latest N with filters)
$regCat = (string)($_GET['regcat'] ?? 'all');
$rw = []; $rp = [];
if ($cluster !== 'all') { $rw[] = 'l.cluster = ?'; $rp[] = $cluster; }
if ($regCat !== 'all' && in_array($regCat, ['low', 'medium', 'high', 'critical'], true)) { $rw[] = 'rs.category = ?'; $rp[] = $regCat; }
$regSql = 'SELECT rs.*, ri.name risk_name, l.name lane_name, l.code lane_code, l.cluster, a.version, a.assessed_at,
                  a.tps_label, u.name assessor, p.name product_name
           FROM risk_scores rs
           JOIN assessments a ON a.id = rs.assessment_id
           JOIN lanes l ON l.id = a.lane_id
           JOIN risk_items ri ON ri.id = rs.risk_item_id
           LEFT JOIN users u ON u.id = a.assessed_by
           LEFT JOIN products p ON p.id = a.product_id'
    . ($rw ? ' WHERE ' . implode(' AND ', $rw) : '')
    . ' ORDER BY a.assessed_at DESC, rs.rpn DESC LIMIT 200';
$register = all($regSql, $rp);
?>
<div class="panel">
  <h3>1. Pilih lane, TPS/kemasan &amp; produk</h3>
  <form class="mini" method="get">
    <input type="hidden" name="p" value="risk">
    <input type="hidden" name="cluster" value="<?= e($cluster) ?>">
    <div><label>Lane</label>
      <select name="lane" onchange="this.form.submit()">
        <?php foreach ($lanes as $l): ?>
        <option value="<?= (int)$l['id'] ?>" <?= $selectedLane === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div style="grid-column:1/-1"><span class="muted">Profil lane, checklist risiko &amp; pilihan TPS dimuat dari database setelah lane dipilih.</span></div>
  </form>

  <?php if ($lane): ?>
  <div class="cards" style="margin-top:14px;margin-bottom:0">
    <div class="card"><div class="l">Rute</div><div style="font-size:14px;font-weight:600;margin-top:2px"><?= e($lane['origin'] . ' → ' . $lane['destination']) ?></div></div>
    <div class="card"><div class="l">Moda transportasi</div><div style="font-size:14px;font-weight:600;margin-top:2px"><?= e($lane['mode'] ?: '-') ?></div></div>
    <div class="card"><div class="l">Durasi perjalanan</div><div style="font-size:14px;font-weight:600;margin-top:2px"><?= e($lane['lead_time_hours'] !== null ? $lane['lead_time_hours'] . ' jam' : '-') ?></div></div>
    <div class="card"><div class="l">Cluster</div><div style="font-size:14px;font-weight:600;margin-top:2px"><?= $lane['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient' ?></div></div>
    <div class="card"><div class="l">Deviasi 6 bulan (bantuan O)</div><div style="font-size:14px;font-weight:600;margin-top:2px"><?= $devCount ?> kejadian</div></div>
  </div>
  <?php
    $latest = one('SELECT * FROM assessments WHERE lane_id = ? ORDER BY version DESC, id DESC LIMIT 1', [(int)$lane['id']]);
    if ($latest): ?>
  <div class="rowbtns" style="margin-top:14px">
    <span class="muted">Penilaian terakhir: versi <?= (int)$latest['version'] ?> · <?= e(fmt_date($latest['assessed_at'])) ?></span>
    <?= approval_badge($latest['approval_status'] ?? null) ?>
    <?php if (($latest['approval_status'] ?? 'pending') !== 'approved'): ?>
      <?php if ($canApprove): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="a" value="approve">
        <input type="hidden" name="id" value="<?= (int)$latest['id'] ?>">
        <button class="btn sm" type="submit">Approve penilaian</button>
      </form>
      <?php else: ?>
      <span class="muted">Menunggu approval Quality/QRM.</span>
      <?php endif; ?>
    <?php else: ?>
      <span class="muted">Disetujui<?= $latest['approved_by'] ? ' oleh ' . e(one('SELECT name FROM users WHERE id = ?', [(int)$latest['approved_by']])['name'] ?? '') : '' ?><?= $latest['approved_at'] ? ' · ' . e(fmt_date($latest['approved_at'])) : '' ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <details class="ref" open>
    <summary>Referensi skoring Severity, Occurrence, Detection &amp; RPN matrix</summary>
    <div class="refgrid" style="margin-top:12px">
      <div><h4 style="margin:0 0 6px;font-size:12.5px">Severity (dampak)</h4><?= criteria_table('severity') ?></div>
      <div><h4 style="margin:0 0 6px;font-size:12.5px">Occurrence (frekuensi)</h4><?= criteria_table('occurrence') ?></div>
      <div><h4 style="margin:0 0 6px;font-size:12.5px">Detection (deteksi)</h4><?= criteria_table('detection') ?></div>
    </div>
    <h4 style="margin:14px 0 6px;font-size:12.5px">RPN matrix — RPN = S × O × D (rentang 8–1000), threshold dapat diubah Admin</h4>
    <table>
      <tr><th>Rentang RPN</th><th>Kategori</th><th>Tindakan umum</th></tr>
      <?php foreach (rpn_bands_table() as $b): ?>
      <tr><td class="nowrap"><?= e($b['range']) ?></td><td><?= badge($b['cat']) ?></td><td><?= e($b['action']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </details>
</div>

<?php if ($lane && $checklist): ?>
<div class="panel">
  <h3>2. Risk identification &amp; penilaian FMEA <span class="hint">— skala 2/4/6/8/10, rationale wajib per parameter</span></h3>
  <p class="muted" style="margin-top:-4px">RPN dihitung ulang oleh server saat disimpan. Saran respons otomatis mengikuti kategori RPN dan dapat diubah oleh Quality/QRM.</p>
  <form method="post" class="need-rational">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="save">
    <input type="hidden" name="lane_id" value="<?= (int)$lane['id'] ?>">
    <div class="mini" style="margin-bottom:14px">
      <div><label><?= $lane['cluster'] === 'cold' ? 'TPS yang dipakai *' : 'Jenis kemasan *' ?></label>
        <?php if ($lane['cluster'] === 'cold'): ?>
        <select name="tps_label" required>
          <option value="">— pilih unit TPS —</option>
          <?php foreach ($tpsUnits as $t): ?><option value="<?= e($t['name']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
        <?php else: ?>
        <select name="tps_label" required>
          <option value="">— pilih jenis kemasan —</option>
          <?php foreach (['Dus standar + pallet', 'Shrink wrap + corner protector', 'Corrugated box + desiccant'] as $pk): ?>
          <option value="<?= e($pk) ?>" <?= $lane['packaging_type'] === $pk ? 'selected' : '' ?>><?= e($pk) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div><label>Produk</label>
        <select name="product_id">
          <?php foreach (array_filter($products, fn($p) => $p['cluster'] === $lane['cluster']) as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Catatan penilaian (opsional)</label>
        <input name="notes" value="<?= e((string)($_GET['notes'] ?? '')) ?>" placeholder="mis. review Q3 2026"></div>
    </div>

    <?php foreach ($checklist as $it): ?>
    <div class="rcard">
      <h4><?= e($it['name']) ?> <span class="tag"><?= $it['scope'] === 'both' ? 'Cold Chain &amp; Ambient' : ($it['scope'] === 'cold' ? 'Cold Chain' : 'Ambient') ?></span></h4>
      <div class="mit">Saran mitigasi: <?= e($it['mitigation'] ?: '—') ?></div>
      <div class="rgrid">
        <div class="f">
          <label>Severity (S)</label>
          <select name="s[<?= (int)$it['id'] ?>]" class="sc-s"><?= score_options(null) ?></select>
          <input name="rat_s[<?= (int)$it['id'] ?>]" placeholder="Rationale severity…">
          <div class="crit"><?= e(SCORE_CRITERIA['severity'][6]) ?> · skala 2–10</div>
        </div>
        <div class="f">
          <label>Occurrence (O)</label>
          <select name="o[<?= (int)$it['id'] ?>]" class="sc-o"><?= score_options(null) ?></select>
          <input name="rat_o[<?= (int)$it['id'] ?>]" placeholder="Rationale occurrence…">
          <div class="crit">Referensi data: <?= $devCount ?> deviasi pada lane ini dalam 6 bulan terakhir. <?= e(SCORE_CRITERIA['occurrence'][6]) ?></div>
        </div>
        <div class="f">
          <label>Detection (D)</label>
          <select name="d[<?= (int)$it['id'] ?>]" class="sc-d"><?= score_options(null) ?></select>
          <input name="rat_d[<?= (int)$it['id'] ?>]" placeholder="Rationale detection…">
          <div class="crit"><?= e(SCORE_CRITERIA['detection'][6]) ?> · skala 2–10</div>
        </div>
      </div>
      <div class="rfoot">
        <span>RPN: <span class="rpnbox">0</span></span>
        <span class="badge b-neutral rpn-badge">—</span>
        <select name="response[<?= (int)$it['id'] ?>]">
          <option value="">— respons otomatis sesuai kategori —</option>
          <?php foreach (RESPONSE_OPTIONS as $ro): ?><option value="<?= e($ro) ?>"><?= e($ro) ?></option><?php endforeach; ?>
        </select>
        <input name="mitigation[<?= (int)$it['id'] ?>]" placeholder="Mitigasi khusus (opsional)" style="flex:1;min-width:220px;padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px">
        <input type="hidden" name="risk_id[]" value="<?= (int)$it['id'] ?>">
      </div>
    </div>
    <?php endforeach; ?>

    <div class="rowbtns" style="margin-top:14px">
      <?php if ($canSave): ?>
        <button class="btn" type="submit">Simpan penilaian ke risk register</button>
      <?php else: ?>
        <span class="badge b-med">Role Anda hanya dapat melihat penilaian</span>
      <?php endif; ?>
      <span class="muted">Penilaian ulang tersimpan sebagai versi baru — riwayat tidak ditimpa.</span>
    </div>
  </form>
</div>
<?php elseif ($lane): ?>
<div class="panel"><div class="empty">Tidak ada checklist risiko aktif untuk cluster ini. Hubungi Admin.</div></div>
<?php endif; ?>

<div class="panel">
  <h3>Risk register <span class="hint">— tabel konsolidasi semua penilaian</span></h3>
  <form class="filters" method="get">
    <input type="hidden" name="p" value="risk">
    <input type="hidden" name="cluster" value="<?= e($cluster) ?>">
    <input type="hidden" name="lane" value="<?= (int)$selectedLane ?>">
    <div class="f"><label>Kategori</label>
      <select name="regcat"><?= sel_options(['all' => 'Semua', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'], $regCat) ?></select></div>
    <div class="f"><label>&nbsp;</label><button class="btn" type="submit">Filter</button></div>
  </form>
  <div class="tablewrap">
  <table>
    <tr><th>Tgl</th><th>Lane</th><th>Versi</th><th>Produk</th><th>TPS/Kemasan</th><th>Risiko</th>
      <th class="right">S</th><th class="right">O</th><th class="right">D</th><th class="right">RPN</th>
      <th>Kat.</th><th>Respons</th><th>Penilai</th></tr>
    <?php if (!$register): ?><tr><td colspan="13" class="empty">Belum ada entri risk register.</td></tr><?php endif; ?>
    <?php foreach ($register as $r): ?>
    <tr>
      <td class="nowrap"><?= e(fmt_date($r['assessed_at'])) ?></td>
      <td><?= e($r['lane_code']) ?> — <?= e($r['lane_name']) ?></td>
      <td class="right">v<?= (int)$r['version'] ?></td>
      <td><?= e($r['product_name'] ?: '-') ?></td>
      <td><?= e($r['tps_label'] ?: '-') ?></td>
      <td><?= e($r['risk_name']) ?></td>
      <td class="right"><?= (int)$r['s'] ?></td><td class="right"><?= (int)$r['o'] ?></td><td class="right"><?= (int)$r['d'] ?></td>
      <td class="right"><b><?= (int)$r['rpn'] ?></b></td>
      <td><?= badge($r['category']) ?></td>
      <td><?= e($r['response']) ?></td>
      <td><?= e($r['assessor'] ?: '-') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<?php
function rpn_bands_table(): array {
    $lo = 8;
    $bands = [];
    foreach ([['low', 'rpn_low_max', 'Terima risiko, monitor rutin'],
              ['medium', 'rpn_med_max', 'Mitigasi standar, review berkala'],
              ['high', 'rpn_high_max', 'Mitigasi wajib, lane verification'],
              ['critical', null, 'Reroute/ganti TPS-supplier atau tolak lane']] as [$cat, $key, $action]) {
        $hi = $key ? (int)setting($key, '0') : 1000;
        $bands[] = ['cat' => $cat, 'range' => $lo . ' - ' . $hi, 'action' => $action];
        $lo = $hi + 1;
    }
    return $bands;
}
