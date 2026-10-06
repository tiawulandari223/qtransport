<?php
$title = 'Report Center';
$action = (string)($_GET['a'] ?? '');

if ($action === 'download') {
    deny_unless(can('report', 'download'), 'Role Anda tidak boleh mengunduh laporan.');
    $id = (int)($_GET['id'] ?? 0);
    $r = one('SELECT * FROM reports WHERE id = ?', [$id]);
    if (!$r) { http_response_code(404); exit('Laporan tidak ditemukan.'); }
    $file = rtrim(config('reports_path'), '/') . '/' . basename($r['file_path']);
    if (!is_file($file)) { http_response_code(404); exit('File laporan hilang dari storage. Silakan generate ulang.'); }
    audit_log('report', (string)$id, 'download', null, ['file' => basename($r['file_path'])]);
    header('Content-Type: ' . ($r['format'] === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($r['file_path'])) . '"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

if (is_post() && $action === 'generate') {
    csrf_check();
    deny_unless(can('report', 'generate'), 'Hanya Admin/Quality yang boleh generate laporan.');
    $type = (string)post('type');
    $format = post('format') === 'docx' ? 'docx' : 'pdf';
    $period = (string)post('period') ?: date('Y') . ' Q' . (int)ceil(((int)date('n')) / 3);
    $target = (string)post('target');
    $meta = REPORT_TYPES[$type] ?? null;
    if (!$meta) { flash('Jenis laporan tidak dikenal.', 'err'); redirect('report'); }
    try {
        if ($meta['target'] === 'none') $targetId = null;
        elseif ($meta['target'] === 'cluster') $targetId = in_array($target, ['cold', 'ambient', 'all'], true) ? $target : 'all';
        elseif ($meta['target'] === 'lane') $targetId = (int)$target;
        else $targetId = (int)$target;
        $id = generate_report($type, $targetId, $format, $period, auth_user()['id']);
        flash('Laporan berhasil digenerate. Unduh dari daftar riwayat di bawah.');
        redirect('report', ['new' => $id]);
    } catch (Throwable $e) {
        flash('Gagal generate laporan: ' . $e->getMessage(), 'err');
        redirect('report');
    }
}

$lanes = all("SELECT id, code, name FROM lanes WHERE status='active' ORDER BY code");
$suppliers = all('SELECT id, name FROM suppliers ORDER BY name');
$history = all(
    'SELECT r.*, u.name creator FROM reports r LEFT JOIN users u ON u.id = r.created_by
     ORDER BY r.id DESC LIMIT 200');
$canGenerate = can('report', 'generate');
?>
<div class="panel">
  <h3>Generate laporan baru <span class="hint">— PDF &amp; DOCX dirender di server, bukan trik HTML</span></h3>
  <?php if (!$canGenerate): ?>
    <p class="muted">Role Anda hanya dapat melihat dan mengunduh laporan yang sudah digenerate.</p>
  <?php endif; ?>
  <form class="mini" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="generate">
    <div><label>Jenis laporan</label>
      <select name="type" id="report_type" <?= $canGenerate ? '' : 'disabled' ?>>
        <?php foreach (REPORT_TYPES as $k => $m): ?><option value="<?= $k ?>"><?= e($m['label']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label id="report_target_label">Lane</label>
      <select name="target" id="report_target" <?= $canGenerate ? '' : 'disabled' ?>>
        <optgroup label="Lane" data-group="tlra">
          <?php foreach ($lanes as $l): ?><option value="<?= (int)$l['id'] ?>" data-group="tlra"><?= e($l['code'] . ' — ' . $l['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Supplier" data-group="supplier">
          <?php foreach ($suppliers as $sp): ?><option value="<?= (int)$sp['id'] ?>" data-group="supplier"><?= e($sp['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Cluster" data-group="summary">
          <option value="all" data-group="summary">Semua cluster</option>
          <option value="cold" data-group="summary">Cold Chain</option>
          <option value="ambient" data-group="summary">Ambient</option>
        </optgroup>
      </select></div>
    <div><label>Format</label>
      <select name="format" <?= $canGenerate ? '' : 'disabled' ?>>
        <option value="pdf">PDF (.pdf)</option><option value="docx">Word (.docx)</option>
      </select></div>
    <div><label>Periode</label>
      <input name="period" value="<?= e(date('Y') . ' Q' . (int)ceil(((int)date('n')) / 3)) ?>" <?= $canGenerate ? '' : 'disabled' ?>></div>
    <div class="rowbtns">
      <?php if ($canGenerate): ?><button class="btn" type="submit">Generate laporan</button><?php endif; ?>
      <span class="muted">File disimpan di storage/reports dan bisa diunduh ulang kapan saja.</span>
    </div>
  </form>
</div>

<div class="panel">
  <h3>Riwayat laporan &amp; jadwal review berikutnya</h3>
  <p class="muted">Review otomatis: TLRA Report tiap 3 tahun; Summary/Comparison tiap 12 bulan; Performance Supplier tiap 6 bulan. Status dihitung setiap halaman dimuat.</p>
  <div class="tablewrap">
  <table>
    <tr><th>Nama laporan</th><th>Jenis</th><th>Format</th><th>Tanggal dibuat</th><th>Dibuat oleh</th>
      <th>Review berikutnya</th><th>Status</th><th></th></tr>
    <?php if (!$history): ?><tr><td colspan="8" class="empty">Belum ada laporan digenerate.</td></tr><?php endif; ?>
    <?php foreach ($history as $h):
        $st = report_status($h['review_due']);
    ?>
    <tr>
      <td><?= e($h['title']) ?></td>
      <td><?= e(REPORT_TYPES[$h['type']]['label'] ?? $h['type']) ?></td>
      <td><span class="tag"><?= strtoupper($h['format']) ?></span></td>
      <td class="nowrap"><?= e(fmt_date($h['created_at'])) ?></td>
      <td><?= e($h['creator'] ?: '-') ?></td>
      <td class="nowrap"><?= e(fmt_date($h['review_due'])) ?></td>
      <td><?php
        $cls = ['scheduled' => 'b-low', 'soon' => 'b-med', 'late' => 'b-crit'][$st];
        echo '<span class="badge ' . $cls . '">' . e(report_status_label($st)) . '</span>';
      ?></td>
      <td class="nowrap">
        <a class="btn sm ghost" href="<?= e(url('report', ['a' => 'download', 'id' => (int)$h['id']])) ?>">Download</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
