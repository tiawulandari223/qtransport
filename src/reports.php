<?php

/**
 * Report generation. One block-based document model is rendered to both PDF (FPDF)
 * and DOCX (src/docx.php), so content can never drift between formats.
 *
 * Block types: h1,h2,h3,p,ul,pagebreak,table{head,rows,widths,fills}
 */

const REPORT_TYPES = [
    'tlra' => ['label' => 'TLRA Report (per lane)', 'months' => 36, 'target' => 'lane'],
    'summary' => ['label' => 'Summary Report (per cluster)', 'months' => 12, 'target' => 'cluster'],
    'comparison' => ['label' => 'Cluster Comparison Report', 'months' => 12, 'target' => 'none'],
    'supplier' => ['label' => 'Performance Supplier Report', 'months' => 6, 'target' => 'supplier'],
];

// 8pt Arial line height in mm (FPDF FontSize) + 1.4mm leading
const PDF_ROW_LH = 4.3;
const CAT_FILL = ['low' => 'C6E7D4', 'medium' => 'FBEAB6', 'high' => 'F7CBA4', 'critical' => 'F2B6B6'];
const CAT_LABEL = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

function report_blocks(string $type, $targetId, string $period): array {
    return match ($type) {
        'tlra' => tlra_blocks((int)$targetId, $period),
        'summary' => summary_blocks((string)$targetId, $period),
        'comparison' => comparison_blocks($period),
        'supplier' => supplier_blocks((int)$targetId, $period),
        default => throw new InvalidArgumentException('Jenis laporan tidak dikenal'),
    };
}

function b(string $t, array $o = []): array { return $o + ['t' => $t]; }

function approval_block(): array {
    return [
        b('h2', ['text' => 'Lembar Persetujuan']),
        b('p', ['text' => 'Dokumen ini ditandatangani secara manual atau melalui proses approval internal.']),
        b('table', [
            'head' => ['Peran', 'Nama & Tanda Tangan', 'Tanggal'],
            'rows' => [
                ['Disusun oleh (Quality/QRM)', '', ''],
                ['Direview oleh (Logistik/Distribusi)', '', ''],
                ['Disetujui oleh (Quality Manager)', '', ''],
            ],
            'widths' => [3, 5, 2],
            'fills' => [],
        ]),
    ];
}

function lane_by_id(int $id): array {
    $l = one('SELECT l.*, s.name supplier_name FROM lanes l LEFT JOIN suppliers s ON s.id = l.supplier_id WHERE l.id = ?', [$id]);
    if (!$l) throw new InvalidArgumentException('Lane tidak ditemukan');
    return $l;
}

function latest_assessment(int $laneId): ?array {
    $a = one('SELECT * FROM assessments WHERE lane_id = ? ORDER BY version DESC, id DESC LIMIT 1', [$laneId]);
    if (!$a) return null;
    $a['scores'] = all(
        'SELECT rs.*, ri.name risk_name, ri.mitigation FROM risk_scores rs
         JOIN risk_items ri ON ri.id = rs.risk_item_id WHERE rs.assessment_id = ? ORDER BY rs.rpn DESC', [$a['id']]);
    $a['max_rpn'] = $a['scores'] ? (int)$a['scores'][0]['rpn'] : null;
    $a['category'] = $a['scores'] ? $a['scores'][0]['category'] : null;
    return $a;
}

function lane_events(int $laneId, int $months = 6): array {
    $from = (new DateTimeImmutable("first day of -$months months"))->format('Y-m-d');
    return all(
        'SELECT e.*, ri.name risk_name FROM events e LEFT JOIN risk_items ri ON ri.id = e.risk_item_id
         WHERE e.lane_id = ? AND e.deleted_at IS NULL AND e.event_date >= ? ORDER BY e.event_date DESC', [$laneId, $from]);
}

function lane_trend(int $laneId): array {
    $months = overall_monthly_deviation_labels();
    $counts = [];
    foreach ($months as $m) {
        $counts[] = (int)scalar("SELECT COUNT(*) FROM events WHERE lane_id=? AND type='deviation' AND deleted_at IS NULL AND substr(event_date,1,7)=?",
            [$laneId, $m['key']]);
    }
    return ['labels' => array_column($months, 'label'), 'counts' => $counts];
}

function overall_monthly_deviation_labels(): array {
    $out = [];
    $start = new DateTimeImmutable('first day of this month -5 months');
    for ($i = 0; $i < 6; $i++) {
        $d = $start->modify("+$i months");
        $out[] = ['key' => $d->format('Y-m'), 'label' => $d->format('M y')];
    }
    return $out;
}

function fmt_date(?string $d): string {
    if (!$d) return '-';
    try {
        return (new DateTimeImmutable($d))->format('d M Y');
    } catch (Throwable) { return $d; }
}

// ---------------------------------------------------------------- TLRA
function tlra_blocks(int $laneId, string $period): array {
    $l = lane_by_id($laneId);
    $a = latest_assessment($laneId);
    $evs = lane_events($laneId, 6);
    $trend = lane_trend($laneId);
    $tps = $l['cluster'] === 'cold'
        ? one('SELECT * FROM tps_units WHERE name = ? OR id IN (SELECT tps_id FROM assessments WHERE lane_id=? ORDER BY version DESC LIMIT 1) LIMIT 1', [$a['tps_label'] ?? '', $laneId])
        : null;
    $scores = $a['scores'] ?? [];
    $top = $scores[0] ?? null;
    $highCrit = array_values(array_filter($scores, fn($s) => in_array($s['category'], ['high', 'critical'], true)));
    $devs = array_values(array_filter($evs, fn($e) => $e['type'] === 'deviation'));
    $openDev = count(array_filter($devs, fn($e) => $e['status'] !== 'closed'));

    $blocks = [
        b('h1', ['text' => 'TLRA REPORT — Transportation Lane Risk Assessment']),
        b('table', [
            'head' => ['Informasi', 'Detail'],
            'rows' => [
                ['Lane', $l['name'] . ' (' . $l['code'] . ')'],
                ['Cluster', $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient'],
                ['Rute', $l['origin'] . ' → ' . $l['destination']],
                ['Moda / Lead time', $l['mode'] . ' / ' . $l['lead_time_hours'] . ' jam'],
                ['Carrier / Supplier', $l['carrier'] . ($l['supplier_name'] ? ' — ' . $l['supplier_name'] : '')],
                ['Periode', $period],
                ['Tanggal generasi', date('d M Y')],
                ['Penilai', $a ? ($a['assessed_by'] ? (one('SELECT name FROM users WHERE id=?', [$a['assessed_by']])['name'] ?? '-') : '-') : 'Belum dinilai'],
            ],
            'widths' => [1, 3],
        ]),
        b('h2', ['text' => '1. Ringkasan Eksekutif']),
        b('p', ['text' => sprintf(
            'Penilaian risiko lane %s menggunakan metodologi FMEA terhadap %d kategori risiko sesuai cluster %s. %s',
            $l['name'], count($scores), $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient',
            $top
                ? sprintf('Risiko tertinggi adalah "%s" dengan RPN %d (kategori %s). Terdapat %d risiko berkategori High/Critical yang memerlukan tindak lanjut mitigasi.',
                    $top['risk_name'], $top['rpn'], CAT_LABEL[$top['category']], count($highCrit))
                : 'Belum ada penilaian risiko tersimpan untuk lane ini.'
        )]),
        b('p', ['text' => sprintf(
            'Selama 6 bulan terakhir tercatat %d event (%d deviasi), dengan %d tindak lanjut deviasi yang masih berstatus open/in progress.',
            count($evs), count($devs), $openDev
        )]),
        b('h2', ['text' => '2. Profil Lane']),
        b('table', [
            'head' => ['Aspek', 'Keterangan'],
            'rows' => [
                ['Asal (origin)', (string)$l['origin']],
                ['Tujuan (destination)', (string)$l['destination']],
                ['Jarak', $l['distance_km'] ? $l['distance_km'] . ' km' : '-'],
                ['Durasi perjalanan', $l['lead_time_hours'] . ' jam'],
                ['Frekuensi pengiriman', (string)$l['frequency']],
                ['Rentang suhu', (string)$l['temp_range']],
                ['Jenis kemasan / TPS', (string)($l['packaging_type'] ?: ($l['cluster'] === 'cold' ? '-' : '-'))],
                ['Data logger', !empty($l['data_logger']) ? 'Tersedia' : 'Tidak tersedia'],
                ['Durasi maks. cold chain break', $l['max_break_minutes'] !== null ? $l['max_break_minutes'] . ' menit' : '-'],
                ['Faktor musiman/geografis', (string)($l['season_notes'] ?: '-')],
                ['Status lane', $l['status'] === 'active' ? 'Aktif' : 'Nonaktif'],
            ],
            'widths' => [1, 3],
        ]),
        b('h2', ['text' => '3. Status Kualifikasi TPS']),
    ];

    if ($tps) {
        $st = tps_status($tps['requal_due']);
        $blocks[] = b('table', [
            'head' => ['Unit TPS', 'Jenis', 'Hold time', 'Supplier', 'Dok. kualifikasi', 'Re-kualifikasi', 'Status', 'Success rate'],
            'rows' => [[
                $tps['name'], $tps['type'], (string)$tps['hold_time'],
                (string)(one('SELECT name FROM suppliers WHERE id=?', [$tps['supplier_id']])['name'] ?? '-'),
                (string)$tps['qual_doc'], fmt_date($tps['requal_due']), tps_status_label($st),
                (($r = tps_success_rate((int)$tps['id'])) !== null) ? $r . '%' : '-',
            ]],
            'widths' => [3, 2, 2, 3, 2, 2, 2, 1],
            'fills' => [$st === 'expired' ? CAT_FILL['critical'] : ($st === 'due' ? CAT_FILL['medium'] : CAT_FILL['low'])],
        ]);
    } else {
        $blocks[] = b('p', ['text' => $l['cluster'] === 'cold'
            ? 'Unit TPS belum terdaftar untuk lane ini.'
            : 'Lane Ambient menggunakan jenis kemasan: ' . ($l['packaging_type'] ?: '-') . '. Kualifikasi TPS tidak berlaku untuk cluster ini.']);
    }

    $blocks[] = b('h2', ['text' => '4. Risk Register (FMEA)']);
    if ($scores) {
        $rows = [];
        $fills = [];
        foreach ($scores as $i => $s) {
            $rows[] = [$s['risk_name'], $s['s'], $s['o'], $s['d'], $s['rpn'], CAT_LABEL[$s['category']], $s['response']];
            $fills[$i] = CAT_FILL[$s['category']];
        }
        $blocks[] = b('table', [
            'head' => ['Risiko', 'S', 'O', 'D', 'RPN', 'Kategori', 'Respons risiko'],
            'rows' => $rows, 'widths' => [5, 1, 1, 1, 1, 2, 4], 'fills' => $fills,
        ]);
        $blocks[] = b('h3', ['text' => 'Rationale penilaian']);
        $rrows = [];
        foreach ($scores as $s) {
            $rrows[] = [$s['risk_name'], $s['rat_s'], $s['rat_o'], $s['rat_d']];
        }
        $blocks[] = b('table', [
            'head' => ['Risiko', 'Rationale Severity', 'Rationale Occurrence', 'Rationale Detection'],
            'rows' => $rrows, 'widths' => [3, 3, 3, 3],
        ]);
    } else {
        $blocks[] = b('p', ['text' => 'Belum ada penilaian FMEA untuk lane ini. Lakukan penilaian pada modul Risk Assessment.']);
    }

    $blocks[] = b('h3', ['text' => 'Heatmap posisi lane (RPN tertinggi tiap lane)']);
    $hmRows = []; $hmFills = [];
    foreach (lane_risk_overview('all') as $i => $ol) {
        $hmRows[] = [$ol['code'], $ol['name'], $ol['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient',
            $ol['max_rpn'] !== null ? (string)(int)$ol['max_rpn'] : 'belum dinilai',
            $ol['category'] ? CAT_LABEL[$ol['category']] : '-',
            (int)$ol['id'] === $laneId ? 'lane ini' : ''];
        $hmFills[$i] = $ol['category'] ? CAT_FILL[$ol['category']] : 'EEEEEE';
    }
    $blocks[] = b('table', [
        'head' => ['Kode', 'Lane', 'Cluster', 'RPN tertinggi', 'Kategori', ''],
        'rows' => $hmRows, 'widths' => [1, 5, 2, 2, 2, 1], 'fills' => $hmFills,
    ]);

    $blocks[] = b('h2', ['text' => '5. Historical Performance']);
    $blocks[] = b('p', ['text' => 'Tren jumlah deviasi per bulan (6 bulan terakhir): '
        . implode(' → ', array_map(fn($l2, $c) => "$l2=$c", $trend['labels'], $trend['counts']))
        . '. Sinyal: ' . trend_signal($trend['counts'])['text'] . '.']);

    $erows = [];
    foreach (array_slice($evs, 0, 40) as $e) {
        $erows[] = [fmt_date($e['event_date']), $e['type'] === 'deviation' ? 'Deviation' : 'Normal',
            (string)($e['risk_name'] ?: '-'), (string)($e['severity'] ?: '-'), (string)($e['status'] ?: '-'), (string)($e['pic'] ?: '-')];
    }
    $blocks[] = b('table', [
        'head' => ['Tanggal', 'Tipe', 'Kategori', 'Keparahan', 'Status tindak lanjut', 'PIC'],
        'rows' => $erows ?: [['-', 'Tidak ada data event untuk lane ini', '', '', '', '']],
        'widths' => [2, 2, 4, 2, 2, 2],
    ]);

    $blocks[] = b('h2', ['text' => '6. Rekomendasi Mitigasi (High/Critical)']);
    if ($highCrit) {
        foreach ($highCrit as $s) {
            $blocks[] = b('ul', ['items' => [
                sprintf('%s — RPN %d (%s). Respons: %s. Mitigasi: %s',
                    $s['risk_name'], $s['rpn'], CAT_LABEL[$s['category']], $s['response'],
                    $s['mitigation'] ?: ($s['response'] === 'Tolak lane (reject)' ? 'Lane ditolak untuk digunakan.' : 'Susun rencana mitigasi bersama Quality.')),
            ]]);
        }
    } else {
        $blocks[] = b('p', ['text' => 'Tidak ada risiko kategori High/Critical pada penilaian ini.']);
    }

    $blocks[] = b('h2', ['text' => '7. Kesimpulan & Jadwal Review']);
    $blocks[] = b('p', ['text' => sprintf(
        'Berdasarkan penilaian FMEA, lane %s berada pada kategori risiko %s. TLRA Report ini direview ulang tiap 3 tahun sejak tanggal pembuatan, atau lebih cepat bila terjadi perubahan signifikan pada profil risiko (perubahan carrier, rute, produk, atau lonjakan deviasi).',
        $l['name'], $a ? CAT_LABEL[$a['category']] : 'belum dinilai')]);
    return array_merge($blocks, approval_block());
}

// ------------------------------------------------------------- Summary
function summary_blocks(string $cluster, string $period): array {
    $cluster = in_array($cluster, ['cold', 'ambient', 'all'], true) ? $cluster : 'all';
    $lanes = lane_risk_overview($cluster);
    $counts = category_counts($lanes);
    $devs = (int)scalar("SELECT COUNT(*) FROM events WHERE type='deviation' AND deleted_at IS NULL"
        . ($cluster === 'all' ? '' : ' AND lane_id IN (SELECT id FROM lanes WHERE cluster = ?)'),
        $cluster === 'all' ? [] : [$cluster]);

    $blocks = [
        b('h1', ['text' => 'SUMMARY REPORT — Konsolidasi Risiko Lane']),
        b('table', [
            'head' => ['Informasi', 'Detail'],
            'rows' => [
                ['Cluster', $cluster === 'all' ? 'Semua cluster' : ($cluster === 'cold' ? 'Cold Chain' : 'Ambient')],
                ['Periode', $period],
                ['Tanggal generasi', date('d M Y')],
                ['Jumlah lane', (string)count($lanes)],
            ],
            'widths' => [1, 3],
        ]),
        b('h2', ['text' => '1. Ringkasan Eksekutif']),
        b('p', ['text' => sprintf(
            'Terdapat %d lane aktif pada lingkup laporan ini, dengan distribusi kategori risiko: Low %d, Medium %d, High %d, Critical %d. Total deviasi tercatat pada periode 6 bulan terakhir sebanyak %d kejadian.',
            count($lanes), $counts['low'], $counts['medium'], $counts['high'], $counts['critical'], $devs)]),
        b('h2', ['text' => '2. Rekapitulasi per Lane']),
    ];
    $rows = []; $fills = [];
    foreach ($lanes as $i => $l) {
        $rows[] = [$l['code'], $l['name'], $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient',
            $l['max_rpn'] ?? '-', $l['category'] ? CAT_LABEL[$l['category']] : 'Belum dinilai',
            $l['assessed_at'] ? fmt_date($l['assessed_at']) : '-'];
        if ($l['category']) $fills[$i] = CAT_FILL[$l['category']];
    }
    $blocks[] = b('table', [
        'head' => ['Kode', 'Lane', 'Cluster', 'RPN tertinggi', 'Kategori', 'Penilaian terakhir'],
        'rows' => $rows ?: [['-', 'Tidak ada lane', '', '', '', '']],
        'widths' => [1, 5, 2, 2, 2, 2], 'fills' => $fills,
    ]);
    $blocks[] = b('h2', ['text' => '3. Kategori Risiko']);
    $blocks[] = b('table', [
        'head' => ['Kategori', 'Jumlah lane', 'Tindakan umum'],
        'rows' => [
            ['Low', (string)$counts['low'], 'Terima risiko, monitor rutin'],
            ['Medium', (string)$counts['medium'], 'Mitigasi standar, review berkala'],
            ['High', (string)$counts['high'], 'Mitigasi wajib, lane verification'],
            ['Critical', (string)$counts['critical'], 'Reroute/ganti TPS-supplier atau tolak lane'],
        ],
        'widths' => [2, 1, 5],
        'fills' => [CAT_FILL['low'], CAT_FILL['medium'], CAT_FILL['high'], CAT_FILL['critical']],
    ]);
    $blocks[] = b('h2', ['text' => '4. Kesimpulan & Jadwal Review']);
    $blocks[] = b('p', ['text' => 'Summary Report direview ulang tiap 12 bulan. Lane berkategori High/Critical wajib masuk program mitigasi dan penilaian ulang lebih sering.']);
    return array_merge($blocks, approval_block());
}

// ---------------------------------------------------------- Comparison
function comparison_blocks(string $period): array {
    $stats = [];
    foreach (['cold', 'ambient'] as $cl) {
        $lanes = lane_risk_overview($cl);
        $devs = (int)scalar("SELECT COUNT(*) FROM events WHERE type='deviation' AND deleted_at IS NULL AND lane_id IN (SELECT id FROM lanes WHERE cluster=?)", [$cl]);
        $rpn = array_filter(array_column($lanes, 'max_rpn'));
        $stats[$cl] = [
            'lanes' => count($lanes),
            'avg_rpn' => $rpn ? round(array_sum($rpn) / count($rpn), 1) : '-',
            'critical' => count(array_filter($lanes, fn($l) => $l['category'] === 'critical')),
            'high' => count(array_filter($lanes, fn($l) => $l['category'] === 'high')),
            'devs' => $devs,
            'assessed' => count(array_filter($lanes, fn($l) => $l['category'])),
        ];
    }
    $blocks = [
        b('h1', ['text' => 'CLUSTER COMPARISON REPORT — Cold Chain vs Ambient']),
        b('table', [
            'head' => ['Informasi', 'Detail'],
            'rows' => [['Periode', $period], ['Tanggal generasi', date('d M Y')]],
            'widths' => [1, 3],
        ]),
        b('h2', ['text' => '1. Ringkasan Eksekutif']),
        b('p', ['text' => sprintf(
            'Rata-rata RPN cluster Cold Chain adalah %s dibandingkan %s pada cluster Ambient. Jumlah lane berkategori Critical: Cold Chain %d, Ambient %d. Deviasi 6 bulan terakhir: Cold Chain %d, Ambient %d.',
            $stats['cold']['avg_rpn'], $stats['ambient']['avg_rpn'],
            $stats['cold']['critical'], $stats['ambient']['critical'],
            $stats['cold']['devs'], $stats['ambient']['devs'])]),
        b('h2', ['text' => '2. Perbandingan Metrik']),
        b('table', [
            'head' => ['Metrik', 'Cold Chain', 'Ambient'],
            'rows' => [
                ['Jumlah lane', $stats['cold']['lanes'], $stats['ambient']['lanes']],
                ['Lane sudah dinilai', $stats['cold']['assessed'], $stats['ambient']['assessed']],
                ['Rata-rata RPN tertinggi per lane', $stats['cold']['avg_rpn'], $stats['ambient']['avg_rpn']],
                ['Lane kategori High', $stats['cold']['high'], $stats['ambient']['high']],
                ['Lane kategori Critical', $stats['cold']['critical'], $stats['ambient']['critical']],
                ['Deviasi 6 bulan terakhir', $stats['cold']['devs'], $stats['ambient']['devs']],
            ],
            'widths' => [4, 2, 2],
        ]),
        b('h2', ['text' => '3. Rekomendasi']),
        b('p', ['text' => 'Prioritas penanganan diberikan pada cluster dengan rata-rata RPN dan jumlah lane High/Critical lebih tinggi. Cluster Cold Chain wajib mempertahankan validasi TPS, data logger, dan verifikasi lane secara berkala.']),
        b('h2', ['text' => '4. Kesimpulan & Jadwal Review']),
        b('p', ['text' => 'Cluster Comparison Report direview ulang tiap 12 bulan atau saat terdapat perubahan portofolio lane.']),
    ];
    return array_merge($blocks, approval_block());
}

// ------------------------------------------------------------- Supplier
function supplier_blocks(int $supplierId, string $period): array {
    $s = one('SELECT * FROM suppliers WHERE id = ?', [$supplierId]);
    if (!$s) throw new InvalidArgumentException('Supplier tidak ditemukan');
    $perf = null;
    foreach (supplier_performance() as $p) {
        if ((int)$p['supplier']['id'] === $supplierId) { $perf = $p; break; }
    }
    $units = all('SELECT * FROM tps_units WHERE supplier_id = ? AND active = 1 ORDER BY name', [$supplierId]);
    $lanes = all('SELECT * FROM lanes WHERE supplier_id = ? AND status = ? ORDER BY code', [$supplierId, 'active']);

    $unitRows = []; $unitFills = [];
    foreach ($units as $i => $u) {
        $st = tps_status($u['requal_due']);
        $rate = tps_success_rate((int)$u['id']);
        $unitRows[] = [$u['name'], $u['type'], (string)$u['hold_time'], $rate === null ? '-' : $rate . '%',
            fmt_date($u['requal_due']), tps_status_label($st)];
        $unitFills[$i] = $st === 'expired' ? CAT_FILL['critical'] : ($st === 'due' ? CAT_FILL['medium'] : CAT_FILL['low']);
    }
    $laneRows = [];
    foreach ($lanes as $l) {
        $ov = one("SELECT MAX(rs.rpn) max_rpn FROM assessments a JOIN risk_scores rs ON rs.assessment_id=a.id WHERE a.lane_id=?", [$l['id']]);
        $laneRows[] = [$l['code'], $l['name'], $l['cluster'] === 'cold' ? 'Cold Chain' : 'Ambient',
            $ov['max_rpn'] ?? '-'];
    }
    $rank = $perf['rank'] ?? '-';
    $recommendation = match ($rank) {
        'A' => 'Performance supplier berada pada kategori baik (Rank A). Pertahankan monitoring rutin dan lanjutkan kerja sama.',
        'B' => 'Performance supplier perlu ditingkatkan (Rank B). Disarankan corrective action bersama supplier dan evaluasi ulang unit TPS dengan success rate di bawah 90%.',
        'C' => 'Performance supplier berada pada kategori kurang (Rank C). Diperlukan rencana perbaikan formal, audit supplier, dan pertimbangan penggantian unit TPS/lane.',
        default => 'Belum ada data success rate unit TPS untuk supplier ini; klasifikasi ranking belum dapat ditentukan.',
    };

    $blocks = [
        b('h1', ['text' => 'PERFORMANCE SUPPLIER REPORT']),
        b('table', [
            'head' => ['Informasi', 'Detail'],
            'rows' => [
                ['Supplier', $s['name']],
                ['Kontak', (string)($s['contact'] ?: '-')],
                ['Periode', $period],
                ['Tanggal generasi', date('d M Y')],
                ['Ranking', $rank],
            ],
            'widths' => [1, 3],
        ]),
        b('h2', ['text' => '1. Ringkasan Eksekutif']),
        b('p', ['text' => sprintf(
            'Supplier ini mengelola %d unit TPS dengan rata-rata success rate %s dan %d deviasi yang masih berstatus open/in progress, serta melayani %d lane aktif. Berdasarkan evaluasi, supplier berada pada Rank %s.',
            count($units), $perf['avg'] === null ? '-' : $perf['avg'] . '%', $perf['open_dev'] ?? 0, count($lanes), $rank)]),
        b('h2', ['text' => '2. Unit TPS yang Dikelola']),
        b('table', [
            'head' => ['Nama TPS', 'Jenis', 'Hold time', 'Success rate', 'Jatuh tempo re-kualifikasi', 'Status'],
            'rows' => $unitRows ?: [['Tidak ada unit TPS terdaftar', '', '', '', '', '']],
            'widths' => [3, 2, 2, 2, 2, 2], 'fills' => $unitFills,
        ]),
        b('h2', ['text' => '3. Lane yang Dilayani']),
        b('table', [
            'head' => ['Kode', 'Lane', 'Cluster', 'RPN tertinggi'],
            'rows' => $laneRows ?: [['Tidak ada lane tercatat untuk supplier ini', '', '', '']],
            'widths' => [1, 5, 2, 2],
        ]),
        b('h2', ['text' => '4. Rekomendasi']),
        b('p', ['text' => $recommendation]),
        b('h2', ['text' => '5. Kesimpulan & Jadwal Review']),
        b('p', ['text' => 'Performance Supplier Report direview ulang tiap 6 bulan sejak tanggal pembuatan.']),
    ];
    return array_merge($blocks, approval_block());
}

// ---------------------------------------------------------------- render
function block_fills(array $blk): array { return $blk['fills'] ?? []; }

function render_docx_blocks(array $blocks, string $path): void {
    $d = new Docx();
    foreach ($blocks as $blk) {
        switch ($blk['t']) {
            case 'h1': $d->h1($blk['text']); break;
            case 'h2': $d->h2($blk['text']); break;
            case 'h3': $d->h3($blk['text']); break;
            case 'p': $d->para($blk['text']); break;
            case 'ul':
                foreach ($blk['items'] as $it) $d->bullet($it);
                break;
            case 'pagebreak': $d->pageBreak(); break;
            case 'table': $d->table($blk['head'], $blk['rows'], $blk['widths'] ?? [], block_fills($blk)); break;
        }
    }
    $d->save($path);
}

function render_pdf_blocks(array $blocks, string $path): void {
    require_once APP_ROOT . '/src/lib/fpdf.php';
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetTitle('Q-Transport Report', true);
    $pdf->AddPage();
    $pdf->SetLeftMargin(10);

    foreach ($blocks as $blk) {
        switch ($blk['t']) {
            case 'h1':
                $pdf->Ln(2);
                $pdf->SetTextColor(31, 56, 100);
                $pdf->SetFont('Arial', 'B', 17);
                $pdf->MultiCell(0, 8, pdf_text($blk['text']));
                $pdf->SetDrawColor(31, 56, 100);
                $pdf->SetLineWidth(0.6);
                $pdf->Line(10, $pdf->GetY() + 1, 200, $pdf->GetY() + 1);
                $pdf->Ln(5);
                break;
            case 'h2':
                if ($pdf->GetY() > 245) $pdf->AddPage();
                $pdf->Ln(4);
                $pdf->SetTextColor(31, 56, 100);
                $pdf->SetFont('Arial', 'B', 12);
                $pdf->MultiCell(0, 6.5, pdf_text($blk['text']));
                $pdf->Ln(1.5);
                break;
            case 'h3':
                $pdf->Ln(2.5);
                $pdf->SetTextColor(46, 90, 128);
                $pdf->SetFont('Arial', 'B', 10.5);
                $pdf->MultiCell(0, 5.5, pdf_text($blk['text']));
                $pdf->Ln(1);
                break;
            case 'p':
                $pdf->SetTextColor(27, 38, 48);
                $pdf->SetFont('Arial', '', 10);
                $pdf->MultiCell(0, 5, pdf_text($blk['text']));
                $pdf->Ln(2);
                break;
            case 'ul':
                $pdf->SetFont('Arial', '', 10);
                $pdf->SetTextColor(27, 38, 48);
                foreach ($blk['items'] as $it) {
                    $pdf->SetX(12);
                    $pdf->MultiCell(0, 5, '-  ' . pdf_text($it));
                }
                $pdf->Ln(2);
                break;
            case 'pagebreak':
                $pdf->AddPage();
                break;
            case 'table':
                pdf_table($pdf, $blk);
                break;
        }
    }
    $pdf->Output('F', $path);
}

function pdf_table(FPDF $pdf, array $blk): void {
    $head = array_values($blk['head']);
    $widths = $blk['widths'] ?? [];
    if (!$widths) $widths = array_fill(0, count($head), 1);
    $total = array_sum($widths);
    $pageW = $pdf->GetPageWidth() - 20;
    $cols = array_map(fn($w) => $pageW * $w / $total, $widths);
    $fills = $blk['fills'] ?? [];
    $pad = 1.2;
    $rowHeight = function (array $cells, bool $bold) use ($pdf, $cols, $pad): float {
        $pdf->SetFont('Arial', $bold ? 'B' : '', 8);
        $max = 1;
        foreach (array_values($cells) as $i => $c) {
            $w = $cols[$i] ?? 20;
            $max = max($max, count(pdf_wrap($pdf, (string)$c, max(4, $w - 2 * $pad))));
        }
        return $max * (PDF_ROW_LH) + 2 * $pad;
    };

    $drawRow = function (array $cells, float $h, bool $bold, ?array $fill) use ($pdf, $cols, $pad): void {
        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();
        $x = $x0;
        foreach (array_values($cells) as $i => $c) {
            $w = $cols[$i] ?? 20;
            if ($fill) {
                $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
                $pdf->Rect($x, $y0, $w, $h, 'F');
            }
            $pdf->SetDrawColor(183, 193, 200);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($x, $y0, $w, $h);
            $pdf->SetFont('Arial', $bold ? 'B' : '', 8);
            $pdf->SetTextColor(255, 255, 255);
            if (!$bold) $pdf->SetTextColor(27, 38, 48);
            $pdf->SetXY($x + $pad, $y0 + $pad);
            $pdf->MultiCell($w - 2 * $pad, PDF_ROW_LH, pdf_text((string)$c), 0, 'L', false);
            $x += $w;
        }
        $pdf->SetXY($x0, $y0 + $h);
    };

    $fits = fn(float $h): bool => $pdf->GetY() + $h <= $pdf->GetPageHeight() - 16;

    $hh = $rowHeight($head, true);
    if (!$fits($hh)) $pdf->AddPage();
    $drawRow($head, $hh, true, [31, 56, 100]);

    foreach (array_values($blk['rows']) as $i => $row) {
        $row = array_values($row);
        $h = $rowHeight($row, false);
        if (!$fits($h)) {
            $pdf->AddPage();
            $hh = $rowHeight($head, true);
            $drawRow($head, $hh, true, [31, 56, 100]);
        }
        $fill = isset($fills[$i]) ? pdf_hex($fills[$i]) : null;
        $drawRow($row, $h, false, $fill);
    }
    $pdf->Ln(3);
}

function pdf_hex(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** FPDF core fonts are latin-1: normalise the few Unicode characters used in the UI. */
function pdf_text(string $s): string {
    $map = [
        "\u{2014}" => ' - ', "\u{2013}" => '-', "\u{2192}" => '->', "\u{00D7}" => 'x',
        "\u{2264}" => '<=', "\u{2265}" => '>=', "\u{2022}" => '-', "\u{2019}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{2026}" => '...', "\u{00A0}" => ' ',
        "\u{2260}" => '!=', "\u{221E}" => 'inf', "\u{2018}" => "'",
    ];
    $s = strtr($s, $map);
    $s = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    if ($s === false) $s = preg_replace('/[^\x20-\x7E]/', '?', (string)$s);
    return $s;
}

function pdf_wrap(FPDF $pdf, string $text, float $width): array {
    $text = trim($text);
    if ($text === '') return [''];
    $out = [];
    foreach (preg_split('/\r?\n/', $text) as $para) {
        $words = preg_split('/\s+/', $para) ?: [''];
        $line = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if ($pdf->GetStringWidth($try) <= $width || $line === '') {
                $line = $try;
            } else {
                $out[] = $line;
                $line = $w;
            }
        }
        $out[] = $line;
    }
    return $out;
}

/** Generate the file, store it, record it in report_history. Returns the row id. */
function generate_report(string $type, $targetId, string $format, string $period, ?int $userId): int {
    if (!isset(REPORT_TYPES[$type])) throw new InvalidArgumentException('Jenis laporan tidak diketahui');
    if (!in_array($format, ['pdf', 'docx'], true)) throw new InvalidArgumentException('Format tidak didukung');

    $meta = REPORT_TYPES[$type];
    $targetLabel = match ($meta['target']) {
        'lane' => lane_by_id((int)$targetId)['name'],
        'supplier' => (one('SELECT name FROM suppliers WHERE id=?', [(int)$targetId])['name'] ?? 'supplier'),
        'cluster' => $targetId === 'cold' ? 'Cold Chain' : ($targetId === 'ambient' ? 'Ambient' : 'Semua cluster'),
        default => 'Semua lane',
    };
    $title = $meta['label'] . ' — ' . $targetLabel . ' — ' . $period;

    $blocks = report_blocks($type, $targetId, $period);

    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $type . '-' . $targetLabel . '-' . $period);
    $fname = $slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $format;
    $path = rtrim(config('reports_path'), '/') . '/' . $fname;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);

    if ($format === 'docx') render_docx_blocks($blocks, $path);
    else render_pdf_blocks($blocks, $path);

    $due = (new DateTimeImmutable('today'))->modify('+' . $meta['months'] . ' months')->format('Y-m-d');
    q('INSERT INTO reports(type,format,target_type,target_id,title,period,file_path,created_by,review_due) VALUES(?,?,?,?,?,?,?,?,?)',
        [$type, $format, $meta['target'], is_numeric($targetId) ? (int)$targetId : null, $title, $period,
            $fname, $userId, $due]);

    $id = (int)db()->lastInsertId();
    audit_log('report', (string)$id, 'generate', null,
        ['type' => $type, 'format' => $format, 'target' => $targetLabel, 'period' => $period, 'file' => $fname]);
    return $id;
}
