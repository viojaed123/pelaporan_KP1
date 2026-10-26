<?php
/**
 * analisis_dataset.php — Visualisasi otomatis untuk dataset berskema bebas.
 *
 * Untuk SETIAP kolom pada dataset, halaman ini membuat grafik yang sesuai
 * dengan tipenya (teks/angka/tanggal), tanpa perlu query yang ditulis tangan
 * per jenis data. Grafik dirender oleh assets/js/grafik-render.js (vendor lokal).
 *
 * KEAMANAN (KRITIS):
 *   Nama kolom dipakai sebagai nama alur JSON (JSON_EXTRACT) — jadi kunci WAJIB
 *   lolos dataset_kunci_sah() (`^[a-z0-9_]{1,64}$`) DAN ada di kolom_json
 *   dataset tersebut. Kunci yang gagal validasi DIABAIKAN, tidak pernah masuk
 *   ke SQL. Nilai selalu lewat prepared statement; output selalu di-escape.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/impor_dataset.php';

wajib_login();

$uid = (int) sesi_user_id();

/** Label pengganti nilai kosong — HARUS sama dengan KOSONG di grafik-render.js. */
const DS_KOSONG = 'Belum diisi';

/**
 * Ekspresi alur JSON untuk satu kunci kolom yang SUDAH divalidasi.
 *
 * Fungsi ini TIDAK memvalidasi; pemanggil wajib sudah memakai
 * dataset_kunci_sah() + membership kolom_json. Dipisah agar jelas bahwa
 * satu-satunya sumber nama alur adalah kunci tervalidasi.
 */
function dsAlur(string $kunci): string
{
    // $kunci sudah `^[a-z0-9_]{1,64}$` — tidak ada karakter kutip/backslash.
    return "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.\"" . $kunci . "\"'))";
}

/** Ekspresi nilai kolom dengan label "Belum diisi" untuk nilai kosong. */
function dsNilai(string $kunci): string
{
    return "COALESCE(NULLIF(TRIM(" . dsAlur($kunci) . "), ''), '" . DS_KOSONG . "')";
}

/** Ekspresi angka dari kolom: buang titik ribuan & koma desimal gaya ID. */
function dsAngka(string $kunci): string
{
    // Ganti "." (ribuan) dan "," (desimal) agar CAST berhasil.
    $a = dsAlur($kunci);
    return "CAST(REPLACE(REPLACE($a, '.', ''), ',', '.') AS DECIMAL(30,6))";
}

/**
 * Jalankan agregasi dengan prepared statement.
 * @param list<int|string> $params
 * @return array{0:list<string>,1:list<float>}
 */
function dsAgg(mysqli $conn, string $sql, array $params = [], string $tipe = ''): array
{
    $lbl = [];
    $val = [];
    $st = $conn->prepare($sql);
    if (!$st) {
        return [$lbl, $val];
    }
    if ($params !== []) {
        $st->bind_param($tipe, ...$params);
    }
    $st->execute();
    $r = $st->get_result();
    while ($r && ($x = $r->fetch_assoc())) {
        $lbl[] = (string) ($x['k'] ?? '');
        $val[] = (float) ($x['c'] ?? 0);
    }
    $st->close();

    return [$lbl, $val];
}

/** Cetak satu kartu grafik (pesan bila data kosong). */
function dsKartu(string $judul, string $id, array $label, array $nilai, string $tipe, array $warna, array $opsi = []): void
{
    $ada = false;
    foreach ($nilai as $v) {
        if ((float) $v != 0.0) {
            $ada = true;
            break;
        }
    }
    echo '<div class="card"><div class="card-h">' . e($judul) . '</div><div class="card-b">';
    if ($label === [] || !$ada) {
        echo '<div class="grafik-kosong">Belum ada data untuk kolom ini.</div>';
    } else {
        echo '<div class="grafik-kanvas"><canvas id="' . e($id) . '"></canvas></div>';
        echo '<script type="application/json" data-grafik="' . e($id) . '">'
            . json_encode(
                ['tipe' => $tipe, 'label' => $label, 'nilai' => $nilai, 'warna' => $warna, 'opsi' => $opsi],
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            )
            . '</script>';
    }
    echo '</div></div>';
}

/** Cetak kartu KPI sederhana (daftar [label, nilai]). */
function dsKpi(array $butir): void
{
    echo '<div class="kpi-baris">';
    foreach ($butir as $b) {
        echo '<div class="kpi-butir"><div class="kpi-nilai">' . e((string) $b[1]) . '</div>'
            . '<div class="kpi-label">' . e((string) $b[0]) . '</div></div>';
    }
    echo '</div>';
}

/* ================================================================
 * Pilih dataset
 * ================================================================ */
$daftar = dataset_daftar($conn);
$idDiminta = (int) ($_GET['id'] ?? 0);
if ($idDiminta <= 0 && $daftar !== []) {
    $idDiminta = (int) $daftar[0]['id'];
}

$ds = dataset_ambil($conn, $idDiminta);

/* Kolom tervalidasi (kunci aman + ada di profil). */
$kolom = [];
if ($ds !== null) {
    foreach ((array) $ds['kolom'] as $k) {
        $kunci = (string) ($k['kunci'] ?? '');
        if (dataset_kunci_sah($kunci)) {
            $kolom[$kunci] = [
                'kunci'  => $kunci,
                'label'  => (string) ($k['label'] ?? $kunci),
                'tipe'   => in_array($k['tipe'] ?? 'teks', ['teks', 'angka', 'tanggal'], true) ? (string) $k['tipe'] : 'teks',
                'kosong' => (int) ($k['kosong'] ?? 0),
            ];
        }
    }
}

$modeLengkap = (string) ($_GET['mode'] ?? '') === 'lengkap';
$cari = trim((string) ($_GET['q'] ?? ''));
$hal = max(1, (int) ($_GET['hal'] ?? 1));
$perHal = max(10, min(100, (int) ($_GET['perhal'] ?? 25)));

/** Bangun URL halaman ini dengan perubahan parameter. */
function dsUrl(array $ubah = []): string
{
    return 'analisis_dataset.php?' . http_build_query(array_merge($_GET, $ubah));
}

/* ================================================================
 * Statistik ringkas dataset (KPI)
 * ================================================================ */
$totalBaris = 0;
$totalKolom = count($kolom);
$kolomKosong = 0;
$petaTipe = ['teks' => 0, 'angka' => 0, 'tanggal' => 0];
foreach ($kolom as $k) {
    $petaTipe[$k['tipe']]++;
    if ($k['kosong'] > 0) {
        $kolomKosong++;
    }
}

if ($ds !== null) {
    $st = $conn->prepare('SELECT COUNT(*) FROM dataset_baris WHERE dataset_id = ?');
    if ($st) {
        $st->bind_param('i', $idDiminta);
        $st->execute();
        $st->bind_result($n);
        $st->fetch();
        $totalBaris = (int) $n;
        $st->close();
    }
}

/* ================================================================
 * Grafik otomatis per kolom
 * ================================================================ */
$grafikKolom = [];   // list ['judul'=>..., 'id'=>..., 'label'=>, 'nilai'=>, 'tipe'=>, 'warna'=>, 'opsi'=>]
$no = 0;

/** Warna palet. */
$palet = ['#005EB8', '#159447', '#EB1C24', '#f59e0b', '#8b5cf6', '#06b6d4', '#0F766E', '#D97706', '#4A90D9', '#3DBE6E'];
$paletDonut = ['#005EB8', '#159447', '#EB1C24', '#f59e0b', '#8b5cf6', '#06b6d4', '#0F766E', '#D97706'];

if ($ds !== null && $kolom !== []) {
    // Kolom angka & kategori (untuk "group by kategori, sum angka").
    $kolomAngka = array_values(array_filter($kolom, static fn ($k) => $k['tipe'] === 'angka'));
    $kolomTeks  = array_values(array_filter($kolom, static fn ($k) => $k['tipe'] === 'teks'));

    /* Nama kolom yang terdengar seperti uang -> format rupiah. */
    $namaUang = ['anggaran', 'biaya', 'rp', 'dana', 'pendapatan', 'harga', 'nilai', 'total'];

    foreach ($kolom as $k) {
        $no++;
        $kunci = $k['kunci'];
        $label = $k['label'];
        $nilaiEkspr = dsNilai($kunci);

        if ($k['tipe'] === 'teks' || $k['tipe'] === 'angka') {
            /* ---- Distribusi nilai (top 12) + "Belum diisi" ---- */
            $sql = "SELECT {$nilaiEkspr} AS k, COUNT(*) AS c"
                 . " FROM dataset_baris WHERE dataset_id = ?"
                 . " GROUP BY k ORDER BY c DESC, k ASC LIMIT 12";
            [$lbl, $val] = dsAgg($conn, $sql, [$idDiminta], 'i');

            $jumlahKategori = 0;
            $st = $conn->prepare("SELECT COUNT(DISTINCT {$nilaiEkspr}) FROM dataset_baris WHERE dataset_id = ?");
            if ($st) {
                $st->bind_param('i', $idDiminta);
                $st->execute();
                $st->bind_result($nk);
                $st->fetch();
                $jumlahKategori = (int) $nk;
                $st->close();
            }

            $tipe = $jumlahKategori > 6 ? 'bar' : 'doughnut';
            $grafikKolom[] = [
                'judul' => 'Distribusi — ' . $label,
                'id'    => 'g' . $no,
                'label' => $lbl,
                'nilai' => $val,
                'tipe'  => $tipe,
                'warna' => $paletDonut,
                'opsi'  => $tipe === 'doughnut' ? ['legend' => 'bottom', 'maxHeight' => '260px'] : ['horizontal' => true],
            ];

            /* ---- Teks yang mirip tahun -> tren per tahun ---- */
            if ($k['tipe'] === 'teks' && preg_match('/tahun|year|periode|thn/i', $kunci) === 1) {
                $grafikKolom[] = [
                    'judul' => 'Tren per Tahun — ' . $label,
                    'id'    => 'g' . $no . 't',
                    'label' => $lbl,
                    'nilai' => $val,
                    'tipe'  => 'line',
                    'warna' => ['#159447'],
                    'opsi'  => ['satuan' => '', 'maxHeight' => '240px'],
                ];
            }
        }

        if ($k['tipe'] === 'angka') {
            /* ---- Ringkasan statistik (KPI) ----
             * PENTING: agregat angka WAJIB memakai dsAngka() (hasil CAST),
             * bukan ekspresi teks mentah — SUM/AVG/MIN/MAX atas kolom teks
             * akan salah (diuji: SUM("1.500.000") jadi 500007).
             * Baris kosong dikecualikan lewat syarat TRIM <> ''. */
            $angkaEkspr = dsAngka($kunci);
            $sql = "SELECT COUNT({$angkaEkspr}) AS n, MIN({$angkaEkspr}) AS mn,"
                 . " MAX({$angkaEkspr}) AS mx, AVG({$angkaEkspr}) AS av, SUM({$angkaEkspr}) AS sm"
                 . " FROM dataset_baris WHERE dataset_id = ? AND TRIM({$nilaiEkspr}) <> ''";
            $st = $conn->prepare($sql);
            $stat = ['n' => 0, 'mn' => 0, 'mx' => 0, 'av' => 0, 'sm' => 0];
            if ($st) {
                $st->bind_param('i', $idDiminta);
                $st->execute();
                $r = $st->get_result();
                if ($r && ($x = $r->fetch_assoc())) {
                    $stat = [
                        'n'  => (int) $x['n'],
                        'mn' => (float) $x['mn'],
                        'mx' => (float) $x['mx'],
                        'av' => (float) $x['av'],
                        'sm' => (float) $x['sm'],
                    ];
                }
                $st->close();
            }

            $isUang = false;
            foreach ($namaUang as $u) {
                if (str_contains($kunci, $u) || str_contains(mb_strtolower($label), $u)) {
                    $isUang = true;
                    break;
                }
            }
            $fmt = static function (float $v) use ($isUang): string {
                return $isUang
                    ? 'Rp ' . number_format($v, 0, ',', '.')
                    : number_format($v, ($v == floor($v) ? 0 : 2), ',', '.');
            };

            $grafikKolom[] = [
                'judul' => 'Ringkasan — ' . $label,
                'id'    => 'g' . $no . 'k',
                'label' => [],
                'nilai' => [],
                'tipe'  => 'kpi',
                'warna' => [],
                'opsi'  => [],
                '_kpi'  => [
                    ['Jumlah terisi', (string) $stat['n']],
                    ['Minimum', $stat['n'] > 0 ? $fmt($stat['mn']) : '—'],
                    ['Maksimum', $stat['n'] > 0 ? $fmt($stat['mx']) : '—'],
                    ['Rata-rata', $stat['n'] > 0 ? $fmt($stat['av']) : '—'],
                    [$isUang ? 'Total' : 'Jumlah', $stat['n'] > 0 ? $fmt($stat['sm']) : '—'],
                ],
            ];

            /* ---- Angka vs kategori terbaik (group by X, sum Y) ---- */
            if ($kolomTeks !== []) {
                // Pilih kolom teks dengan jumlah kategori sedang (2..30) agar rapi.
                $pilih = null;
                foreach ($kolomTeks as $kt) {
                    $nilaiK = dsNilai($kt['kunci']);
                    $st = $conn->prepare("SELECT COUNT(DISTINCT {$nilaiK}) FROM dataset_baris WHERE dataset_id = ?");
                    $nk = 0;
                    if ($st) {
                        $st->bind_param('i', $idDiminta);
                        $st->execute();
                        $st->bind_result($x2);
                        $st->fetch();
                        $nk = (int) $x2;
                        $st->close();
                    }
                    if ($nk >= 2 && $nk <= 30) {
                        $pilih = $kt;
                        break;
                    }
                }
                if ($pilih !== null) {
                    $kel = dsNilai($pilih['kunci']);
                    $sum = dsAngka($kunci);
                    $sql = "SELECT {$kel} AS k, SUM({$sum}) AS c"
                         . " FROM dataset_baris WHERE dataset_id = ?"
                         . " AND TRIM({$nilaiEkspr}) <> '' GROUP BY k ORDER BY c DESC LIMIT 10";
                    [$lbl2, $val2] = dsAgg($conn, $sql, [$idDiminta], 'i');
                    $grafikKolom[] = [
                        'judul' => $label . ' per ' . $pilih['label'] . ' (total)',
                        'id'    => 'g' . $no . 's',
                        'label' => $lbl2,
                        'nilai' => $val2,
                        'tipe'  => 'bar',
                        'warna' => ['#D97706'],
                        'opsi'  => ['horizontal' => true, 'satuan' => $isUang ? 'rupiah' : ''],
                    ];
                }
            }
        }

        if ($k['tipe'] === 'tanggal') {
            /* ---- Tren per bulan (hanya baris yang tanggalnya terurai) ---- */
            $alur = dsAlur($kunci);
            $sql = "SELECT DATE_FORMAT(STR_TO_DATE(NULLIF(TRIM({$alur}), ''), '%Y-%m-%d'), '%Y-%m') AS k,"
                 . " COUNT(*) AS c FROM dataset_baris WHERE dataset_id = ?"
                 . " AND STR_TO_DATE(NULLIF(TRIM({$alur}), ''), '%Y-%m-%d') IS NOT NULL"
                 . " GROUP BY k ORDER BY k";
            [$lbl, $val] = dsAgg($conn, $sql, [$idDiminta], 'i');

            if ($lbl !== []) {
                $grafikKolom[] = [
                    'judul' => 'Tren per Bulan — ' . $label,
                    'id'    => 'g' . $no,
                    'label' => $lbl,
                    'nilai' => $val,
                    'tipe'  => 'area',
                    'warna' => ['#005EB8'],
                    'opsi'  => ['maxHeight' => '260px'],
                ];
            }

            /* ---- Distribusi nilai tanggal (menampung yang tak terurai) ---- */
            $sql2 = "SELECT {$nilaiEkspr} AS k, COUNT(*) AS c FROM dataset_baris WHERE dataset_id = ?"
                  . " GROUP BY k ORDER BY c DESC LIMIT 12";
            [$lbl3, $val3] = dsAgg($conn, $sql2, [$idDiminta], 'i');
            if ($lbl3 !== []) {
                $grafikKolom[] = [
                    'judul' => 'Sebaran Nilai — ' . $label,
                    'id'    => 'g' . $no . 'd',
                    'label' => $lbl3,
                    'nilai' => $val3,
                    'tipe'  => 'bar',
                    'warna' => ['#0F766E'],
                    'opsi'  => ['horizontal' => true],
                ];
            }
        }
    }
}

/* ================================================================
 * Grafik lintas kolom (pilihan pengguna)
 * ================================================================ */
$pilihX = (string) ($_GET['x'] ?? '');
$pilihY = (string) ($_GET['y'] ?? '');

$grafikSilang = null;
$pivot = null;

if ($ds !== null) {
    // Hanya terima kunci yang ada di $kolom (tervalidasi).
    $kunciTeks = array_values(array_map(static fn ($k) => $k['kunci'], array_filter($kolom, static fn ($k) => $k['tipe'] !== 'angka')));
    $kunciAngka = array_values(array_map(static fn ($k) => $k['kunci'], array_filter($kolom, static fn ($k) => $k['tipe'] === 'angka')));

    if ($pilihX !== '' && !isset($kolom[$pilihX])) {
        $pilihX = '';
    }
    if ($pilihY !== '' && !isset($kolom[$pilihY])) {
        $pilihY = '';
    }
    if ($pilihX === '' && $kunciTeks !== []) {
        $pilihX = $kunciTeks[0];
    }
    if ($pilihY === '' && $kunciAngka !== []) {
        $pilihY = $kunciAngka[0];
    }

    if ($pilihX !== '' && $pilihY !== '' && isset($kolom[$pilihX]) && isset($kolom[$pilihY])) {
        $kel = dsNilai($pilihX);
        $sum = dsAngka($pilihY);
        $sql = "SELECT {$kel} AS k, SUM({$sum}) AS c FROM dataset_baris WHERE dataset_id = ?"
             . " AND TRIM(" . dsAlur($pilihY) . ") <> '' GROUP BY k ORDER BY c DESC LIMIT 15";
        [$lx, $vx] = dsAgg($conn, $sql, [$idDiminta], 'i');
        $grafikSilang = [
            'judul' => $kolom[$pilihY]['label'] . ' per ' . $kolom[$pilihX]['label'],
            'id'    => 'gSilang',
            'label' => $lx,
            'nilai' => $vx,
            'tipe'  => 'bar',
            'warna' => ['#005EB8'],
            'opsi'  => ['horizontal' => true],
        ];

        /* ---- Pivot sederhana: kategori X x kategori Z (COUNT) ---- */
        $pilihZ = (string) ($_GET['z'] ?? '');
        if ($pilihZ !== '' && !isset($kolom[$pilihZ])) {
            $pilihZ = '';
        }
        if ($pilihZ === '' && count($kunciTeks) > 1) {
            $pilihZ = $kunciTeks[1];
        }
        if ($pilihZ !== '' && $pilihZ !== $pilihX && isset($kolom[$pilihZ])) {
            $kel2 = dsNilai($pilihZ);
            $sql = "SELECT {$kel} AS k, {$kel2} AS z, COUNT(*) AS c FROM dataset_baris WHERE dataset_id = ?"
                 . " GROUP BY k, z ORDER BY c DESC LIMIT 200";
            $st = $conn->prepare($sql);
            $mat = [];
            $labelZ = [];
            if ($st) {
                $st->bind_param('i', $idDiminta);
                $st->execute();
                $r = $st->get_result();
                while ($r && ($x = $r->fetch_assoc())) {
                    $kx = (string) $x['k'];
                    $kz = (string) $x['z'];
                    $mat[$kx][$kz] = (float) $x['c'];
                    $labelZ[$kz] = true;
                }
                $st->close();
            }
            $labelZ = array_slice(array_keys($labelZ), 0, 8);
            $deret = [];
            foreach ($labelZ as $i => $kz) {
                $nilaiZ = [];
                foreach (array_keys($mat) as $kx) {
                    $nilaiZ[] = $mat[$kx][$kz] ?? 0;
                }
                $deret[] = ['label' => $kz, 'nilai' => $nilaiZ, 'warna' => $paletDonut[$i % count($paletDonut)]];
            }
            if ($deret !== []) {
                $pivot = [
                    'judul' => $kolom[$pilihX]['label'] . ' × ' . $kolom[$pilihZ]['label'],
                    'id'    => 'gPivot',
                    'label' => array_keys($mat),
                    'opsi'  => ['multi' => $deret, 'stacked' => true, 'legend' => 'bottom', 'maxHeight' => '280px'],
                ];
            }
        }
    }
}

/* ================================================================
 * Tabel data (dengan pencarian & paginasi)
 * ================================================================ */
$barisTabel = [];
$totalTabel = $totalBaris;
if ($ds !== null && $kolom !== []) {
    $where = 'dataset_id = ?';
    $params = [$idDiminta];
    $tipe = 'i';
    if ($cari !== '') {
        // Pencarian pada seluruh isi JSON baris (aman: nilai lewat prepared).
        $where .= ' AND data_json LIKE ?';
        $params[] = '%' . $cari . '%';
        $tipe .= 's';
    }

    $st = $conn->prepare("SELECT COUNT(*) FROM dataset_baris WHERE $where");
    if ($st) {
        $st->bind_param($tipe, ...$params);
        $st->execute();
        $st->bind_result($n);
        $st->fetch();
        $totalTabel = (int) $n;
        $st->close();
    }

    $off = ($hal - 1) * $perHal;
    $st = $conn->prepare("SELECT nomor, data_json FROM dataset_baris WHERE $where ORDER BY nomor LIMIT ? OFFSET ?");
    if ($st) {
        $p2 = $params;
        $p2[] = $perHal;
        $p2[] = $off;
        $st->bind_param($tipe . 'ii', ...$p2);
        $st->execute();
        $r = $st->get_result();
        while ($r && ($x = $r->fetch_assoc())) {
            $data = json_decode((string) $x['data_json'], true);
            $barisTabel[] = ['nomor' => (int) $x['nomor'], 'data' => is_array($data) ? $data : []];
        }
        $st->close();
    }
}
$totalHal = max(1, (int) ceil($totalTabel / $perHal));
$off = ($hal - 1) * $perHal;

$judulDs = $ds !== null ? (string) $ds['nama'] : 'Dataset';

$head = '<script src="assets/js/vendor/chart.umd.min.js" defer></script>'
      . '<script src="assets/js/grafik.js" defer></script>'
      . '<script src="assets/js/grafik-render.js" defer></script>';

mulai_halaman([
    'judul' => 'Analisis Dataset — ' . $judulDs,
    'crumb' => APP_SINGKAT,
    'aktif' => 'dataset',
    'head'  => $head,
]);
?>

<style>
.grid-grafik { display:grid; grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr)); gap:14px; margin-top:14px; }
.card { background:#fff; border:1px solid var(--abu-200); border-radius:12px; overflow:hidden; }
.card-h { padding:11px 14px; font-size:13px; font-weight:700; color:var(--phr-biru-tua);
    border-bottom:1px solid var(--abu-200); background:var(--biru-50); }
.card-b { padding:12px 14px; }
.grafik-kanvas { position:relative; height:240px; }
.grafik-kosong { padding:26px 8px; text-align:center; color:#94a3b8; font-size:13px; }
.kpi-baris { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:10px; }
.kpi-butir { background:#f8fafc; border:1px solid var(--abu-200); border-radius:10px; padding:10px 12px; text-align:center; }
.kpi-nilai { font-size:17px; font-weight:800; color:var(--phr-biru-tua); line-height:1.2; }
.kpi-label { font-size:11.5px; color:#64748b; margin-top:3px; }
.ds-pemilih { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.ds-pemilih label { font-size:12.5px; font-weight:600; }

/* Mode gelap untuk elemen khusus halaman ini (yang lain sudah diatur app.css). */
html[data-tema="gelap"] .card { background:var(--kartu, #111a2b); border-color:var(--garis); }
html[data-tema="gelap"] .kpi-butir { background:rgba(148,163,184,.10); border-color:var(--garis); }
html[data-tema="gelap"] .kpi-nilai { color:var(--teks); }
html[data-tema="gelap"] .grafik-kosong { color:var(--teks-redup, #94a3b8); }
</style>

<?php if ($daftar === []): ?>
<div class="kartu">
    <div class="kartu-kepala"><h2>Belum Ada Dataset</h2></div>
    <div class="kartu-badan">
        <p class="text-redup">Unggah berkas terlebih dahulu untuk melihat grafik otomatisnya.</p>
        <a class="tombol tombol-utama" href="impor_dataset.php">Impor Dataset</a>
    </div>
</div>
<?php else: ?>

<div class="kartu">
    <div class="kartu-kepala" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <div>
            <h2 style="margin:0"><?= e($judulDs) ?></h2>
            <?php if (!empty($ds['deskripsi'])): ?>
                <p class="text-redup" style="margin:5px 0 0;font-size:13px"><?= e((string) $ds['deskripsi']) ?></p>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="tombol tombol-garis tombol-kecil" href="impor_dataset.php">Impor Dataset</a>
            <a class="tombol tombol-garis tombol-kecil" href="<?= e(dsUrl(['mode' => $modeLengkap ? '' : 'lengkap'])) ?>">
                <?= $modeLengkap ? 'Mode Ringkas' : 'Tampilkan Semua Grafik' ?>
            </a>
        </div>
    </div>
    <div class="kartu-badan">
        <div class="ds-pemilih">
            <label>Dataset
                <select onchange="location.href='analisis_dataset.php?id='+this.value">
                    <?php foreach ($daftar as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $idDiminta ? 'selected' : '' ?>>
                            <?= e((string) $d['nama']) ?> (<?= number_format((int) $d['jumlah_baris'], 0, ',', '.') ?> baris)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <?php dsKpi([
            ['Baris', number_format($totalBaris, 0, ',', '.')],
            ['Kolom', (string) $totalKolom],
            ['Kolom teks', (string) $petaTipe['teks']],
            ['Kolom angka', (string) $petaTipe['angka']],
            ['Kolom tanggal', (string) $petaTipe['tanggal']],
            ['Kolom ada kosong', (string) $kolomKosong],
        ]); ?>
    </div>
</div>

<?php
/* Grafik lintas kolom (pilihan pengguna) */
$teksAda = array_values(array_filter($kolom, static fn ($k) => $k['tipe'] !== 'angka'));
$angkaAda = array_values(array_filter($kolom, static fn ($k) => $k['tipe'] === 'angka'));
?>
<?php if ($teksAda !== [] && $angkaAda !== []): ?>
<div class="kartu" style="margin-top:18px">
    <div class="kartu-kepala"><h2>Grafik Pilihan Anda</h2></div>
    <div class="kartu-badan">
        <form class="ds-pemilih" method="get" action="analisis_dataset.php">
            <input type="hidden" name="id" value="<?= (int) $idDiminta ?>">
            <label>Kelompokkan (X)
                <select name="x">
                    <?php foreach ($teksAda as $k): ?>
                        <option value="<?= e($k['kunci']) ?>" <?= $k['kunci'] === $pilihX ? 'selected' : '' ?>><?= e($k['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Jumlahkan (Y)
                <select name="y">
                    <?php foreach ($angkaAda as $k): ?>
                        <option value="<?= e($k['kunci']) ?>" <?= $k['kunci'] === $pilihY ? 'selected' : '' ?>><?= e($k['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="tombol tombol-utama tombol-kecil" type="submit">Terapkan</button>
        </form>

        <div class="grid-grafik">
            <?php
            if ($grafikSilang !== null) {
                dsKartu(
                    $grafikSilang['judul'], $grafikSilang['id'], $grafikSilang['label'],
                    $grafikSilang['nilai'], $grafikSilang['tipe'], $grafikSilang['warna'], $grafikSilang['opsi']
                );
            }
            ?>
            <?php if ($pivot !== null): ?>
                <div class="card">
                    <div class="card-h"><?= e($pivot['judul']) ?></div>
                    <div class="card-b">
                        <div class="grafik-kanvas"><canvas id="<?= e($pivot['id']) ?>"></canvas></div>
                        <script type="application/json" data-grafik="<?= e($pivot['id']) ?>"><?=
                            json_encode(
                                ['tipe' => 'bar-stacked', 'label' => $pivot['label'], 'nilai' => [], 'warna' => [], 'opsi' => $pivot['opsi']],
                                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                            )
                        ?></script>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="kartu" style="margin-top:18px">
    <div class="kartu-kepala">
        <h2>Grafik Otomatis per Kolom (<?= count($grafikKolom) ?>)</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:12.5px">
            Setiap kolom dibuatkan grafik sesuai tipenya. Klik "Tampilkan Semua Grafik" untuk melihat seluruhnya.
        </p>
    </div>
    <div class="kartu-badan">
        <div class="grid-grafik">
            <?php
            $batas = $modeLengkap ? count($grafikKolom) : min(6, count($grafikKolom));
            for ($i = 0; $i < $batas; $i++) {
                $g = $grafikKolom[$i];
                if ($g['tipe'] === 'kpi') {
                    echo '<div class="card"><div class="card-h">' . e($g['judul']) . '</div><div class="card-b">';
                    dsKpi($g['_kpi']);
                    echo '</div></div>';
                    continue;
                }
                dsKartu($g['judul'], $g['id'], $g['label'], $g['nilai'], $g['tipe'], $g['warna'], $g['opsi']);
            }
            ?>
        </div>
        <?php if (!$modeLengkap && count($grafikKolom) > 6): ?>
            <p style="margin-top:14px">
                <a class="tombol tombol-garis tombol-kecil" href="<?= e(dsUrl(['mode' => 'lengkap'])) ?>">
                    Tampilkan semua <?= count($grafikKolom) ?> grafik
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php /* ================= Tabel data ================= */ ?>
<div class="kartu" style="margin-top:18px">
    <div class="kartu-kepala" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <h2 style="margin:0">Data — <?= e($judulDs) ?></h2>
        <form method="get" action="analisis_dataset.php" style="display:flex;gap:8px">
            <input type="hidden" name="id" value="<?= (int) $idDiminta ?>">
            <input type="search" name="q" value="<?= e($cari) ?>" placeholder="Cari isi data..." style="min-width:200px">
            <button class="tombol tombol-garis tombol-kecil" type="submit">Cari</button>
        </form>
    </div>
    <div class="kartu-badan padat">
        <?php if ($kolom === []): ?>
            <p class="text-redup" style="padding:16px 4px">Dataset ini tidak memiliki kolom yang bisa ditampilkan.</p>
        <?php elseif ($barisTabel === []): ?>
            <p class="text-redup" style="padding:16px 4px">Tidak ada baris yang cocok.</p>
        <?php else: ?>
        <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th style="width:60px">#</th>
                        <?php foreach ($kolom as $k): ?>
                            <th><?= e($k['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($barisTabel as $b): ?>
                    <tr>
                        <td class="text-redup"><?= (int) $b['nomor'] ?></td>
                        <?php foreach ($kolom as $k): ?>
                            <?php $v = trim((string) ($b['data'][$k['kunci']] ?? '')); ?>
                            <td><?= $v !== '' ? e($v) : '<span class="text-redup">—</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px">
            <span class="text-redup" style="font-size:12.5px">
                Menampilkan <?= count($barisTabel) ?> dari <?= number_format($totalTabel, 0, ',', '.') ?> baris
                (halaman <?= $hal ?> / <?= $totalHal ?>)
            </span>
            <?php if ($totalHal > 1): ?>
            <span style="display:flex;gap:6px">
                <?php if ($hal > 1): ?>
                    <a class="tombol tombol-garis tombol-kecil" href="<?= e(dsUrl(['hal' => $hal - 1])) ?>">‹ Sebelumnya</a>
                <?php endif; ?>
                <?php if ($hal < $totalHal): ?>
                    <a class="tombol tombol-garis tombol-kecil" href="<?= e(dsUrl(['hal' => $hal + 1])) ?>">Berikutnya ›</a>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php akhiri_halaman(); ?>
