<?php

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = config();
    $dir = dirname($cfg['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $fresh = !is_file($cfg['db_path']);
    $pdo = new PDO('sqlite:' . $cfg['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    if ($fresh) install($pdo); else migrate($pdo);
    return $pdo;
}

/** Add columns introduced after the first release to an existing database. */
function migrate(PDO $pdo): void {
    $cols = [
        ['tps_units', 'approval_status', "TEXT NOT NULL DEFAULT 'pending'"],
        ['tps_units', 'approved_by', 'INTEGER'],
        ['tps_units', 'approved_at', 'TEXT'],
        ['assessments', 'approval_status', "TEXT NOT NULL DEFAULT 'pending'"],
        ['assessments', 'approved_by', 'INTEGER'],
        ['assessments', 'approved_at', 'TEXT'],
    ];
    foreach ($cols as [$table, $col, $def]) {
        foreach ($pdo->query("PRAGMA table_info($table)") as $c) {
            if ($c['name'] === $col) continue 2;
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}

function scalar(string $sql, array $params = []) {
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function install(PDO $pdo): void {
    $pdo->exec(SCHEMA_SQL);
    seed($pdo);
}

const SCHEMA_SQL = <<<'SQL'
CREATE TABLE users(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN('admin','quality','logistics','management','auditor')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE suppliers(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE, contact TEXT, phone TEXT, email TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE lanes(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, origin TEXT, destination TEXT,
  cluster TEXT NOT NULL CHECK(cluster IN('cold','ambient')),
  mode TEXT, distance_km REAL, lead_time_hours REAL, frequency TEXT, carrier TEXT,
  supplier_id INTEGER REFERENCES suppliers(id),
  temp_range TEXT, packaging_type TEXT, data_logger INTEGER NOT NULL DEFAULT 0, max_break_minutes REAL,
  season_notes TEXT,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN('active','inactive')),
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE products(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, cluster TEXT NOT NULL CHECK(cluster IN('cold','ambient')), active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE tps_units(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
  type TEXT NOT NULL CHECK(type IN('active','passive','packaging')),
  hold_time TEXT, temp_range TEXT, product_cats TEXT DEFAULT '',
  supplier_id INTEGER REFERENCES suppliers(id),
  qual_date TEXT, qual_doc TEXT, requal_due TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  approval_status TEXT NOT NULL DEFAULT 'pending',
  approved_by INTEGER REFERENCES users(id), approved_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE risk_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, scope TEXT NOT NULL CHECK(scope IN('cold','ambient','both')),
  mitigation TEXT DEFAULT '', temp_related INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE events(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_date TEXT NOT NULL, lane_id INTEGER NOT NULL REFERENCES lanes(id),
  type TEXT NOT NULL CHECK(type IN('normal','deviation')),
  risk_item_id INTEGER REFERENCES risk_items(id),
  severity TEXT CHECK(severity IN('minor','major','critical')),
  duration TEXT, tps_id INTEGER REFERENCES tps_units(id), product_id INTEGER REFERENCES products(id),
  root_cause TEXT, action_taken TEXT,
  status TEXT CHECK(status IN('open','in_progress','closed')) DEFAULT 'open',
  pic TEXT, deadline TEXT,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  deleted_at TEXT
);
CREATE INDEX idx_events_lane_date ON events(lane_id, event_date);
CREATE INDEX idx_events_date ON events(event_date);
CREATE TABLE assessments(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lane_id INTEGER NOT NULL REFERENCES lanes(id),
  tps_label TEXT NOT NULL, tps_id INTEGER REFERENCES tps_units(id),
  product_id INTEGER REFERENCES products(id),
  version INTEGER NOT NULL DEFAULT 1,
  notes TEXT,
  assessed_by INTEGER REFERENCES users(id),
  approval_status TEXT NOT NULL DEFAULT 'pending',
  approved_by INTEGER REFERENCES users(id), approved_at TEXT,
  assessed_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_assess_lane ON assessments(lane_id);
CREATE TABLE risk_scores(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  assessment_id INTEGER NOT NULL REFERENCES assessments(id) ON DELETE CASCADE,
  risk_item_id INTEGER NOT NULL REFERENCES risk_items(id),
  s INTEGER NOT NULL, o INTEGER NOT NULL, d INTEGER NOT NULL,
  rat_s TEXT NOT NULL, rat_o TEXT NOT NULL, rat_d TEXT NOT NULL,
  rpn INTEGER NOT NULL, category TEXT NOT NULL,
  response TEXT NOT NULL, mitigation TEXT DEFAULT ''
);
CREATE INDEX idx_scores_assess ON risk_scores(assessment_id);
CREATE TABLE reports(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL, format TEXT NOT NULL, target_type TEXT, target_id INTEGER,
  title TEXT NOT NULL, period TEXT, file_path TEXT NOT NULL,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  review_due TEXT NOT NULL
);
CREATE TABLE audit_log(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER, user_name TEXT, entity TEXT NOT NULL, entity_id TEXT,
  action TEXT NOT NULL, before_json TEXT, after_json TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
INSERT INTO settings(key,value) VALUES
 ('rpn_low_max','80'),('rpn_med_max','250'),('rpn_high_max','600'),
 ('rank_a_min','90'),('rank_b_min','75'),
 ('tps_due_days','60'),('report_due_days','30');
SQL;

function seed(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $ins = fn(string $sql, array $p) => (function () use ($sql, $p) {
            $st = db()->prepare($sql); $st->execute($p); return (int)db()->lastInsertId();
        })();

        // Users
        $users = [
            ['System Admin', 'admin@qtransport.local', 'admin123', 'admin'],
            ['Rina Rahmawati', 'quality@qtransport.local', 'quality123', 'quality'],
            ['Budi Santoso', 'logistik@qtransport.local', 'logistik123', 'logistics'],
            ['Direksi', 'manajemen@qtransport.local', 'manajemen123', 'management'],
            ['Auditor Eksternal', 'auditor@qtransport.local', 'auditor123', 'auditor'],
        ];
        foreach ($users as $u) {
            $ins('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)',
                [$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT), $u[3]]);
        }

        // Suppliers
        $sup = [];
        foreach ([['ColdLog Indonesia', 'Agus Wijaya', '021-555-0101'],
                  ['FreshChain Sulawesi', 'Maya Lestari', '0411-555-0202'],
                  ['Nusantara Cargo', 'Hendra Gunawan', '021-555-0303']] as $s) {
            $sup[$s[0]] = $ins('INSERT INTO suppliers(name,contact,phone) VALUES(?,?,?)', $s);
        }

        // Products
        $prod = [
            'Vaksin A' => $ins('INSERT INTO products(name,cluster) VALUES(?,?)', ['Vaksin A', 'cold']),
            'Insulin B' => $ins('INSERT INTO products(name,cluster) VALUES(?,?)', ['Insulin B', 'cold']),
            'Tablet generik C' => $ins('INSERT INTO products(name,cluster) VALUES(?,?)', ['Tablet generik C', 'ambient']),
            'Suplemen D' => $ins('INSERT INTO products(name,cluster) VALUES(?,?)', ['Suplemen D', 'ambient']),
        ];

        // TPS units
        $tps = [];
        $tpsSeed = [
            ['TPS-001', 'Active Reefer X', 'active', '72 jam @2-8°C', '2-8°C', 'A1,A2', 'ColdLog Indonesia', '2026-01-15', 'QUAL-TPS-001', '2027-01-15'],
            ['TPS-002', 'Passive Box 48H', 'passive', '48 jam @2-8°C', '2-8°C', 'A2,B', 'ColdLog Indonesia', '2026-03-10', 'QUAL-TPS-002', '2027-03-10'],
            ['TPS-003', 'Active Reefer Y', 'active', '96 jam @2-8°C', '2-8°C', 'A1', 'FreshChain Sulawesi', '2025-11-02', 'QUAL-TPS-003', '2026-11-02'],
            ['TPS-004', 'Passive Box 72H', 'passive', '72 jam @2-8°C', '2-8°C', 'B,C', 'FreshChain Sulawesi', '2026-06-20', 'QUAL-TPS-004', '2027-06-20'],
        ];
        foreach ($tpsSeed as $t) {
            $tps[$t[1]] = $ins(
                'INSERT INTO tps_units(code,name,type,hold_time,temp_range,product_cats,supplier_id,qual_date,qual_doc,requal_due) VALUES(?,?,?,?,?,?,?,?,?,?)',
                [$t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $sup[$t[6]], $t[7], $t[8], $t[9]]
            );
        }

        // Lanes
        $lanes = [];
        $laneSeed = [
            ['L001', 'Jakarta DC - Surabaya Hospital', 'Jakarta', 'Surabaya', 'cold', 'Darat (truk reefer)', 670, 18, 'Harian', 'PT Trans Nusantara', 'ColdLog Indonesia', '2-8°C', '', 1, 60, 'Rawan macet tol Cipali'],
            ['L002', 'Jakarta DC - Bandung Clinic', 'Jakarta', 'Bandung', 'cold', 'Darat (truk berpendingin)', 150, 6, '3x minggu', 'PT Anggarah', 'ColdLog Indonesia', '2-8°C', '', 1, 30, 'Rawan banjir bandang'],
            ['L003', 'Semarang WH - Yogyakarta Pharmacy', 'Semarang', 'Yogyakarta', 'ambient', 'Darat (truk box)', 130, 8, '2x minggu', 'PT Kargo Sentosa', 'Nusantara Cargo', '15-30°C', 'Dus standar + pallet', 0, 240, 'Rawan macet'],
            ['L004', 'Makassar DC - Kendari Hospital', 'Makassar', 'Kendari', 'cold', 'Udara + darat', 900, 30, 'Mingguan', 'PT Laut Kargo', 'FreshChain Sulawesi', '2-8°C', '', 1, 120, 'Cuaca ekstrem musim hujan'],
            ['L005', 'Medan DC - Pekanbaru Clinic', 'Medan', 'Pekanbaru', 'ambient', 'Darat (truk box)', 400, 14, '2x minggu', 'PT Sumatra Cargo', 'Nusantara Cargo', '15-30°C', 'Dus standar + pallet', 0, 240, 'Jalur rawan banjir'],
            ['L006', 'Surabaya DC - Denpasar Hospital', 'Surabaya', 'Denpasar', 'cold', 'Laut + darat', 500, 40, 'Mingguan', 'PT Bahari Kargo', 'FreshChain Sulawesi', '2-8°C', '', 1, 180, 'Penyeberangan rawan gelombang'],
        ];
        foreach ($laneSeed as $l) {
            $lanes[$l[1]] = $ins(
                'INSERT INTO lanes(code,name,origin,destination,cluster,mode,distance_km,lead_time_hours,frequency,carrier,supplier_id,temp_range,packaging_type,data_logger,max_break_minutes,season_notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $l[6], $l[7], $l[8], $l[9], $sup[$l[10]], $l[11], $l[12], $l[13], $l[14], $l[15]]
            );
        }

        // Risk items
        $risks = [
            ['Temperature excursion', 'cold', 'TPS tervalidasi dengan hold time > lead time; pasang data logger', 1],
            ['Kegagalan reefer/TPS', 'cold', 'Rekualifikasi unit TPS; siapkan unit cadangan', 1],
            ['Kapasitas cold storage transit tidak cukup', 'cold', 'Koordinasi kapasitas TPS transit sebelum jadwal kirim', 0],
            ['Kerusakan fisik/guncangan', 'both', 'Perkuat packaging; SOP handling hati-hati', 0],
            ['Kelembapan berlebih', 'both', 'Tambahkan desiccant / moisture barrier', 0],
            ['Delay/penahanan bea cukai', 'both', 'Siapkan dokumen lengkap di awal; reroute bila perlu', 0],
            ['Kesalahan dokumentasi/misrouting', 'both', 'Verifikasi dokumen & label sebelum kirim', 0],
            ['Cuaca ekstrem', 'both', 'Pantau prakiraan cuaca; reroute musiman', 0],
            ['Kontaminasi silang (multi-drop)', 'both', 'Segregasi cargo; kendaraan dedicated bila perlu', 0],
            ['Keamanan/pencurian/tampering', 'both', 'Gunakan seal & tracking; verifikasi carrier', 0],
        ];
        $riskId = [];
        foreach ($risks as $i => $r) {
            $riskId[$r[0]] = $ins('INSERT INTO risk_items(name,scope,mitigation,temp_related,sort_order) VALUES(?,?,?,?,?)',
                [$r[0], $r[1], $r[2], $r[3], $i + 1]);
        }

        // Events: 6 months of history, deterministic pattern so trending shows signal
        $qualityId = 2; $logistikId = 3;
        $laneNames = array_keys($lanes);
        $day = new DateTimeImmutable('first day of this month -5 months');
        $n = 0;
        for ($m = 0; $m < 6; $m++) {
            $first = $day->modify("+$m months");
            $days = (int)$first->format('t');
            for ($d = 1; $d <= $days; $d += 7) {
              $date = $first->modify("+" . ($d - 1) . " days")->format('Y-m-d');
              foreach ($laneNames as $laneName) {
                $laneId = $lanes[$laneName];
                $laneCluster = one('SELECT cluster FROM lanes WHERE id = ?', [$laneId])['cluster'];
                $isCold = $laneCluster === 'cold';
                // rising deviation trend on two lanes, flat elsewhere
                $rise = in_array($laneName, ['Makassar DC - Kendari Hospital', 'Surabaya DC - Denpasar Hospital'], true);
                $devP = $rise ? 0.15 + $m * 0.09 : 0.12;
                $isDev = (($n * 37 + $m * 13) % 100) / 100 < $devP;
                $cat = null; $sev = null; $status = null; $pic = null; $deadline = null;
                if ($isDev) {
                    $cats = $isCold
                        ? ['Temperature excursion', 'Kegagalan reefer/TPS', 'Kerusakan fisik/guncangan', 'Delay/penahanan bea cukai']
                        : ['Kerusakan fisik/guncangan', 'Delay/penahanan bea cukai', 'Kelembapan berlebih', 'Kesalahan dokumentasi/misrouting'];
                    $cat = $cats[($n + $m) % count($cats)];
                    $sev = ['minor', 'major', 'critical'][($n * 5 + $m) % 3];
                    $status = ($m >= 4 && ($n + $m) % 3 !== 0)
                        ? 'open'
                        : ['closed', 'in_progress', 'closed'][($n + $m) % 3];
                    $pic = ['Budi Santoso', 'Rina Rahmawati', 'Dedi Prasetyo'][($n + $m) % 3];
                    $deadline = (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
                }
                $tpsUsed = $isCold ? ($n % 2 ? 'Active Reefer X' : 'Active Reefer Y') : null;
                $prodName = $isCold ? ($n % 2 ? 'Vaksin A' : 'Insulin B') : ($n % 2 ? 'Tablet generik C' : 'Suplemen D');
                $ins(
                    'INSERT INTO events(event_date,lane_id,type,risk_item_id,severity,duration,tps_id,product_id,root_cause,action_taken,status,pic,deadline,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$date, $laneId, $isDev ? 'deviation' : 'normal',
                        $cat ? $riskId[$cat] : null, $sev,
                        $isDev ? ['< 1 jam', '1-4 jam', '> 4 hr'][($n + $m) % 3] : null,
                        $tpsUsed ? $tps[$tpsUsed] : null, $prod[$prodName],
                        $isDev ? 'Penyimpangan terdeteksi saat perjalanan' : null,
                        $isDev ? 'Investigasi dan tindakan korektif sesuai SOP' : null,
                        $status, $pic, $deadline, $isDev ? $logistikId : $logistikId]
                );
                $n++;
              }
            }
        }

        // Risk register seed: 2 lanes assessed
        $defaultScores = [
            'Temperature excursion' => [8, 6, 6], 'Kegagalan reefer/TPS' => [8, 4, 4],
            'Kapasitas cold storage transit tidak cukup' => [6, 4, 6],
            'Kerusakan fisik/guncangan' => [6, 6, 4], 'Kelembapan berlebih' => [4, 4, 4],
            'Delay/penahanan bea cukai' => [4, 6, 4], 'Kesalahan dokumentasi/misrouting' => [4, 4, 2],
            'Cuaca ekstrem' => [6, 4, 6], 'Kontaminasi silang (multi-drop)' => [6, 4, 4],
            'Keamanan/pencurian/tampering' => [6, 2, 4],
        ];
        $resp = fn(string $cat) => ['low' => 'Terima risiko (monitor rutin)', 'medium' => 'Butuh mitigasi standar',
            'high' => 'Mitigasi wajib + lane verification', 'critical' => 'Reroute / ganti TPS-supplier'][$cat];

        foreach ([['Jakarta DC - Surabaya Hospital', 'Active Reefer X', 'Vaksin A'],
                  ['Surabaya DC - Denpasar Hospital', 'Passive Box 72H', 'Insulin B']] as $a) {
            [$laneName, $tpsName, $prodName] = $a;
            $scope = 'cold';
            $assessId = $ins('INSERT INTO assessments(lane_id,tps_label,tps_id,product_id,version,assessed_by,assessed_at) VALUES(?,?,?,?,?,?,?)',
                [$lanes[$laneName], $tpsName, $tps[$tpsName], $prod[$prodName], 1, $qualityId,
                    (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);
            foreach ($defaultScores as $rn => [$s, $o, $d]) {
                $it = one('SELECT scope, mitigation FROM risk_items WHERE id = ?', [$riskId[$rn]]);
                if (!in_array($it['scope'], ['cold', 'both'], true)) continue;
                $rpn = $s * $o * $d;
                $cat = rpn_category($rpn);
                q('INSERT INTO risk_scores(assessment_id,risk_item_id,s,o,d,rat_s,rat_o,rat_d,rpn,category,response,mitigation) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$assessId, $riskId[$rn], $s, $o, $d,
                        'Berdasarkan riwayat deviasi lane dan dampak produk.', 'Frekuensi deviasi 6 bulan terakhir pada lane ini.', 'Kontrol tersedia melalui data logger dan verifikasi dokumen.',
                        $rpn, $cat, $resp($cat), $it['mitigation'] ?? '']);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
