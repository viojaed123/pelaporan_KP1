<?php
/**
 * analisis_sosial.php — Analisis & visualisasi data terpadu.
 *
 * Empat tab:
 *   srm      — tabel srm_kegiatan
 *   cid      — tabel cid_program
 *   laporan  — tabel laporan_harian (+ users)
 *   absensi  — tabel absensi
 *
 * Setiap tab menampilkan KPI, grafik (Chart.js), dan daftar data berhalaman.
 * Nilai kolom yang kosong dikelompokkan sebagai "Belum diisi" supaya grafik
 * tetap tergambar walau data belum lengkap.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/srm_cid.php';

wajib_login();

$tab = strtolower(trim((string)($_GET['tab'] ?? 'srm')));
if (!in_array($tab, ['srm', 'cid', 'laporan', 'absensi'], true)) { $tab = 'srm'; }
$highlight = isset($_GET['highlight']);

$fTahun = trim((string)($_GET['tahun'] ?? ''));
$fKab   = trim((string)($_GET['kabupaten'] ?? ''));
$fArea  = trim((string)($_GET['area'] ?? ''));
$fPilar = trim((string)($_GET['pilar'] ?? ''));
$fStatus= trim((string)($_GET['status'] ?? ''));
$fCari  = trim((string)($_GET['q'] ?? ''));
$hal    = max(1, (int)($_GET['hal'] ?? 1));
$perHal = max(25, min(100, (int)($_GET['perhal'] ?? 25)));

function aurl(array $ubah=[]){
    $q = array_merge($_GET,$ubah);
    return 'analisis_sosial.php?'.http_build_query($q);
}

/** Label pengganti untuk nilai kolom yang kosong/NULL. */
const ANALISIS_KOSONG = 'Belum diisi';

/**
 * Ekspresi SQL yang mengubah nilai kosong/NULL menjadi label "Belum diisi".
 * Dipakai pada GROUP BY agar baris tanpa nilai tetap terhitung.
 */
function sbExpr(string $kolom): string
{
    return "COALESCE(NULLIF(TRIM(" . $kolom . "), ''), '" . ANALISIS_KOSONG . "')";
}

/**
 * Bangun daftar nilai DISTINCT (tanpa label "Belum diisi") untuk pilihan filter.
 * @return list<string>
 */
function daftarDistinct(mysqli $conn, string $sql): array
{
    $out = [];
    $r = $conn->query($sql);
    while ($r && ($x = $r->fetch_assoc())) {
        $v = trim((string)$x['v']);
        if ($v !== '') { $out[] = $v; }
    }
    return array_values(array_unique($out));
}

/**
 * Jalankan satu query agregasi dan kembalikan barisnya sebagai [label, nilai].
 *
 * Query memakai placeholder `?` (dari penyaring), jadi WAJIB dijalankan lewat
 * prepared statement — `query()` mentah akan gagal begitu ada penyaring aktif
 * dan seluruh grafik jadi kosong.
 *
 * @param list<int|string> $params Nilai untuk placeholder, sesuai tipe.
 * @param string           $tipe   String tipe bind_param (mis. "is").
 * @return array{0:list<string>,1:list<int|float>}
 */
function agg(mysqli $conn, string $sql, array $params = [], string $tipe = ''): array
{
    $lbl = []; $val = [];
    $st = $conn->prepare($sql);
    if (!$st) { return [$lbl, $val]; }
    if ($params !== []) { $st->bind_param($tipe, ...$params); }
    $st->execute();
    $r = $st->get_result();
    while ($r && ($x = $r->fetch_assoc())) {
        $lbl[] = (string)($x['k'] ?? '');
        $val[] = (int)($x['c'] ?? 0);
    }
    $st->close();
    return [$lbl, $val];
}

/** Label untuk grafik tren: tahun sebagai teks. */
function trenTahun(mysqli $conn, string $tabel, string $clause = '', array $params = [], string $tipe = ''): array
{
    return agg($conn, "SELECT " . sbExpr('tahun_intervensi') . " k, COUNT(*) c"
        . " FROM $tabel $clause GROUP BY k ORDER BY k", $params, $tipe);
}

/**
 * Query agregasi bernilai desimal (mis. SUM anggaran), memakai prepared
 * statement agar penyaring tetap aman diterapkan.
 *
 * @param list<int|string> $params
 * @return array{0:list<string>,1:list<float>}
 */
function aggFloat(mysqli $conn, string $sql, array $params = [], string $tipe = ''): array
{
    $lbl = []; $val = [];
    $st = $conn->prepare($sql);
    if (!$st) { return [$lbl, $val]; }
    if ($params !== []) { $st->bind_param($tipe, ...$params); }
    $st->execute();
    $r = $st->get_result();
    while ($r && ($x = $r->fetch_assoc())) {
        $lbl[] = (string)($x['k'] ?? '');
        $val[] = (float)($x['c'] ?? 0);
    }
    $st->close();
    return [$lbl, $val];
}

/* ---------------- Daftar filter ---------------- */
$daftarTahun = [];
foreach ([
    "SELECT DISTINCT tahun_intervensi v FROM srm_kegiatan WHERE tahun_intervensi IS NOT NULL",
    "SELECT DISTINCT tahun_intervensi v FROM cid_program WHERE tahun_intervensi IS NOT NULL",
] as $q) {
    $r = $conn->query($q);
    while ($r && ($x = $r->fetch_assoc())) if ($x['v'] !== '') $daftarTahun[] = (int)$x['v'];
}
$daftarTahun = array_values(array_unique($daftarTahun));
rsort($daftarTahun);

$daftarKab = daftarDistinct($conn,
    "SELECT DISTINCT kabupaten_kota v FROM srm_kegiatan WHERE kabupaten_kota IS NOT NULL"
    . " UNION SELECT DISTINCT kabupaten_kota v FROM cid_program WHERE kabupaten_kota IS NOT NULL");

$daftarArea = daftarDistinct($conn, "SELECT DISTINCT area_field v FROM srm_kegiatan WHERE area_field IS NOT NULL");
$daftarPilar = daftarDistinct($conn, "SELECT DISTINCT pilar_program v FROM cid_program WHERE pilar_program IS NOT NULL");
$daftarStatus = daftarDistinct($conn, "SELECT DISTINCT status v FROM srm_kegiatan WHERE status IS NOT NULL");

/* ================================================================
 * 1. SRM / CID — KPI, grafik, dan tabel
 * ================================================================ */
$kpi = [];
$topKab = $stStat = $stPilar = $tren = $topArea = $topKategori = $topDesa = $anggaranPilar = [];
$baris = []; $total = 0; $off = 0; $totalHal = 1;

if ($tab === 'srm' || $tab === 'cid') {
    $srm = $tab === 'srm';
    $tabel = $srm ? 'srm_kegiatan' : 'cid_program';

    $w = []; $t = ''; $p = [];
    if ($fTahun !== '') { $w[] = 'tahun_intervensi = ?'; $t .= 'i'; $p[] = (int)$fTahun; }
    if ($fKab !== '')   { $w[] = 'kabupaten_kota = ?';  $t .= 's'; $p[] = $fKab; }
    if ($srm && $fArea !== '')   { $w[] = 'area_field = ?';   $t .= 's'; $p[] = $fArea; }
    if (!$srm && $fPilar !== '') { $w[] = 'pilar_program = ?'; $t .= 's'; $p[] = $fPilar; }
    if ($srm && $fStatus !== '') { $w[] = 'status = ?';       $t .= 's'; $p[] = $fStatus; }
    if ($fCari !== '') {
        $w[] = $srm
            ? '(judul_issue LIKE ? OR detail_issue LIKE ? OR pic LIKE ?)'
            : '(judul_program LIKE ? OR desa LIKE ? OR kelompok_diberdayakan LIKE ?)';
        $t .= 'sss';
        $lk = '%' . $fCari . '%';
        $p[] = $lk; $p[] = $lk; $p[] = $lk;
    }
    $cl = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

    /* ---- KPI ---- */
    if ($srm) {
        $sql = "SELECT COUNT(*) total, SUM(status='High') high, SUM(status='Med') med,"
            . " SUM(status='Low') low, COUNT(DISTINCT area_field) area,"
            . " COUNT(DISTINCT kabupaten_kota) kab FROM srm_kegiatan $cl";
    } else {
        $sql = "SELECT COUNT(*) total, COALESCE(SUM(total_penerima_manfaat),0) manfaat,"
            . " COALESCE(SUM(anggaran),0) anggaran, COUNT(DISTINCT desa) desa,"
            . " COUNT(DISTINCT kecamatan) kec, COUNT(DISTINCT pilar_program) pilar"
            . " FROM cid_program $cl";
    }
    $st = $conn->prepare($sql);
    if ($st) {
        if ($t !== '') { $st->bind_param($t, ...$p); }
        $st->execute();
        $rs = $st->get_result();
        $kpi = $rs ? $rs->fetch_assoc() : [];
        $st->close();
    }
    $kpi = $kpi ?: [];

    /* ---- Grafik (memakai penyaring yang sama agar konsisten) ---- */
    $andClause = $w ? ('WHERE ' . implode(' AND ', $w) . ' ') : '';

    [$topKab, $topKabVal] = agg($conn,
        "SELECT " . sbExpr('kabupaten_kota') . " k, COUNT(*) c FROM $tabel $andClause"
        . " GROUP BY k ORDER BY c DESC, k ASC LIMIT 10", $p, $t);

    [$tren, $trenVal] = trenTahun($conn, $tabel, $andClause, $p, $t);

    if ($srm) {
        [$stStat, $stStatVal] = agg($conn,
            "SELECT " . sbExpr('status') . " k, COUNT(*) c FROM srm_kegiatan $andClause"
            . " GROUP BY k ORDER BY c DESC, k ASC", $p, $t);
        [$topArea, $topAreaVal] = agg($conn,
            "SELECT " . sbExpr('area_field') . " k, COUNT(*) c FROM srm_kegiatan $andClause"
            . " GROUP BY k ORDER BY c DESC, k ASC LIMIT 10", $p, $t);
        [$topKategori, $topKategoriVal] = agg($conn,
            "SELECT " . sbExpr('kategori_kegiatan') . " k, COUNT(*) c FROM srm_kegiatan $andClause"
            . " GROUP BY k ORDER BY c DESC, k ASC LIMIT 10", $p, $t);
    } else {
        [$stPilar, $stPilarVal] = agg($conn,
            "SELECT " . sbExpr('pilar_program') . " k, COUNT(*) c FROM cid_program $andClause"
            . " GROUP BY k ORDER BY c DESC, k ASC", $p, $t);
        [$topDesa, $topDesaVal] = agg($conn,
            "SELECT " . sbExpr('desa') . " k, COUNT(*) c FROM cid_program $andClause"
            . " GROUP BY k ORDER BY c DESC, k ASC LIMIT 10", $p, $t);
        // Anggaran per pilar: angka mentah (bukan format rupiah).
        [$anggaranPilar, $anggaranPilarVal] = aggFloat($conn,
            "SELECT " . sbExpr('pilar_program') . " k, COALESCE(SUM(anggaran),0) c"
            . " FROM cid_program $andClause GROUP BY k ORDER BY c DESC, k ASC", $p, $t);
    }

    /* ---- Tabel berhalaman ---- */
    $stt = $conn->prepare("SELECT COUNT(*) c FROM $tabel $cl");
    if ($stt) {
        if ($t !== '') { $stt->bind_param($t, ...$p); }
        $stt->execute();
        $rt = $stt->get_result();
        $total = $rt ? (int)$rt->fetch_assoc()['c'] : 0;
        $stt->close();
    }
    $totalHal = max(1, (int)ceil($total / $perHal));
    $hal = min($hal, $totalHal);
    $off = ($hal - 1) * $perHal;

    $kolom = $srm
        ? 'id,tahun_intervensi,tanggal,area_field,judul_issue,pic,kelurahan,kecamatan,kabupaten_kota,status,persen_status'
        : 'id,tahun_intervensi,judul_program,pilar_program,desa,kecamatan,kabupaten_kota,kelompok_diberdayakan,total_penerima_manfaat,anggaran';
    $stt = $conn->prepare("SELECT $kolom FROM $tabel $cl ORDER BY id DESC LIMIT ? OFFSET ?");
    if ($stt) {
        $t2 = $t . 'ii';
        $p2 = $p; $p2[] = $perHal; $p2[] = $off;
        $stt->bind_param($t2, ...$p2);
        $stt->execute();
        $rt = $stt->get_result();
        while ($rt && ($x = $rt->fetch_assoc())) $baris[] = $x;
        $stt->close();
    }

/* ================================================================
 * 2. LAPORAN HARIAN
 * ================================================================ */
} elseif ($tab === 'laporan') {
    $w = []; $t = ''; $p = [];
    if ($fTahun !== '') { $w[] = 'YEAR(l.tanggal) = ?'; $t .= 'i'; $p[] = (int)$fTahun; }
    if ($fStatus !== '') { $w[] = 'l.status = ?'; $t .= 's'; $p[] = $fStatus; }
    if ($fCari !== '') {
        $w[] = '(l.kegiatan LIKE ? OR l.deskripsi LIKE ? OR u.nama LIKE ?)';
        $t .= 'sss';
        $lk = '%' . $fCari . '%';
        $p[] = $lk; $p[] = $lk; $p[] = $lk;
    }
    $cl = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

    $sql = "SELECT COUNT(*) total, COUNT(DISTINCT l.user_id) pelapor,"
        . " SUM(l.status='draft') draft, SUM(l.status='dikirim') dikirim,"
        . " SUM(l.status='diperiksa') diperiksa, SUM(l.status='perlu_revisi') revisi"
        . " FROM laporan_harian l JOIN users u ON u.id = l.user_id $cl";
    $st = $conn->prepare($sql);
    if ($st) {
        if ($t !== '') { $st->bind_param($t, ...$p); }
        $st->execute();
        $rs = $st->get_result();
        $kpi = $rs ? $rs->fetch_assoc() : [];
        $st->close();
    }
    $kpi = $kpi ?: [];

    $andClause = $w ? ('WHERE ' . implode(' AND ', $w) . ' ') : '';

    [$stStat, $stStatVal] = agg($conn,
        "SELECT l.status k, COUNT(*) c FROM laporan_harian l JOIN users u ON u.id = l.user_id"
        . " $andClause GROUP BY k ORDER BY c DESC, k ASC", $p, $t);

    // Tren 12 bulan terakhir.
    $tren = []; $trenVal = [];
    $st = $conn->prepare("SELECT DATE_FORMAT(l.tanggal,'%Y-%m') k, COUNT(*) c"
        . " FROM laporan_harian l JOIN users u ON u.id = l.user_id"
        . " $andClause GROUP BY k ORDER BY k DESC LIMIT 12");
    if ($st) {
        if ($t !== '') { $st->bind_param($t, ...$p); }
        $st->execute();
        $r = $st->get_result();
        while ($r && ($x = $r->fetch_assoc())) { $tren[] = (string)$x['k']; $trenVal[] = (int)$x['c']; }
        $st->close();
    }
    $tren = array_reverse($tren); $trenVal = array_reverse($trenVal);

    [$topKab, $topKabVal] = agg($conn,
        "SELECT COALESCE(NULLIF(TRIM(u.nama),''), '" . ANALISIS_KOSONG . "') k, COUNT(*) c"
        . " FROM laporan_harian l JOIN users u ON u.id = l.user_id"
        . " $andClause GROUP BY k ORDER BY c DESC, k ASC LIMIT 10", $p, $t);

    /* ---- Tabel ---- */
    $stt = $conn->prepare("SELECT COUNT(*) c FROM laporan_harian l JOIN users u ON u.id = l.user_id $cl");
    if ($stt) {
        if ($t !== '') { $stt->bind_param($t, ...$p); }
        $stt->execute();
        $rt = $stt->get_result();
        $total = $rt ? (int)$rt->fetch_assoc()['c'] : 0;
        $stt->close();
    }
    $totalHal = max(1, (int)ceil($total / $perHal));
    $hal = min($hal, $totalHal);
    $off = ($hal - 1) * $perHal;

    $stt = $conn->prepare("SELECT l.id, l.tanggal, l.kegiatan, l.status, u.nama"
        . " FROM laporan_harian l JOIN users u ON u.id = l.user_id"
        . " $cl ORDER BY l.id DESC LIMIT ? OFFSET ?");
    if ($stt) {
        $t2 = $t . 'ii';
        $p2 = $p; $p2[] = $perHal; $p2[] = $off;
        $stt->bind_param($t2, ...$p2);
        $stt->execute();
        $rt = $stt->get_result();
        while ($rt && ($x = $rt->fetch_assoc())) $baris[] = $x;
        $stt->close();
    }

/* ================================================================
 * 3. ABSENSI
 * ================================================================ */
} else {
    $w = []; $t = ''; $p = [];
    if ($fTahun !== '') { $w[] = 'YEAR(a.tanggal) = ?'; $t .= 'i'; $p[] = (int)$fTahun; }
    if ($fStatus !== '') { $w[] = 'a.status = ?'; $t .= 's'; $p[] = $fStatus; }
    if ($fCari !== '') {
        $w[] = '(a.kegiatan_hari_ini LIKE ? OR a.keterangan LIKE ? OR u.nama LIKE ?)';
        $t .= 'sss';
        $lk = '%' . $fCari . '%';
        $p[] = $lk; $p[] = $lk; $p[] = $lk;
    }
    $cl = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

    $sql = "SELECT COUNT(*) total, COUNT(DISTINCT a.user_id) pegawai,"
        . " SUM(a.status='hadir') hadir, SUM(a.status='izin') izin,"
        . " SUM(a.status='sakit') sakit, SUM(a.status='alpa') alpa"
        . " FROM absensi a JOIN users u ON u.id = a.user_id $cl";
    $st = $conn->prepare($sql);
    if ($st) {
        if ($t !== '') { $st->bind_param($t, ...$p); }
        $st->execute();
        $rs = $st->get_result();
        $kpi = $rs ? $rs->fetch_assoc() : [];
        $st->close();
    }
    $kpi = $kpi ?: [];

    $andClause = $w ? ('WHERE ' . implode(' AND ', $w) . ' ') : '';

    [$stStat, $stStatVal] = agg($conn,
        "SELECT COALESCE(NULLIF(TRIM(a.status),''), '" . ANALISIS_KOSONG . "') k, COUNT(*) c"
        . " FROM absensi a JOIN users u ON u.id = a.user_id $andClause GROUP BY k ORDER BY c DESC, k ASC", $p, $t);

    [$stPilar, $stPilarVal] = agg($conn,
        "SELECT COALESCE(NULLIF(TRIM(a.kondisi_kesehatan),''), '" . ANALISIS_KOSONG . "') k, COUNT(*) c"
        . " FROM absensi a JOIN users u ON u.id = a.user_id $andClause GROUP BY k ORDER BY c DESC, k ASC", $p, $t);

    // Tren 12 bulan terakhir.
    $tren = []; $trenVal = [];
    $st = $conn->prepare("SELECT DATE_FORMAT(a.tanggal,'%Y-%m') k, COUNT(*) c"
        . " FROM absensi a JOIN users u ON u.id = a.user_id"
        . " $andClause GROUP BY k ORDER BY k DESC LIMIT 12");
    if ($st) {
        if ($t !== '') { $st->bind_param($t, ...$p); }
        $st->execute();
        $r = $st->get_result();
        while ($r && ($x = $r->fetch_assoc())) { $tren[] = (string)$x['k']; $trenVal[] = (int)$x['c']; }
        $st->close();
    }
    $tren = array_reverse($tren); $trenVal = array_reverse($trenVal);

    /* ---- Tabel ---- */
    $stt = $conn->prepare("SELECT COUNT(*) c FROM absensi a JOIN users u ON u.id = a.user_id $cl");
    if ($stt) {
        if ($t !== '') { $stt->bind_param($t, ...$p); }
        $stt->execute();
        $rt = $stt->get_result();
        $total = $rt ? (int)$rt->fetch_assoc()['c'] : 0;
        $stt->close();
    }
    $totalHal = max(1, (int)ceil($total / $perHal));
    $hal = min($hal, $totalHal);
    $off = ($hal - 1) * $perHal;

    $stt = $conn->prepare("SELECT a.id, a.tanggal, a.jam_masuk, a.jam_pulang, a.status,"
        . " a.kondisi_kesehatan, u.nama"
        . " FROM absensi a JOIN users u ON u.id = a.user_id"
        . " $cl ORDER BY a.id DESC LIMIT ? OFFSET ?");
    if ($stt) {
        $t2 = $t . 'ii';
        $p2 = $p; $p2[] = $perHal; $p2[] = $off;
        $stt->bind_param($t2, ...$p2);
        $stt->execute();
        $rt = $stt->get_result();
        while ($rt && ($x = $rt->fetch_assoc())) $baris[] = $x;
        $stt->close();
    }
}

/* ---------------- Judul & label per tab ---------------- */
$judulTab = [
    'srm'     => 'SRM',
    'cid'     => 'CID',
    'laporan' => 'Laporan Harian',
    'absensi' => 'Absensi',
];
$namaTab = $judulTab[$tab];

/** Daftar tahun untuk filter laporan/absensi (dari kolom tanggal). */
if ($tab === 'laporan' || $tab === 'absensi') {
    $tabelTanggal = $tab === 'laporan' ? 'laporan_harian' : 'absensi';
    $daftarTahun = [];
    $r = $conn->query("SELECT DISTINCT YEAR(tanggal) v FROM $tabelTanggal WHERE tanggal IS NOT NULL ORDER BY v DESC");
    while ($r && ($x = $r->fetch_assoc())) if ($x['v'] !== null) $daftarTahun[] = (int)$x['v'];
}

/** Status filter untuk tab laporan/absensi. */
$daftarStatusTab = $daftarStatus;
if ($tab === 'laporan') {
    $daftarStatusTab = ['draft', 'dikirim', 'diperiksa', 'perlu_revisi'];
} elseif ($tab === 'absensi') {
    $daftarStatusTab = ['hadir', 'izin', 'sakit', 'alpa'];
}

mulai_halaman([
    'judul' => 'Analisis & Visualisasi Data',
    'crumb' => 'Analisis',
    'aktif' => 'analisis',
    'aksi'  => '<a class="tombol tombol-garis" href="peta.php">' . ikon_sp('peta') . ' Lihat di Peta</a>'
        . ' <a class="tombol tombol-garis" href="impor_laporan.php">' . ikon_sp('unggah') . ' Impor</a>',
]);
?>
<style>
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.kpi{background:#fff;border:1px solid var(--abu-200);border-radius:10px;padding:12px 14px}
.kpi .n{font-size:22px;font-weight:700}
.kpi .l{color:#475569;font-size:13px}
.tabs{display:flex;gap:8px;margin:10px 0;flex-wrap:wrap}
.tab{padding:8px 14px;border:1px solid var(--abu-200);border-radius:999px;text-decoration:none;color:#334155;background:#fff}
.tab.active{background:var(--phr-biru-tua);color:#fff;border-color:var(--phr-biru-tua)}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:14px}
.card{background:#fff;border:1px solid var(--abu-200);border-radius:10px}
.card-h{padding:10px 14px;border-bottom:1px solid var(--abu-200);font-weight:600}
.card-b{padding:12px 14px}
.filter{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
.grafik-kosong{display:flex;align-items:center;justify-content:center;min-height:220px;color:#64748b;font-size:13px;text-align:center;padding:16px}
</style>
<div class="kartu">
  <div class="kartu-kepala">
    <h2>Analisis &amp; Visualisasi Data</h2>
    <p class="text-redup" style="margin:4px 0 0;font-size:13px">Visualisasi &amp; filter data SRM, CID, laporan harian, dan absensi setelah impor</p>
  </div>
  <div class="kartu-badan">
    <?php if($highlight): ?><div class="alert alert-sukses"><span class="ikon"><?=ikon_sp('centang')?></span><span>Data berhasil diimpor. Berikut hasil analisis terkini.</span></div><?php endif; ?>
    <div class="tabs">
      <?php foreach ($judulTab as $k => $lbl): ?>
        <a class="tab <?= $tab===$k?'active':'' ?>" href="<?=aurl(['tab'=>$k,'hal'=>1,'status'=>'','pilar'=>'','area'=>''])?>"><?=e($lbl)?></a>
      <?php endforeach; ?>
    </div>
    <form class="filter" method="get" action="analisis_sosial.php">
      <input type="hidden" name="tab" value="<?=e($tab)?>">
      <label>Tahun <select name="tahun"><option value="">Semua</option><?php foreach($daftarTahun as $th):?><option value="<?=$th?>" <?=(string)$fTahun===(string)$th?'selected':''?>><?=$th?></option><?php endforeach;?></select></label>
      <?php if($tab==='srm'||$tab==='cid'): ?>
        <label>Kabupaten/Kota <select name="kabupaten"><option value="">Semua</option><?php foreach($daftarKab as $k):?><option value="<?=e($k)?>" <?=$fKab===$k?'selected':''?>><?=e($k)?></option><?php endforeach;?></select></label>
      <?php endif; ?>
      <?php if($tab==='srm'): ?>
        <label>Area Field <select name="area"><option value="">Semua</option><?php foreach($daftarArea as $a):?><option value="<?=e($a)?>" <?=$fArea===$a?'selected':''?>><?=e($a)?></option><?php endforeach;?></select></label>
      <?php elseif($tab==='cid'): ?>
        <label>Pilar Program <select name="pilar"><option value="">Semua</option><?php foreach($daftarPilar as $p):?><option value="<?=e($p)?>" <?=$fPilar===$p?'selected':''?>><?=e($p)?></option><?php endforeach;?></select></label>
      <?php endif; ?>
      <?php if($tab==='srm'||$tab==='laporan'||$tab==='absensi'): ?>
        <label>Status <select name="status"><option value="">Semua</option><?php foreach($daftarStatusTab as $s):?><option value="<?=e($s)?>" <?=$fStatus===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select></label>
      <?php endif; ?>
      <label>Pencarian <input type="text" name="q" value="<?=e($fCari)?>" placeholder="cari..."></label>
      <button class="tombol tombol-utama" type="submit">Terapkan</button>
      <a class="tombol tombol-garis" href="analisis_sosial.php?tab=<?=$tab?>">Reset</a>
    </form>

    <?php /* ---------------- KPI ---------------- */ ?>
    <div class="kpi-grid" style="margin-top:14px">
      <?php if($tab==='srm'): ?>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['total']??0),0,',','.')?></div><div class="l">Total SRM</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['high']??0),0,',','.')?></div><div class="l">High</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['med']??0),0,',','.')?></div><div class="l">Med</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['low']??0),0,',','.')?></div><div class="l">Low</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['area']??0),0,',','.')?></div><div class="l">Area Field</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['kab']??0),0,',','.')?></div><div class="l">Kabupaten/Kota</div></div>
      <?php elseif($tab==='cid'): ?>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['total']??0),0,',','.')?></div><div class="l">Total Program</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['manfaat']??0),0,',','.')?></div><div class="l">Penerima Manfaat</div></div>
        <div class="kpi"><div class="n"><?=srm_cid_rupiah($kpi['anggaran']??0)?></div><div class="l">Total Anggaran</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['desa']??0),0,',','.')?></div><div class="l">Desa</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['kec']??0),0,',','.')?></div><div class="l">Kecamatan</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['pilar']??0),0,',','.')?></div><div class="l">Pilar Program</div></div>
      <?php elseif($tab==='laporan'): ?>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['total']??0),0,',','.')?></div><div class="l">Total Laporan</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['pelapor']??0),0,',','.')?></div><div class="l">Pelapor</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['draft']??0),0,',','.')?></div><div class="l">Draft</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['dikirim']??0),0,',','.')?></div><div class="l">Dikirim</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['diperiksa']??0),0,',','.')?></div><div class="l">Diperiksa</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['revisi']??0),0,',','.')?></div><div class="l">Perlu Revisi</div></div>
      <?php else: ?>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['total']??0),0,',','.')?></div><div class="l">Total Absensi</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['pegawai']??0),0,',','.')?></div><div class="l">Pegawai</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['hadir']??0),0,',','.')?></div><div class="l">Hadir</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['izin']??0),0,',','.')?></div><div class="l">Izin</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['sakit']??0),0,',','.')?></div><div class="l">Sakit</div></div>
        <div class="kpi"><div class="n"><?=number_format((int)($kpi['alpa']??0),0,',','.')?></div><div class="l">Alpa</div></div>
      <?php endif; ?>
    </div>

    <?php
    /**
     * Cetak satu kartu grafik. Bila data kosong, tampilkan pesan alih-alih kanvas.
     * @param list<string> $label
     * @param list<int|float> $nilai
     */
    if (!function_exists('kartuGrafik')) {
    function kartuGrafik(string $judul, string $id, array $label, array $nilai, string $tipe, array $warna, array $opsi = []): void
    {
        $kosong = ($label === [] || array_sum(array_map('floatval', $nilai)) == 0.0);
        echo '<div class="card"><div class="card-h">' . e($judul) . '</div><div class="card-b">';
        if ($kosong) {
            echo '<div class="grafik-kosong">Belum ada data. Impor data dulu pada menu Impor.</div>';
        } else {
            echo '<div class="grafik-kanvas"><canvas id="' . e($id) . '"></canvas></div>';
            // Data disisipkan sebagai JSON agar aman untuk semua karakter.
            echo '<script type="application/json" data-grafik="' . e($id) . '">'
                . json_encode(['tipe' => $tipe, 'label' => $label, 'nilai' => $nilai,
                    'warna' => $warna, 'opsi' => $opsi], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>';
        }
        echo '</div></div>';
    }
    }

    $warnaUtama = ['#005EB8', '#159447', '#EB1C24', '#f59e0b', '#8b5cf6', '#06b6d4', '#0F766E', '#D97706'];
    $warnaDonut = ['#EB1C24', '#f59e0b', '#159447', '#94a3b8', '#005EB8', '#8b5cf6'];
    ?>

    <?php if($tab==='srm'): ?>
    <div class="grid2" style="margin-top:14px">
      <?php
      kartuGrafik('Distribusi Status', 'cStat', $stStat ?? [], $stStatVal ?? [], 'doughnut', $warnaDonut, ['legend' => 'bottom']);
      kartuGrafik('Top 10 Kabupaten/Kota', 'cKab', $topKab, $topKabVal, 'bar', ['#005EB8'], ['horizontal' => true]);
      kartuGrafik('Tren per Tahun', 'cTren', $tren, $trenVal, 'bar', ['#159447']);
      kartuGrafik('Top Area Field', 'cArea', $topArea ?? [], $topAreaVal ?? [], 'bar', ['#f59e0b'], ['horizontal' => true]);
      kartuGrafik('Top Kategori Kegiatan', 'cKat', $topKategori ?? [], $topKategoriVal ?? [], 'bar', ['#8b5cf6'], ['horizontal' => true]);
      ?>
    </div>
    <?php elseif($tab==='cid'): ?>
    <div class="grid2" style="margin-top:14px">
      <?php
      kartuGrafik('Distribusi Pilar Program', 'cPilar', $stPilar ?? [], $stPilarVal ?? [], 'doughnut', $warnaDonut, ['legend' => 'bottom']);
      kartuGrafik('Top 10 Kabupaten/Kota', 'cKab', $topKab, $topKabVal, 'bar', ['#005EB8'], ['horizontal' => true]);
      kartuGrafik('Tren per Tahun', 'cTren', $tren, $trenVal, 'bar', ['#159447']);
      kartuGrafik('Top 10 Desa', 'cDesa', $topDesa ?? [], $topDesaVal ?? [], 'bar', ['#0F766E'], ['horizontal' => true]);
      kartuGrafik('Anggaran per Pilar', 'cAnggaran', $anggaranPilar ?? [], $anggaranPilarVal ?? [], 'bar', ['#D97706'], ['horizontal' => true]);
      ?>
    </div>
    <?php elseif($tab==='laporan'): ?>
    <div class="grid2" style="margin-top:14px">
      <?php
      kartuGrafik('Distribusi Status', 'cStat', $stStat ?? [], $stStatVal ?? [], 'doughnut', $warnaDonut, ['legend' => 'bottom']);
      kartuGrafik('Laporan per Bulan', 'cTren', $tren, $trenVal, 'bar', ['#005EB8']);
      kartuGrafik('Top 10 Pelapor', 'cPelapor', $topKab, $topKabVal, 'bar', ['#159447'], ['horizontal' => true]);
      ?>
    </div>
    <?php else: ?>
    <div class="grid2" style="margin-top:14px">
      <?php
      kartuGrafik('Distribusi Kehadiran', 'cStat', $stStat ?? [], $stStatVal ?? [], 'doughnut', $warnaDonut, ['legend' => 'bottom']);
      kartuGrafik('Distribusi Kondisi Kesehatan', 'cPilar', $stPilar ?? [], $stPilarVal ?? [], 'doughnut', $warnaDonut, ['legend' => 'bottom']);
      kartuGrafik('Absensi per Bulan', 'cTren', $tren, $trenVal, 'bar', ['#005EB8']);
      ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ================= Daftar data ================= */ ?>
<div class="kartu">
  <div class="kartu-kepala"><h2>Daftar Data — <?=e($namaTab)?></h2></div>
  <div class="kartu-badan padat">
    <div class="bungkus-tabel">
      <table class="tabel">
        <?php if($tab==='srm'): ?>
        <thead><tr><th>#</th><th>Tahun</th><th>Tanggal</th><th>Area</th><th>Judul Issue</th><th>PIC</th><th>Lokasi</th><th>Status</th><th>%</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($baris as $i=>$r): ?>
          <tr><td><?=($off+$i+1)?></td><td><?=e((string)$r['tahun_intervensi'])?></td><td><?=e((string)($r['tanggal']??'-'))?></td><td><?=e((string)($r['area_field']??'-'))?></td><td><?=e((string)$r['judul_issue'])?></td><td><?=e((string)($r['pic']??'-'))?></td><td><?=e(trim(($r['kelurahan']??'').', '.($r['kecamatan']??'').', '.($r['kabupaten_kota']??''),', '))?></td><td><?=e((string)($r['status']??'-'))?></td><td><?=srm_persen($r['persen_status']??null)?></td><td><a class="tombol tombol-garis tombol-kecil" href="peta.php?sumber=srm&q=<?=rawurlencode((string)$r['judul_issue'])?>">Lihat di Peta</a></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php elseif($tab==='cid'): ?>
        <thead><tr><th>#</th><th>Tahun</th><th>Judul Program</th><th>Pilar</th><th>Desa</th><th>Kecamatan</th><th>Kabupaten/Kota</th><th>Kelompok</th><th class="text-kanan">Penerima Manfaat</th><th class="text-kanan">Anggaran</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($baris as $i=>$r): ?>
          <tr><td><?=($off+$i+1)?></td><td><?=e((string)$r['tahun_intervensi'])?></td><td><?=e((string)$r['judul_program'])?></td><td><?=e((string)($r['pilar_program']??'-'))?></td><td><?=e((string)($r['desa']??'-'))?></td><td><?=e((string)($r['kecamatan']??'-'))?></td><td><?=e((string)($r['kabupaten_kota']??'-'))?></td><td><?=e((string)($r['kelompok_diberdayakan']??'-'))?></td><td class="text-kanan"><?=number_format((int)($r['total_penerima_manfaat']??0),0,',','.')?></td><td class="text-kanan"><?=srm_cid_rupiah($r['anggaran']??null)?></td><td><a class="tombol tombol-garis tombol-kecil" href="peta.php?sumber=cid&q=<?=rawurlencode((string)$r['judul_program'])?>">Lihat di Peta</a></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php elseif($tab==='laporan'): ?>
        <thead><tr><th>#</th><th>Tanggal</th><th>Pelapor</th><th>Kegiatan</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($baris as $i=>$r): ?>
          <tr><td><?=($off+$i+1)?></td><td><?=e((string)($r['tanggal']??'-'))?></td><td><?=e((string)($r['nama']??'-'))?></td><td><?=e((string)($r['kegiatan']??'-'))?></td><td><?=e((string)($r['status']??'-'))?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php else: ?>
        <thead><tr><th>#</th><th>Tanggal</th><th>Nama</th><th>Masuk</th><th>Pulang</th><th>Status</th><th>Kondisi</th></tr></thead>
        <tbody>
        <?php foreach($baris as $i=>$r): ?>
          <tr><td><?=($off+$i+1)?></td><td><?=e((string)($r['tanggal']??'-'))?></td><td><?=e((string)($r['nama']??'-'))?></td><td><?=e((string)($r['jam_masuk']??'-'))?></td><td><?=e((string)($r['jam_pulang']??'-'))?></td><td><?=e((string)($r['status']??'-'))?></td><td><?=e((string)($r['kondisi_kesehatan']??'-'))?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php endif; ?>
      </table>
    </div>
    <?php if($baris===[]): ?>
      <p class="text-redup" style="margin:12px 2px 2px;font-size:13px">Belum ada data pada tab ini.</p>
    <?php endif; ?>
    <?php if($totalHal>1): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
      <?php for($i=max(1,$hal-2);$i<=min($totalHal,$hal+2);$i++): ?>
        <a class="tombol tombol-garis tombol-kecil <?= $i===$hal?'aktif':'' ?>" href="<?=aurl(['hal'=>$i])?>"><?=$i?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    'use strict';
    if (typeof Chart === 'undefined') { return; }

    Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

    var wadah = document.querySelectorAll('script[data-grafik]');
    Array.prototype.forEach.call(wadah, function (el) {
        var d;
        try { d = JSON.parse(el.textContent || '{}'); } catch (e) { return; }

        var kanvas = document.getElementById(el.getAttribute('data-grafik'));
        if (!kanvas) { return; }

        var opsi = { responsive: true, maintainAspectRatio: false };

        if (d.tipe === 'doughnut') {
            opsi.plugins = { legend: { position: (d.opsi && d.opsi.legend) || 'bottom' } };
            kanvas.style.maxHeight = '260px';
        } else {
            opsi.plugins = { legend: { display: false } };
            if (d.opsi && d.opsi.horizontal) {
                opsi.indexAxis = 'y';
                opsi.scales = { x: { beginAtZero: true, ticks: { precision: 0 } } };
            } else {
                opsi.scales = { y: { beginAtZero: true, ticks: { precision: 0 } } };
            }
        }

        // "Belum diisi" selalu diberi warna abu netral agar mudah dikenali.
        var warna = [];
        var palet = d.warna || ['#005EB8'];
        for (var i = 0; i < d.label.length; i++) {
            if (d.label[i] === <?= json_encode(ANALISIS_KOSONG) ?>) {
                warna.push('#94a3b8');
            } else {
                warna.push(palet[i % palet.length]);
            }
        }

        new Chart(kanvas, {
            type: d.tipe === 'doughnut' ? 'doughnut' : 'bar',
            data: { labels: d.label, datasets: [{ data: d.nilai, backgroundColor: warna, borderWidth: 1, borderColor: '#fff', borderRadius: d.tipe === 'doughnut' ? 0 : 5 }] },
            options: opsi
        });
    });
})();
</script>
<?php akhiri_halaman(); ?>
