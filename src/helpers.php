<?php

function badge(?string $cat): string {
    $cat = (string)$cat;
    $map = ['low' => ['Low', 'b-low'], 'medium' => ['Medium', 'b-med'],
        'high' => ['High', 'b-high'], 'critical' => ['Critical', 'b-crit']];
    if (!isset($map[$cat])) return '<span class="badge b-neutral">—</span>';
    return '<span class="badge ' . $map[$cat][1] . '">' . $map[$cat][0] . '</span>';
}

function cat_fill(string $cat): string {
    return ['low' => '#2F8F5B', 'medium' => '#C79A1E', 'high' => '#D9722C', 'critical' => '#C23B3B'][$cat] ?? '#8A97A0';
}

function status_badge(string $status): string {
    $map = ['valid' => ['Berlaku', 'b-low'], 'due' => ['Segera jatuh tempo', 'b-med'],
        'expired' => ['Kadaluarsa', 'b-crit'], 'unknown' => ['Belum ada data', 'b-neutral']];
    $m = $map[$status] ?? $map['unknown'];
    return '<span class="badge ' . $m[1] . '">' . $m[0] . '</span>';
}

function capa_badge(string $status): string {
    $map = ['open' => ['Open', 'b-crit'], 'in_progress' => ['In progress', 'b-med'], 'closed' => ['Closed', 'b-low']];
    $m = $map[$status] ?? ['-', 'b-neutral'];
    return '<span class="badge ' . $m[1] . '">' . $m[0] . '</span>';
}

function sev_badge(?string $s): string {
    $s = (string)$s;
    $map = ['minor' => ['Minor', 'b-low'], 'major' => ['Major', 'b-med'], 'critical' => ['Critical', 'b-crit']];
    $m = $map[$s] ?? ['-', 'b-neutral'];
    return '<span class="badge ' . $m[1] . '">' . $m[0] . '</span>';
}

function rank_badge(?string $r): string {
    $r = (string)$r;
    $map = ['A' => ['Rank A', 'b-low'], 'B' => ['Rank B', 'b-med'], 'C' => ['Rank C', 'b-crit']];
    $m = $map[$r] ?? ['Belum dinilai', 'b-neutral'];
    return '<span class="badge ' . $m[1] . '">' . $m[0] . '</span>';
}

function approval_badge(?string $s): string {
    $map = ['approved' => ['Disetujui', 'b-low'], 'pending' => ['Menunggu approval', 'b-med']];
    $m = $map[(string)$s] ?? ['Belum di-approve', 'b-neutral'];
    return '<span class="badge ' . $m[1] . '">' . $m[0] . '</span>';
}

function score_options(?int $sel = null, ?string $name = null, string $cls = ''): string {
    $out = '<option value="">— pilih —</option>';
    foreach (SCORE_VALUES as $v) {
        $out .= '<option value="' . $v . '" class="' . $cls . '"' . ($sel === $v ? ' selected' : '') . '>' . $v . '</option>';
    }
    return $out;
}

/** Simple SVG bar chart used by dashboard and trending panels. */
function bar_chart(array $points, int $w = 660, int $h = 170): string {
    $pad = 30;
    $max = max(1, max(array_column($points, 'count')));
    $bw = ($w - 2 * $pad) / max(1, count($points));
    $bars = '';
    foreach ($points as $i => $p) {
        $bh = ($p['count'] / $max) * ($h - 2 * $pad - 14);
        $x = $pad + $i * $bw + $bw * 0.2;
        $y = $h - $pad - $bh;
        $bwid = $bw * 0.6;
        $bars .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bwid, 1)
            . '" height="' . round($bh, 1) . '" rx="3" fill="#173C57"/>'
            . '<text x="' . round($x + $bwid / 2, 1) . '" y="' . ($h - $pad + 13) . '" font-size="10" text-anchor="middle" fill="#6B7680">'
            . e($p['label']) . '</text>'
            . '<text x="' . round($x + $bwid / 2, 1) . '" y="' . round($y - 5, 1) . '" font-size="10" text-anchor="middle" fill="#1B2630">' . (int)$p['count'] . '</text>';
    }
    return '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '">' . $bars
        . '<line x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad) . '" y2="' . ($h - $pad) . '" stroke="#DDE3E6"/></svg>';
}

function criteria_table(string $key): string {
    $rows = '';
    foreach (SCORE_CRITERIA[$key] as $v => $label) {
        $rows .= '<tr><td><b>' . $v . '</b></td><td>' . e($label) . '</td></tr>';
    }
    return '<table><tr><th style="width:52px">Skor</th><th>Kriteria</th></tr>' . $rows . '</table>';
}

function h2a(string $t): string { return '<h2 style="margin:0;font-size:20px">' . e($t) . '</h2>'; }

function confirm_form(string $msg): string {
    return ' data-confirm="' . e($msg) . '"';
}

function req_cluster(): string {
    $c = (string)($_GET['cluster'] ?? 'all');
    return in_array($c, ['all', 'cold', 'ambient'], true) ? $c : 'all';
}

function date_filter(): array {
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
    return [$from, $to];
}

function sel_options(array $items, ?string $sel = null): string {
    $out = '';
    foreach ($items as $k => $label) {
        $out .= '<option value="' . e((string)$k) . '"' . ((string)$sel === (string)$k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $out;
}
