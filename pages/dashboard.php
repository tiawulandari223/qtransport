<?php
$title = 'Dashboard';
$cluster = req_cluster();
[$from, $to] = date_filter();
$cat = (string)($_GET['cat'] ?? 'all');
if (!in_array($cat, ['all', 'low', 'medium', 'high', 'critical'], true)) $cat = 'all';

$lanes = lane_risk_overview($cluster, $cat);
$counts = category_counts($lanes);
$assessed = count(array_filter($lanes, fn($l) => $l['category']));

// date-range + cluster scoped deviation/CAPA queries
$w = ["e.type='deviation'", 'e.deleted_at IS NULL'];
$p = [];
if ($cluster !== 'all') { $w[] = 'l.cluster = ?'; $p[] = $cluster; }
if ($from) { $w[] = 'e.event_date >= ?'; $p[] = $from; }
if ($to) { $w[] = 'e.event_date <= ?'; $p[] = $to; }
$where = implode(' AND ', $w);

$capa = ['open' => 0, 'in_progress' => 0, 'closed' => 0, 'overdue' => 0];
foreach (all("SELECT e.status, e.deadline, COUNT(*) c FROM events e JOIN lanes l ON l.id = e.lane_id WHERE $where GROUP BY e.status, e.deadline", $p) as $r) {
    if ($r['deadline'] && $r['deadline'] < date('Y-m-d') && $r['status'] !== 'closed') $capa['overdue'] += (int)$r['c'];
    $capa[$r['status']] += (int)$r['c'];
}

$devSeries = [];
$start = new DateTimeImmutable('first day of this month -5 months');
for ($i = 0; $i < 6; $i++) {
    $d = $start->modify("+$i months");
    $devSeries[] = [
        'label' => $d->format('M y'),
        'count' => (int)scalar(
            "SELECT COUNT(*) FROM events e JOIN lanes l ON l.id = e.lane_id WHERE $where AND substr(e.event_date,1,7) = ?",
            array_merge($p, [$d->format('Y-m')])),
    ];
}
$overallSig = trend_signal(array_column($devSeries, 'count'));

$topRisks = all(
    "SELECT ri.name, COUNT(*) appearances, MAX(rs.rpn) max_rpn
     FROM risk_scores rs
     JOIN risk_items ri ON ri.id = rs.risk_item_id
     JOIN assessments a ON a.id = rs.assessment_id
     JOIN lanes l ON l.id = a.lane_id AND l.status='active'
     " . ($cluster !== 'all' ? "WHERE l.cluster = ?" : "") . "
     GROUP BY rs.risk_item_id ORDER BY max_rpn DESC, appearances DESC LIMIT 8", $cluster !== 'all' ? [$cluster] : []);

$cmp = [];
foreach (['cold', 'ambient'] as $cl) {
    $rows = lane_risk_overview($cl);
    $rpn = array_filter(array_column($rows, 'max_rpn'));
    $cmp[$cl] = [
        'lanes' => count($rows),
        'assessed' => count(array_filter($rows, fn($r) => $r['category'])),
        'avg_rpn' => $rpn ? round(array_sum($rpn) / count($rpn)) : 0,
        'critical' => count(array_filter($rows, fn($r) => $r['category'] === 'critical')),
        'dev' => (int)scalar("SELECT COUNT(*) FROM events e JOIN lanes l ON l.id=e.lane_id WHERE e.type='deviation' AND e.deleted_at IS NULL AND l.cluster=?", [$cl]),
    ];
}

$clusterName = ['all' => 'Semua cluster', 'cold' => 'Cold Chain', 'ambient' => 'Ambient'][$cluster];
?>
<div class="cards">
  <div class="card"><div class="n"><?= count($lanes) ?></div><div class="l">Total lane (<?= e($clusterName) ?>)</div></div>
  <div class="card"><div class="n"><?= $assessed ?></div><div class="l">Lane sudah dinilai</div></div>
  <div class="card"><div class="n" style="color:var(--low)"><?= $counts['low'] ?></div><div class="l">Kategori Low</div></div>
  <div class="card"><div class="n" style="color:var(--med)"><?= $counts['medium'] ?></div><div class="l">Kategori Medium</div></div>
  <div class="card"><div class="n" style="color:var(--high)"><?= $counts['high'] ?></div><div class="l">Kategori High</div></div>
  <div class="card"><div class="n" style="color:var(--crit)"><?= $counts['critical'] ?></div><div class="l">Kategori Critical</div></div>
</div>

<div class="panel">
  <h3>Filter dashboard <span class="hint">— filter memengaruhi query database, bukan hanya tampilan</span></h3>
  <form class="filters" method="get">
    <input type="hidden" name="p" value="dashboard">
    <div class="f"><label>Cluster</label>
      <select name="cluster"><?= sel_options(['all' => 'Semua', 'cold' => 'Cold Chain', 'ambient' => 'Ambient'], $cluster) ?></select></div>
    <div class="f"><label>Kategori risiko</label>
      <select name="cat"><?= sel_options(['all' => 'Semua', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'], $cat) ?></select></div>
    <div class="f"><label>Dari tanggal</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="f"><label>Sampai tanggal</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="f"><label>&nbsp;</label><button class="btn" type="submit">Terapkan filter</button></div>
  </form>
</div>

<div class="two">
  <div class="panel">
    <h3>Heatmap lane <span class="hint">— berdasarkan RPN tertinggi</span></h3>
    <?php if (!$lanes): ?><div class="empty">Tidak ada lane pada filter ini.</div><?php else: ?>
    <div class="hm">
      <?php foreach ($lanes as $l): ?>
        <a href="<?= e(url('lane', ['id' => (int)$l['id'], 'cluster' => $cluster])) ?>"
           style="background:<?= $l['category'] ? e(cat_fill($l['category'])) : '#8A97A0' ?>"
           title="<?= e($l['name']) ?>">
          <?= e($l['code']) ?><br><?= $l['max_rpn'] !== null ? (int)$l['max_rpn'] . ' RPN' : 'belum dinilai' ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Tren deviasi 6 bulan <span class="hint">— sinyal ke Quality</span></h3>
    <?= bar_chart($devSeries) ?>
    <div style="margin-top:8px;display:flex;gap:10px;align-items:center">
      <?= badge($overallSig['cat']) ?><span style="font-size:13px"><?= e($overallSig['text']) ?></span>
    </div>
  </div>
</div>

<div class="two">
  <div class="panel">
    <h3>Top risiko lintas lane</h3>
    <?php if (!$topRisks): ?><div class="empty">Belum ada penilaian risiko.</div><?php else: ?>
    <table>
      <tr><th>Risiko</th><th>Muncul di</th><th>RPN maks</th></tr>
      <?php foreach ($topRisks as $t): ?>
      <tr><td><?= e($t['name']) ?></td><td><?= (int)$t['appearances'] ?> penilaian</td>
        <td><b><?= (int)$t['max_rpn'] ?></b> <?= badge(rpn_category((int)$t['max_rpn'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Status tindak lanjut / CAPA</h3>
    <table>
      <tr><th>Status</th><th class="right">Jumlah</th></tr>
      <tr><td><?= capa_badge('open') ?></td><td class="right"><b><?= $capa['open'] ?></b></td></tr>
      <tr><td><?= capa_badge('in_progress') ?></td><td class="right"><b><?= $capa['in_progress'] ?></b></td></tr>
      <tr><td><?= capa_badge('closed') ?></td><td class="right"><b><?= $capa['closed'] ?></b></td></tr>
      <tr><td><span class="badge b-crit">Terlambat (overdue)</span></td><td class="right"><b><?= $capa['overdue'] ?></b></td></tr>
    </table>
    <p class="muted" style="margin-bottom:0">Rentang tanggal: <?= $from ? e($from) : 'awal' ?> — <?= $to ? e($to) : 'hari ini' ?>.</p>
  </div>
</div>

<div class="panel">
  <h3>Perbandingan Cold Chain vs Ambient</h3>
  <table>
    <tr><th>Cluster</th><th class="right">Lane</th><th class="right">Sudah dinilai</th>
      <th class="right">Rata-rata RPN</th><th class="right">Lane Critical</th><th class="right">Deviasi 6 bln</th></tr>
    <?php foreach (['cold' => 'Cold Chain', 'ambient' => 'Ambient'] as $k => $label): $c = $cmp[$k]; ?>
    <tr>
      <td><a href="<?= e(url('dashboard', ['cluster' => $k])) ?>"><?= $label ?></a></td>
      <td class="right"><?= $c['lanes'] ?></td>
      <td class="right"><?= $c['assessed'] ?></td>
      <td class="right"><?= $c['avg_rpn'] ?: '-' ?></td>
      <td class="right"><?= $c['critical'] ?></td>
      <td class="right"><?= $c['dev'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <h3>Rincian lane <span class="hint">— klik lane untuk detail &amp; audit trail</span></h3>
  <div class="tablewrap">
  <table>
    <tr><th>Kode</th><th>Lane</th><th>Cluster</th><th class="right">RPN tertinggi</th><th>Kategori</th><th>Penilaian terakhir</th></tr>
    <?php if (!$lanes): ?><tr><td colspan="6" class="empty">Tidak ada data.</td></tr><?php endif; ?>
    <?php foreach ($lanes as $l): ?>
    <tr>
      <td class="nowrap"><?= e($l['code']) ?></td>
      <td><a href="<?= e(url('lane', ['id' => (int)$l['id']])) ?>"><?= e($l['name']) ?></a></td>
      <td><span class="tag"><?= $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient' ?></span></td>
      <td class="right"><?= $l['max_rpn'] !== null ? (int)$l['max_rpn'] : '-' ?></td>
      <td><?= badge($l['category']) ?></td>
      <td class="nowrap"><?= $l['assessed_at'] ? e(fmt_date($l['assessed_at'])) : '-' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
