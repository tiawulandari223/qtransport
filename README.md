# Q-Transport

**Quality oversight for transportation management** — sistem Transportation Lane Risk Assessment (TLRA)
berbasis Quality Risk Management (QRM) / FMEA untuk distribusi produk farmasi, cluster **Cold Chain** dan **Ambient**.

Aplikasi web **PHP murni + SQLite**, tanpa Composer dan tanpa framework — dirancang agar bisa
di-*upload* langsung ke shared hosting (cPanel / DirectAdmin / sejenisnya) dan langsung jalan.

---

## Isi paket

```
app/
├── index.php            front controller (satu-satunya entry point)
├── layout.php           template halaman (sidebar + topbar)
├── config.php           konfigurasi + loader .env
├── .env.example
├── .htaccess            larang akses langsung ke .env & directory listing
├── assets/app.css       desain UI (dari mockup Q-Transport)
├── assets/app.js        progresif enhancement kecil (kalkulasi tetap di server)
├── pages/               modul: login, dashboard, lane, tps, event, risk, report, admin, audit
├── src/
│   ├── bootstrap.php    session, helper, routing support
│   ├── db.php           skema + auto-migrate + seed
│   ├── auth.php         login & RBAC (dicek di level server)
│   ├── audit.php        audit trail (append-only)
│   ├── calc.php         SELURUH perhitungan (RPN, status TPS, success rate, trending, ranking)
│   ├── reports.php      pembangun laporan (PDF via FPDF, DOCX via writer sendiri)
│   ├── docx.php         penulis .docx (Office Open XML asli, di-zip)
│   └── lib/             FPDF 1.9 + font core (vendored, MIT-compatible)
└── storage/             database SQLite + hasil laporan (diblokir dari web)
```

## Kebutuhan server

- PHP **8.1+** dengan ekstensi: `pdo_sqlite`, `zip`, `mbstring`, `json`, `session`
  (semuanya bawaan PHP default; tidak perlu `composer`)
- Apache dengan `mod_rewrite` **tidak wajib** — routing memakai query string `index.php?p=…`
- MySQL/PostgreSQL **tidak diperlukan**; seluruh data tersimpan permanen di `storage/app.sqlite`

Cek cepat: `php -m | grep -E 'pdo_sqlite|zip'`

## Setup lokal

```bash
cd app
cp .env.example .env          # opsional, semua default sudah jalan
php -S 127.0.0.1:8099         # buka http://127.0.0.1:8099/index.php
```

Saat permintaan pertama dijalankan, database dibuat, skema di-migrate, dan **seed data otomatis masuk**
(5 user, 3 supplier, 6 lane, 4 unit TPS, 10 item risiko, ±60 event 6 bulan terakhir, 2 penilaian risk register).

### Akun seed

| Role | Email | Password |
|---|---|---|
| Admin | `admin@qtransport.local` | `admin123` |
| Quality/QRM | `quality@qtransport.local` | `quality123` |
| Logistik/Distribusi | `logistik@qtransport.local` | `logistik123` |
| Manajemen | `manajemen@qtransport.local` | `manajemen123` |
| Auditor | `auditor@qtransport.local` | `auditor123` |

> Ganti semua password di atas **sebelum** dipakai produksi (Manajemen Data → Pengguna & role).

## Deploy ke shared hosting

1. Upload **isi folder `app/`** ke document root (mis. `public_html/`).
   Pastikan `index.php` berada persis di document root.
2. Pastikan folder `storage/` dapat ditulis oleh user web:
   `chmod 775 storage storage/reports` (atau sesuai petunjuk hosting).
3. Buka domain Anda — login, seed sudah terisi.
4. Opsional: salin `.env.example` ke `.env` untuk menyesuaikan path / session TTL.

> Folder `src/`, `pages/`, dan `storage/` berisi `.htaccess` `Require all denied`,
> jadi kode sumber dan database tidak bisa diunduh lewat browser.
> Jika hosting memakai Nginx, tambahkan blok `location` untuk menolak ketiga direktori itu.

### Alternatif: folder publik terpisah

Jika hosting mengizinkan mengubah document root, pindahkan `index.php`, `layout.php`, `config.php`,
`assets/`, `pages/`, `src/` ke satu folder aplikasi dan arahkan document root ke folder yang berisi
`index.php` + `assets/` saja, lalu sesuaikan `DB_PATH`/`REPORTS_PATH` di `.env` ke luar document root.

## Modul & hak akses

| Modul | Lihat | Tulis |
|---|---|---|
| Dashboard | semua role | — |
| Lane Management | semua role | Admin, Logistik |
| TPS & Supplier | semua role | Admin (tulis) · Quality/QRM (approval unit) |
| Event & Deviation Log | semua role | input: Admin, Logistik, Quality · tutup CAPA: Admin, Quality |
| Risk Assessment | semua role | simpan penilaian: Admin, Logistik, Manajemen · approve: Quality/QRM |
| Report Center | semua role (unduh) | generate: Admin, Quality |
| Manajemen Data | Admin | Admin |
| Audit Trail | Admin, Quality, Auditor | — (append-only, tidak bisa diubah) |

Pembatasan dieksekusi di server (`src/auth.php` → `can()` / `deny_unless()`), bukan hanya
dengan menyembunyikan tombol di UI. Setiap POST juga divalidasi CSRF token.

## Perhitungan yang dijalankan di server

Semua angka berasal dari query database, bukan data statis:

- **RPN = S × O × D**, kategori dari threshold yang bisa diubah Admin
  (Manajemen Data → Threshold & konfigurasi), bukan hardcode di frontend.
- **Status TPS** — Berlaku / Segera jatuh tempo (≤ N hari) / Kadaluarsa, dibandingkan dengan tanggal server.
- **Success rate unit TPS** — % pengiriman tanpa deviasi terkait suhu (flag `temp_related` pada item risiko).
- **Ranking supplier** — rata-rata success rate unit miliknya → A/B/C + jumlah deviasi open.
- **Trending** — agregasi deviasi per bulan (6 bulan) + sinyal:
  naik 3 bulan berturut-turut = Critical, cenderung naik = Medium, stabil/turun = Low.
- **Status review laporan** — Terjadwal / Segera (≤30 hari) / Terlambat, dihitung setiap halaman dimuat.
- **Dashboard filters** (cluster, kategori, rentang tanggal) ikut masuk ke `WHERE` query.

## Laporan (PDF & DOCX)

Dihasilkan **di server**, bukan trik HTML-dibungkus-`.doc`:

- **PDF** — FPDF 1.9 (di-vendor di `src/lib/`), tabel multi-halaman, warna kategori.
- **DOCX** — penulis Office Open XML sendiri (`src/docx.php`), di-zip jadi paket `.docx`
  yang valid dan bisa dibuka Word / LibreOffice.

Empat jenis laporan: **TLRA (per lane)**, **Summary (per cluster)**, **Cluster Comparison**,
**Performance Supplier**. File disimpan di `storage/reports/` dan tercatat di `report_history`
beserta jadwal review (TLRA 3 tahun, Summary/Comparison 12 bulan, Supplier 6 bulan) —
bisa diunduh ulang kapan saja.

## Menambah / mengubah data awal

- Tambah item risiko → **Manajemen Data → Daftar risiko** (tersimpan di tabel `risk_items`, bukan hardcode).
- Ubah threshold RPN/ranking → **Manajemen Data → Threshold & konfigurasi**.
- Reset penuh (development): hapus `storage/app.sqlite*`, muat ulang halaman, seed dibuat ulang.

## Catatan terhadap URS

- **Skala penilaian** memakai **2/4/6/8/10** (RPN 8–1000) sesuai spesifikasi build & mockup.
  URS §7 mencatat skala 1–5 sebagai *open item* — untuk menggantinya, ubah konstanta
  `SCORE_VALUES` di `src/calc.php` dan `SCORE_CRITERIA` (rentang RPN ikut menyesuaikan lewat konfigurasi Admin).
- Tidak diimplementasikan (berprioritas *Desirable* / di luar scope URS): lampiran file pada event,
  peta rute, integrasi data logger IoT, electronic signature formal, ekspor XLSX.
