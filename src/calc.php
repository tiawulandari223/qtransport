<?php

// All business calculations live here — server side, from live DB data.

function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) $cache = array_column(all('SELECT key, value FROM settings'), 'value', 'key');
    return $cache[$key] ?? $default;
}

function rpn_category(int $rpn): string {
    if ($rpn <= (int)setting('rpn_low_max', '80')) return 'low';
    if ($rpn <= (int)setting('rpn_med_max', '250')) return 'medium';
    if ($rpn <= (int)setting('rpn_high_max', '600')) return 'high';
    return 'critical';
}

function category_label(string $cat): string {
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'][$cat] ?? $cat;
}

function response_for(string $cat): string {
    return [
        'low' => 'Terima risiko (monitor rutin)',
        'medium' => 'Butuh mitigasi standar',
        'high' => 'Mitigasi wajib + lane verification',
        'critical' => 'Reroute / ganti TPS-supplier',
    ][$cat] ?? '';
}

const RESPONSE_OPTIONS = [
    'Terima risiko (monitor rutin)',
    'Butuh mitigasi standar',
    'Mitigasi wajib + lane verification',
    'Reroute / ganti TPS-supplier',
    'Tolak lane (reject)',
];

const RPN_BANDS = [
    ['low', '8 - 80', 'Terima risiko, monitor rutin'],
    ['medium', '81 - 250', 'Mitigasi standar, review berkala'],
    ['high', '251 - 600', 'Mitigasi wajib, lane verification'],
    ['critical', '601 - 1000', 'Reroute/ganti TPS-supplier atau tolak lane'],
];

const SCORE_VALUES = [2, 4, 6, 8, 10];

const SCORE_CRITERIA = [
    'severity' => [
        2 => 'Dampak minimal, tidak mempengaruhi mutu/keamanan produk',
        4 => 'Dampak ringan, penurunan mutu minor, produk masih bisa dipakai',
        6 => 'Dampak sedang, berpotensi tidak memenuhi spesifikasi, perlu evaluasi',
        8 => 'Dampak besar, produk berpotensi tidak layak digunakan/dijual',
        10 => 'Dampak kritis, produk pasti rusak/tidak aman bagi pengguna',
    ],
    'occurrence' => [
        2 => 'Sangat jarang, historis kurang dari 1x per tahun',
        4 => 'Jarang, sekitar 1-2x per tahun',
        6 => 'Kadang terjadi, beberapa kali per kuartal',
        8 => 'Sering, hampir tiap bulan/tiap pengiriman',
        10 => 'Sangat sering, hampir selalu terjadi tiap pengiriman',
    ],
    'detection' => [
        2 => 'Sangat mudah terdeteksi, monitoring real-time/otomatis',
        4 => 'Mudah terdeteksi, ada kontrol rutin sebelum dampak muncul',
        6 => 'Cukup sulit, kontrol manual/berkala',
        8 => 'Sulit, kontrol terbatas, baru diketahui setelah kejadian',
        10 => 'Hampir tidak terdeteksi, tidak ada kontrol memadai',
    ],
];

/** TPS qualification status from server date vs requalification due date. */
function tps_status(?string $due, ?int $days = null): string {
    if (!$due) return 'unknown';
    $days = $days ?? (int)setting('tps_due_days', '60');
    $diff = (new DateTimeImmutable($due))->setTime(0, 0)->diff(new DateTimeImmutable('today'))->days;
    $past = (new DateTimeImmutable($due)) < new DateTimeImmutable('today');
    if ($past) return 'expired';
    if ($diff <= $days) return 'due';
    return 'valid';
}

function tps_status_label(string $status): string {
    return ['valid' => 'Berlaku', 'due' => 'Segera jatuh tempo', 'expired' => 'Kadaluarsa', 'unknown' => 'Belum ada data'][$status];
}

/**
 * Success rate = share of logged shipments on this unit with no temperature-related deviation.
 * Returns null when the unit has never been used.
 */
function tps_success_rate(int $tpsId): ?float {
    $row = one(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN e.type = 'deviation' AND COALESCE(ri.temp_related,0) = 1 THEN 1 ELSE 0 END) fail
         FROM events e LEFT JOIN risk_items ri ON ri.id = e.risk_item_id
         WHERE e.deleted_at IS NULL AND e.tps_id = ?", [$tpsId]);
    $total = (int)$row['total'];
    if ($total === 0) return null;
    return round(100 * (1 - ((int)$row['fail']) / $total), 1);
}

function supplier_performance(): array {
    $out = [];
    foreach (all('SELECT * FROM suppliers ORDER BY name') as $s) {
        $units = all('SELECT id, name FROM tps_units WHERE supplier_id = ? AND active = 1', [$s['id']]);
        $rates = [];
        foreach ($units as $u) {
            $r = tps_success_rate((int)$u['id']);
            if ($r !== null) $rates[] = $r;
        }
        $avg = $rates ? round(array_sum($rates) / count($rates), 1) : null;
        $openDev = (int)scalar(
            "SELECT COUNT(*) FROM events e
             JOIN tps_units t ON t.supplier_id = ?
             WHERE e.tps_id = t.id AND e.type='deviation' AND e.status IN ('open','in_progress') AND e.deleted_at IS NULL",
            [$s['id']]);
        $laneCount = (int)scalar('SELECT COUNT(*) FROM lanes WHERE supplier_id = ? AND status = ?', [$s['id'], 'active']);
        $rank = null;
        if ($avg !== null) {
            $rank = $avg >= (float)setting('rank_a_min', '90') ? 'A'
                : ($avg >= (float)setting('rank_b_min', '75') ? 'B' : 'C');
        }
        $out[] = ['supplier' => $s, 'units' => count($units), 'avg' => $avg, 'open_dev' => $openDev,
            'lanes' => $laneCount, 'rank' => $rank];
    }
    return $out;
}

/** Latest assessment per lane (with its worst score) — used by dashboard/heatmap. */
function lane_risk_overview(?string $cluster = null, ?string $cat = null): array {
    $where = ['l.status = ?']; $p = ['active'];
    if ($cluster && $cluster !== 'all') { $where[] = 'l.cluster = ?'; $p[] = $cluster; }
    $sql = "SELECT l.*, a.id assessment_id, a.assessed_at, a.version,
                   MAX(rs.rpn) max_rpn,
                   (SELECT rs2.category FROM risk_scores rs2 WHERE rs2.assessment_id = a.id ORDER BY rs2.rpn DESC LIMIT 1) category
            FROM lanes l
            LEFT JOIN assessments a ON a.id = (SELECT id FROM assessments WHERE lane_id = l.id ORDER BY version DESC, id DESC LIMIT 1)
            LEFT JOIN risk_scores rs ON rs.assessment_id = a.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.id
            ORDER BY max_rpn IS NULL, max_rpn DESC, l.code";
    $rows = all($sql, $p);
    if ($cat && $cat !== 'all') $rows = array_values(array_filter($rows, fn($r) => $r['category'] === $cat));
    return $rows;
}

function category_counts(array $lanes): array {
    $c = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0, 'none' => 0];
    foreach ($lanes as $l) $c[$l['category'] ?: 'none']++;
    return $c;
}

function overall_monthly_deviation(): array {
    $months = [];
    $start = new DateTimeImmutable('first day of this month -5 months');
    for ($i = 0; $i < 6; $i++) {
        $d = $start->modify("+$i months");
        $months[] = ['label' => $d->format('M y'), 'count' => (int)scalar(
            "SELECT COUNT(*) FROM events WHERE type='deviation' AND deleted_at IS NULL AND substr(event_date,1,7)=?",
            [$d->format('Y-m')])];
    }
    return $months;
}

/** Signal rules: 3 consecutive rises = critical, rising vs start = medium, else low. */
function trend_signal(array $counts): array {
    $n = count($counts);
    $last = array_slice($counts, -3);
    if (count($last) === 3 && $last[2] > $last[1] && $last[1] > $last[0]) {
        return ['cat' => 'critical', 'text' => 'Naik 3 bulan berturut-turut — investigasi Quality segera'];
    }
    if ($n >= 2 && $counts[$n - 1] > $counts[0]) {
        return ['cat' => 'medium', 'text' => 'Cenderung naik dibanding awal periode — pantau lebih lanjut'];
    }
    return ['cat' => 'low', 'text' => 'Stabil/menurun'];
}

/** Breakdown rows: category + lane + TPS + product, monthly counts and signal. */
function trend_breakdown(?string $cluster = null): array {
    $where = ["e.type='deviation'", 'e.deleted_at IS NULL'];
    $p = [];
    if ($cluster && $cluster !== 'all') { $where[] = 'l.cluster = ?'; $p[] = $cluster; }
    $start = (new DateTimeImmutable('first day of this month -5 months'))->format('Y-m-d');
    $end = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
    $rows = all(
        "SELECT COALESCE(ri.name,'(tanpa kategori)') cat_name, l.name lane_name,
                COALESCE(t.name,'-') tps_name, COALESCE(p.name,'-') prod_name,
                COALESCE(ri.id,0) ri, e.lane_id, COALESCE(e.tps_id,0) tps_id, COALESCE(e.product_id,0) prod_id,
                substr(e.event_date,1,7) m, COUNT(*) c
         FROM events e
         LEFT JOIN risk_items ri ON ri.id = e.risk_item_id
         LEFT JOIN lanes l ON l.id = e.lane_id
         LEFT JOIN tps_units t ON t.id = e.tps_id
         LEFT JOIN products p ON p.id = e.product_id
         WHERE " . implode(' AND ', $where) . " AND e.event_date BETWEEN ? AND ?
         GROUP BY ri, e.lane_id, tps_id, prod_id, m", array_merge($p, [$start, $end]));

    $months = [];
    $cur = new DateTimeImmutable($start);
    while ($cur->format('Y-m-d') <= $end) { $months[$cur->format('Y-m')] = $cur->format('M y'); $cur = $cur->modify('first day of next month'); }
    $mkeys = array_keys($months);

    $grouped = [];
    foreach ($rows as $r) {
        $k = $r['ri'] . '|' . $r['lane_id'] . '|' . $r['tps_id'] . '|' . $r['prod_id'];
        $grouped[$k] ??= ['category' => $r['cat_name'], 'lane' => $r['lane_name'], 'tps' => $r['tps_name'],
            'product' => $r['prod_name'], 'counts' => array_fill_keys($mkeys, 0)];
        $grouped[$k]['counts'][$r['m']] = (int)$r['c'];
    }
    foreach ($grouped as &$g) {
        $g['series'] = array_values($g['counts']);
        $g['signal'] = trend_signal($g['series']);
        $g['total'] = array_sum($g['series']);
    }
    unset($g);
    usort($grouped, fn($a, $b) => $b['total'] <=> $a['total']);
    return ['months' => array_values($months), 'rows' => $grouped];
}

/** CAPA / follow-up status of deviations. */
function capa_status(): array {
    $rows = all("SELECT status, deadline, COUNT(*) c FROM events
                 WHERE type='deviation' AND deleted_at IS NULL GROUP BY status, deadline");
    $out = ['open' => 0, 'in_progress' => 0, 'closed' => 0, 'overdue' => 0];
    foreach ($rows as $r) {
        $overdue = $r['deadline'] && $r['deadline'] < date('Y-m-d') && $r['status'] !== 'closed';
        if ($overdue) $out['overdue'] += (int)$r['c'];
        $out[$r['status']] += (int)$r['c'];
    }
    return $out;
}

/** Occurrence helper: actual deviation frequency on a lane over the last 6 months (RAS-04). */
function lane_deviation_count(int $laneId, int $months = 6): int {
    $from = (new DateTimeImmutable("first day of -$months months"))->format('Y-m-d');
    return (int)scalar("SELECT COUNT(*) FROM events WHERE lane_id=? AND type='deviation' AND deleted_at IS NULL AND event_date >= ?",
        [$laneId, $from]);
}

function top_risks(int $limit = 10): array {
    return all(
        "SELECT ri.name, COUNT(*) appearances, MAX(rs.rpn) max_rpn
         FROM risk_scores rs
         JOIN risk_items ri ON ri.id = rs.risk_item_id
         JOIN assessments a ON a.id = rs.assessment_id
         JOIN lanes l ON l.id = a.lane_id AND l.status = 'active'
         GROUP BY rs.risk_item_id ORDER BY max_rpn DESC, appearances DESC LIMIT $limit");
}

function report_status(string $reviewDue): string {
    $due = (new DateTimeImmutable($reviewDue))->setTime(0, 0);
    $today = new DateTimeImmutable('today');
    $days = (int)$today->diff($due)->days;
    if ($due < $today) return 'late';
    $limit = (int)setting('report_due_days', '30');
    if ($days <= $limit) return 'soon';
    return 'scheduled';
}

function report_status_label(string $s): string {
    return ['scheduled' => 'Terjadwal', 'soon' => 'Segera (≤30 hari)', 'late' => 'Terlambat review'][$s];
}
