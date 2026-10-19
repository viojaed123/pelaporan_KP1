<?php
/**
 * peta.php — Peta lokasi untuk SEMUA data.
 *
 * Menampilkan titik lokasi dari:
 *   - Kasus            (garis_lintang/garis_bujur asli)
 *   - SRM              (hasil resolusi wilayah)
 *   - CID              (hasil resolusi wilayah)
 *   - Laporan Harian   (lokasi diisi karyawan)
 *   - Absensi          (lokasi diisi karyawan)
 *   - Jadwal           (lokasi diisi karyawan)
 *
 * Setiap titik dilengkapi tautan "Cek Lokasi" ke Google Maps. Data yang
 * belum memiliki koordinat tetap dicantumkan pada tabel dengan tautan
 * pencarian Google Maps berdasar teks alamat.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

/** Ringkas nama berkas agar rapi di banner sorotan. */
function namaAsliLabelPeta(string $nama): string
{
    $nama = $nama !== '' ? $nama : 'berkas';
    return mb_strlen($nama) > 48 ? mb_substr($nama, 0, 45) . '...' : $nama;
}

/* ---------------- Penyaring ---------------- */
$fTahun = trim((string) ($_GET['tahun'] ?? ''));
$fKab   = trim((string) ($_GET['kabupaten'] ?? ''));
$fCari  = trim((string) ($_GET['q'] ?? ''));
$sumber = (string) ($_GET['sumber'] ?? 'semua');

$tahunInt = ($fTahun !== '' && ctype_digit($fTahun)) ? (int) $fTahun : null;
$like     = '%' . $fCari . '%';

/* ---------------- Lanjutan geokode otomatis ----------------
 * Baris yang masih "menunggu geokode" (mis. hasil impor lebih dari 15 baris)
 * dilanjutkan bertahap. Agar halaman tetap responsif, proses otomatis dibatasi
 * 5 baris dan hanya dijalankan saat sorotan data baru aktif; tombol "Proses
 * sisa lokasi" memproses lebih banyak (20 baris). Nominatim tetap 1 req/detik.
 */
$geokodeHasil = null;
$menungguAwal = function_exists('wilayah_menunggu_geokode_semua')
    ? wilayah_menunggu_geokode_semua($conn)
    : ['total' => 0, 'per_tabel' => []];

if (function_exists('wilayah_batch_koordinat_berikutnya') && $menungguAwal['total'] > 0) {
    $paksaProses = isset($_GET['proses_geokode']);
    $sorotSedang  = isset($_GET['sorot'])
        && isset($_SESSION['unggahan_terakhir'])
        && is_array($_SESSION['unggahan_terakhir']);

    if ($paksaProses) {
        @set_time_limit(90);
        $geokodeHasil = wilayah_batch_koordinat_berikutnya($conn, 20);
    } elseif ($sorotSedang) {
        @set_time_limit(30);
        $geokodeHasil = wilayah_batch_koordinat_berikutnya($conn, 5);
    }
}

$menungguSisa = function_exists('wilayah_menunggu_geokode_semua')
    ? wilayah_menunggu_geokode_semua($conn)
    : ['total' => 0, 'per_tabel' => []];

/** Titik peta terpadu. */
$titik = [];
$jumlah = [
    'kasus'   => 0,
    'srm'     => 0,
    'cid'     => 0,
    'laporan' => 0,
    'absensi' => 0,
    'jadwal'  => 0,
];
$tanpaKoordinat = [];

/**
 * Tambah satu titik.
 */
function peta_titik(array &$titik, array &$jumlah, string $sumber, array $baris): void
{
    $lat = (float) $baris['lat'];
    $lng = (float) $baris['lng'];
    if (!wilayah_koordinat_valid($lat, $lng)) {
        return;
    }
    $baris['lat'] = $lat;
    $baris['lng'] = $lng;
    $baris['sumber'] = $sumber;
    $titik[] = $baris;
    $jumlah[$sumber] = ($jumlah[$sumber] ?? 0) + 1;
}

/* ---------------- Kasus ---------------- */
$sql = 'SELECT id, kode_kasus, judul, nama_lokasi, tingkat_urgensi, status_kasus,
               garis_lintang AS lat, garis_bujur AS lng
          FROM kasus
         WHERE garis_lintang BETWEEN -90 AND 90
           AND garis_bujur BETWEEN -180 AND 180
           AND NOT (garis_lintang = 0 AND garis_bujur = 0)';
$param = [];
$tipe = '';
if ($fCari !== '') {
    $sql .= ' AND (judul LIKE ? OR nama_lokasi LIKE ? OR kode_kasus LIKE ?)';
    $tipe .= 'sss';
    array_push($param, $like, $like, $like);
}
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($tipe !== '') {
        $stmt->bind_param($tipe, ...$param);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $alamat = wilayah_peta_url($r['nama_lokasi'] ?? '');
        peta_titik($titik, $jumlah, 'kasus', [
            'id'       => (string) $r['kode_kasus'],
            'judul'    => (string) $r['judul'],
            'keterangan' => 'Lokasi: ' . ($r['nama_lokasi'] ?? '-')
                . ' | Urgensi: ' . ($r['tingkat_urgensi'] ?? '-')
                . ' | Status: ' . ($r['status_kasus'] ?? '-'),
            'lat'      => $r['lat'],
            'lng'      => $r['lng'],
            'peta_url' => $alamat,
            'detail_url' => 'detail_kasus.php?id=' . (int) $r['id'],
        ]);
    }
    $stmt->close();
}

/* ---------------- SRM ---------------- */
if ($sumber === 'semua' || $sumber === 'srm') {
    $sql = 'SELECT id, tahun_intervensi, judul_issue, kelurahan, kecamatan, kabupaten_kota, status,
                   garis_lintang AS lat, garis_bujur AS lng
              FROM srm_kegiatan
             WHERE garis_lintang IS NOT NULL AND garis_bujur IS NOT NULL';
    $tipe = '';
    $param = [];
    if ($tahunInt !== null) {
        $sql .= ' AND tahun_intervensi = ?';
        $tipe .= 'i';
        $param[] = $tahunInt;
    }
    if ($fKab !== '') {
        $sql .= ' AND kabupaten_kota = ?';
        $tipe .= 's';
        $param[] = $fKab;
    }
    if ($fCari !== '') {
        $sql .= ' AND (judul_issue LIKE ? OR kelurahan LIKE ? OR kecamatan LIKE ?)';
        $tipe .= 'sss';
        array_push($param, $like, $like, $like);
    }
    $stmt = $conn->prepare($sql . ' LIMIT 2000');
    if ($stmt) {
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            peta_titik($titik, $jumlah, 'srm', [
                'id'       => 'SRM#' . $r['id'],
                'judul'    => (string) ($r['judul_issue'] ?? '-'),
                'keterangan' => ($r['kelurahan'] ?? '-') . ', ' . ($r['kecamatan'] ?? '-')
                    . ', ' . ($r['kabupaten_kota'] ?? '-') . ' | Status: ' . ($r['status'] ?? '-'),
                'lat'      => $r['lat'],
                'lng'      => $r['lng'],
                'peta_url' => wilayah_peta_url($r['kelurahan'] ?? null, [$r['kecamatan'] ?? '', $r['kabupaten_kota'] ?? '', 'Riau']),
                'detail_url' => 'srm.php?q=' . rawurlencode((string) ($r['judul_issue'] ?? '')),
            ]);
        }
        $stmt->close();
    }
}

/* ---------------- CID ---------------- */
if ($sumber === 'semua' || $sumber === 'cid') {
    $sql = 'SELECT id, tahun_intervensi, judul_program, pilar_program, desa, kecamatan, kabupaten_kota,
                   total_penerima_manfaat, garis_lintang AS lat, garis_bujur AS lng
              FROM cid_program
             WHERE garis_lintang IS NOT NULL AND garis_bujur IS NOT NULL';
    $tipe = '';
    $param = [];
    if ($tahunInt !== null) {
        $sql .= ' AND tahun_intervensi = ?';
        $tipe .= 'i';
        $param[] = $tahunInt;
    }
    if ($fKab !== '') {
        $sql .= ' AND kabupaten_kota = ?';
        $tipe .= 's';
        $param[] = $fKab;
    }
    if ($fCari !== '') {
        $sql .= ' AND (judul_program LIKE ? OR desa LIKE ? OR kecamatan LIKE ?)';
        $tipe .= 'sss';
        array_push($param, $like, $like, $like);
    }
    $stmt = $conn->prepare($sql . ' LIMIT 2000');
    if ($stmt) {
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            peta_titik($titik, $jumlah, 'cid', [
                'id'       => 'CID#' . $r['id'],
                'judul'    => (string) ($r['judul_program'] ?? '-'),
                'keterangan' => ($r['desa'] ?? '-') . ', ' . ($r['kecamatan'] ?? '-')
                    . ', ' . ($r['kabupaten_kota'] ?? '-') . ' | Pilar: ' . ($r['pilar_program'] ?? '-')
                    . ' | Penerima: ' . srm_cid_angka_id($r['total_penerima_manfaat']),
                'lat'      => $r['lat'],
                'lng'      => $r['lng'],
                'peta_url' => wilayah_peta_url($r['desa'] ?? null, [$r['kecamatan'] ?? '', $r['kabupaten_kota'] ?? '', 'Riau']),
                'detail_url' => 'cid.php?q=' . rawurlencode((string) ($r['judul_program'] ?? '')),
            ]);
        }
        $stmt->close();
    }
}

/* ---------------- Laporan Harian ---------------- */
if ($sumber === 'semua' || $sumber === 'laporan') {
    $sql = 'SELECT l.id, l.tanggal, l.kegiatan, l.lokasi_teks, u.nama,
                   l.garis_lintang AS lat, l.garis_bujur AS lng,
                   (SELECT bg.id FROM bukti_gambar bg WHERE bg.laporan_id = l.id ORDER BY bg.id LIMIT 1) AS foto_id
              FROM laporan_harian l
              LEFT JOIN users u ON u.id = l.user_id
             WHERE l.garis_lintang IS NOT NULL AND l.garis_bujur IS NOT NULL';
    $tipe = '';
    $param = [];
    if ($fCari !== '') {
        $sql .= ' AND (l.kegiatan LIKE ? OR l.lokasi_teks LIKE ? OR u.nama LIKE ?)';
        $tipe .= 'sss';
        array_push($param, $like, $like, $like);
    }
    $stmt = $conn->prepare($sql . ' LIMIT 2000');
    if ($stmt) {
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            peta_titik($titik, $jumlah, 'laporan', [
                'id'       => 'LAP#' . $r['id'],
                'judul'    => (string) ($r['kegiatan'] ?? '-'),
                'keterangan' => ($r['nama'] ?? '-') . ' | ' . tanggal_id($r['tanggal'] !== null ? (string) $r['tanggal'] : null)
                    . ' | ' . ($r['lokasi_teks'] ?? '-'),
                'lat'      => $r['lat'],
                'lng'      => $r['lng'],
                'peta_url' => wilayah_peta_url($r['lokasi_teks'] ?? null),
                'detail_url' => 'detail_laporan.php?id=' . (int) $r['id'],
                'foto_url' => !empty($r['foto_id']) ? 'lihat_foto.php?id=' . (int) $r['foto_id'] : '',
            ]);
        }
        $stmt->close();
    }
}

/* ---------------- Absensi ---------------- */
if ($sumber === 'semua' || $sumber === 'absensi') {
    $sql = 'SELECT a.id, a.tanggal, a.lokasi_teks, a.status, u.nama,
                   a.garis_lintang AS lat, a.garis_bujur AS lng
              FROM absensi a
              LEFT JOIN users u ON u.id = a.user_id
             WHERE a.garis_lintang IS NOT NULL AND a.garis_bujur IS NOT NULL';
    $tipe = '';
    $param = [];
    if ($fCari !== '') {
        $sql .= ' AND (a.lokasi_teks LIKE ? OR u.nama LIKE ?)';
        $tipe .= 'ss';
        array_push($param, $like, $like);
    }
    $stmt = $conn->prepare($sql . ' LIMIT 2000');
    if ($stmt) {
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            peta_titik($titik, $jumlah, 'absensi', [
                'id'       => 'ABS#' . $r['id'],
                'judul'    => 'Absensi ' . ($r['nama'] ?? '-'),
                'keterangan' => tanggal_id($r['tanggal'] !== null ? (string) $r['tanggal'] : null)
                    . ' | ' . label_status_absensi((string) $r['status'])
                    . ' | ' . ($r['lokasi_teks'] ?? '-'),
                'lat'      => $r['lat'],
                'lng'      => $r['lng'],
                'peta_url' => wilayah_peta_url($r['lokasi_teks'] ?? null),
                'detail_url' => 'absensi.php',
            ]);
        }
        $stmt->close();
    }
}

/* ---------------- Jadwal ---------------- */
if ($sumber === 'semua' || $sumber === 'jadwal') {
    $sql = 'SELECT j.id, j.judul, j.lokasi, j.mulai, u.nama,
                   j.garis_lintang AS lat, j.garis_bujur AS lng
              FROM jadwal j
              LEFT JOIN users u ON u.id = j.user_id
             WHERE j.garis_lintang IS NOT NULL AND j.garis_bujur IS NOT NULL';
    $tipe = '';
    $param = [];
    if ($fCari !== '') {
        $sql .= ' AND (j.judul LIKE ? OR j.lokasi LIKE ? OR u.nama LIKE ?)';
        $tipe .= 'sss';
        array_push($param, $like, $like, $like);
    }
    $stmt = $conn->prepare($sql . ' LIMIT 2000');
    if ($stmt) {
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            peta_titik($titik, $jumlah, 'jadwal', [
                'id'       => 'JDW#' . $r['id'],
                'judul'    => (string) ($r['judul'] ?? '-'),
                'keterangan' => ($r['nama'] ?? '-') . ' | ' . ($r['mulai'] ?? '-')
                    . ' | ' . ($r['lokasi'] ?? '-'),
                'lat'      => $r['lat'],
                'lng'      => $r['lng'],
                'peta_url' => wilayah_peta_url($r['lokasi'] ?? null),
                'detail_url' => 'jadwal.php',
            ]);
        }
        $stmt->close();
    }
}

/* ---------------- Total keseluruhan + daftar filter ---------------- */
$jmlTotal = count($titik);

/* ---------------- Sorotan data yang baru diunggah ---------------- */
$unggahan = $_SESSION['unggahan_terakhir'] ?? null;

/* Sorot ulang dari riwayat unggahan (tombol pada panel riwayat). */
if (isset($_GET['sorot_unggah'])) {
    $idUnggah = (int) $_GET['sorot_unggah'];
    $uidSesi  = (int) sesi_user_id();
    $roleSesi = sesi_role();
    if ($idUnggah > 0) {
        $stmtU = $conn->prepare(
            'SELECT user_id, jenis, nama_berkas, jumlah_masuk, id_semua, sumber
               FROM impor_sejarah WHERE id = ? LIMIT 1'
        );
        if ($stmtU) {
            $stmtU->bind_param('i', $idUnggah);
            $stmtU->execute();
            $rU = $stmtU->get_result()->fetch_assoc();
            $stmtU->close();

            $bolehSorot = $rU
                && ((int) $rU['user_id'] === $uidSesi || in_array($roleSesi, ['mentor', 'admin'], true));
            if ($bolehSorot) {
                $jenisU = (string) $rU['jenis'];
                $prefixU = $jenisU === 'absensi' ? 'ABS#' : 'LAP#';
                $tabelU  = match ($jenisU) {
                    'absensi'  => 'absensi',
                    'srm_cid'  => 'srm_kegiatan,cid_program',
                    default    => 'laporan_harian',
                };
                $idArr = json_decode((string) ($rU['id_semua'] ?? ''), true);
                $idArr = is_array($idArr) ? array_values(array_filter(array_map('intval', $idArr), static fn ($v) => $v > 0)) : [];
                /* Impor SRM/CID bersifat ganti-semua, sehingga penyorotan cukup
                 * per sumber (semua titik srm & cid berasal dari berkas itu).
                 * Untuk laporan/absensi, sorot hanya id yang diimpor. */
                $kunciU = $jenisU === 'srm_cid'
                    ? []
                    : array_map(static fn ($i) => $prefixU . $i, $idArr);

                $srcU = trim((string) ($rU['sumber'] ?? ''));
                $sumberU = $srcU !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $srcU)), static fn ($s) => $s !== ''))
                    : [$jenisU === 'absensi' ? 'absensi' : 'laporan'];

                $_SESSION['unggahan_terakhir'] = [
                    'jenis'        => $jenisU,
                    'tabel'        => $tabelU,
                    'masuk'        => (int) ($rU['jumlah_masuk'] ?? 0),
                    'berkas'       => (string) $rU['nama_berkas'],
                    'waktu'        => date('Y-m-d H:i:s'),
                    'sorot_kunci'  => array_values($kunciU),
                    'sorot_sumber' => $sumberU,
                ];
                header('Location: peta.php?sorot=1');
                exit;
            }
        }
    }
    header('Location: peta.php');
    exit;
}

if (isset($_GET['hapus_sorot'])) {
    unset($_SESSION['unggahan_terakhir']);
    header('Location: peta.php');
    exit;
}

$kunciSorot  = (array) ($unggahan['sorot_kunci'] ?? []);
$sumberSorot = (array) ($unggahan['sorot_sumber'] ?? []);
// Sorotan berlaku bila ada datanya dan (diminta eksplisit `?sorot=1`
// atau belum kedaluwarsa, maks 24 jam).
$waktuSorot = strtotime((string) ($unggahan['waktu'] ?? '')) ?: time();
$sorotAktif = $unggahan !== null
    && ($kunciSorot !== [] || $sumberSorot !== [])
    && (isset($_GET['sorot']) || (time() - $waktuSorot) <= 86400);

$jmlSorot = 0;
/* Fallback "per sumber" hanya dipakai bila tidak ada daftar id impor
 * (mis. berkas lama yang tidak menyimpan id_semua). Bila daftar id ada,
 * sorot hanya baris-baris itu agar tidak menyorot seluruh sumber. */
$pakaiSumber = ($kunciSorot === []);
$kunciSorotStr  = array_map('strval', $kunciSorot);
$sumberSorotStr = array_map('strval', $sumberSorot);
foreach ($titik as &$t) {
    $t['sorot'] = $sorotAktif && (
        in_array((string) $t['id'], $kunciSorotStr, true)
        || ($pakaiSumber && in_array((string) $t['sumber'], $sumberSorotStr, true))
    );
    if ($t['sorot']) {
        $jmlSorot++;
    }
}
unset($t);

$jsonTitik = json_encode($titik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonTitik === false) {
    $jsonTitik = '[]';
}

/* ---------------- Data grafik ---------------- */
$grafikSumber = [
    'Kasus'         => $jumlah['kasus'],
    'SRM'           => $jumlah['srm'],
    'CID'           => $jumlah['cid'],
    'Laporan Harian' => $jumlah['laporan'],
    'Absensi'       => $jumlah['absensi'],
    'Jadwal'        => $jumlah['jadwal'],
];

$grafikKab = [];
$res = $conn->query(
    "SELECT kabupaten_kota AS kab, COUNT(*) AS j FROM (
        SELECT kabupaten_kota FROM srm_kegiatan WHERE kabupaten_kota IS NOT NULL AND TRIM(kabupaten_kota) <> ''
        UNION ALL
        SELECT kabupaten_kota FROM cid_program WHERE kabupaten_kota IS NOT NULL AND TRIM(kabupaten_kota) <> ''
     ) x GROUP BY kab ORDER BY j DESC LIMIT 10"
);
while ($res && ($r = $res->fetch_assoc())) {
    $grafikKab[(string) $r['kab']] = (int) $r['j'];
}

$grafikLaporan = [];
$res = $conn->query(
    "SELECT DATE_FORMAT(tanggal, '%Y-%m') AS ym, COUNT(*) AS j
       FROM laporan_harian
      WHERE tanggal IS NOT NULL
      GROUP BY ym ORDER BY ym DESC LIMIT 12"
);
$barisLaporan = [];
while ($res && ($r = $res->fetch_assoc())) {
    $barisLaporan[] = $r;
}
$barisLaporan = array_reverse($barisLaporan); // urut lama -> baru
foreach ($barisLaporan as $r) {
    $grafikLaporan[(string) $r['ym']] = (int) $r['j'];
}

$daftarTahun = [];
$res = $conn->query(
    "SELECT DISTINCT tahun_intervensi AS v FROM srm_kegiatan WHERE tahun_intervensi IS NOT NULL
     UNION SELECT DISTINCT tahun_intervensi FROM cid_program WHERE tahun_intervensi IS NOT NULL
     ORDER BY v DESC"
);
while ($res && ($r = $res->fetch_assoc())) {
    $daftarTahun[] = (int) $r['v'];
}

$daftarKab = [];
$res = $conn->query(
    "SELECT DISTINCT kabupaten_kota AS v FROM srm_kegiatan WHERE kabupaten_kota IS NOT NULL
     UNION SELECT DISTINCT kabupaten_kota FROM cid_program WHERE kabupaten_kota IS NOT NULL
     ORDER BY v"
);
while ($res && ($r = $res->fetch_assoc())) {
    $daftarKab[] = (string) $r['v'];
}
// Jaring pengaman: buang varian beda besar-kecil/spasi agar tidak dobel.
$daftarKab = wilayah_unik($daftarKab);

/* ---------------- Tabel data terunggah (gabungan semua sumber) ----------------
 * Menampilkan baris data apa pun yang ada (termasuk yang belum berkoordinat),
 * agar pengguna bisa memverifikasi "file saya sudah masuk / keluar titiknya".
 */
$tabelDataBatas = 200;
$tabelData = [];

/** Tambah satu baris ke tabel data terunggah. */
function peta_baris_tabel(array &$out, string $sumber, string $judul, string $lokasi, mixed $lat, mixed $lng, mixed $tanggal, string $tautan): void
{
    $out[] = [
        'sumber'  => $sumber,
        'judul'   => $judul !== '' ? $judul : '-',
        'lokasi'  => $lokasi !== '' ? $lokasi : '-',
        'lat'     => is_numeric($lat) ? (float) $lat : null,
        'lng'     => is_numeric($lng) ? (float) $lng : null,
        'tanggal' => (string) ($tanggal ?? ''),
        'tautan'  => $tautan,
    ];
}

/** Jalankan SELECT sederhana dan kembalikan semua baris. */
function peta_ambil(mysqli $conn, string $sql): array
{
    $out = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $out[] = $r;
        }
        $res->free();
    }
    return $out;
}

$cariEsc = $conn->real_escape_string($fCari);
$kabEsc  = $conn->real_escape_string($fKab);

// Kasus
$w = $fCari !== ''
    ? " WHERE (judul LIKE '%{$cariEsc}%' OR nama_lokasi LIKE '%{$cariEsc}%' OR kode_kasus LIKE '%{$cariEsc}%')"
    : '';
foreach (peta_ambil($conn, "SELECT id, judul, nama_lokasi AS lokasi, garis_lintang AS lat, garis_bujur AS lng, DATE(dibuat_pada) AS tanggal
                              FROM kasus{$w} ORDER BY id DESC LIMIT 60") as $r) {
    peta_baris_tabel($tabelData, 'Kasus', (string) $r['judul'], (string) ($r['lokasi'] ?? ''), $r['lat'], $r['lng'], $r['tanggal'], 'detail_kasus.php?id=' . (int) $r['id']);
}

// SRM
$w = [];
if ($fCari !== '') { $w[] = "(judul_issue LIKE '%{$cariEsc}%' OR kelurahan LIKE '%{$cariEsc}%' OR kecamatan LIKE '%{$cariEsc}%')"; }
if ($kabEsc !== '') { $w[] = "kabupaten_kota = '{$kabEsc}'"; }
if ($tahunInt !== null) { $w[] = 'tahun_intervensi = ' . $tahunInt; }
$wc = $w !== [] ? ' WHERE ' . implode(' AND ', $w) : '';
foreach (peta_ambil($conn, "SELECT id, judul_issue AS judul, CONCAT_WS(', ', kelurahan, kecamatan, kabupaten_kota) AS lokasi,
                                   garis_lintang AS lat, garis_bujur AS lng, tahun_intervensi AS tanggal
                              FROM srm_kegiatan{$wc} ORDER BY id DESC LIMIT 60") as $r) {
    peta_baris_tabel($tabelData, 'SRM', (string) $r['judul'], (string) ($r['lokasi'] ?? ''), $r['lat'], $r['lng'], $r['tanggal'], 'srm.php?q=' . rawurlencode((string) $r['judul']));
}

// CID
$w = [];
if ($fCari !== '') { $w[] = "(judul_program LIKE '%{$cariEsc}%' OR desa LIKE '%{$cariEsc}%' OR kecamatan LIKE '%{$cariEsc}%')"; }
if ($kabEsc !== '') { $w[] = "kabupaten_kota = '{$kabEsc}'"; }
if ($tahunInt !== null) { $w[] = 'tahun_intervensi = ' . $tahunInt; }
$wc = $w !== [] ? ' WHERE ' . implode(' AND ', $w) : '';
foreach (peta_ambil($conn, "SELECT id, judul_program AS judul, CONCAT_WS(', ', desa, kecamatan, kabupaten_kota) AS lokasi,
                                   garis_lintang AS lat, garis_bujur AS lng, tahun_intervensi AS tanggal
                              FROM cid_program{$wc} ORDER BY id DESC LIMIT 60") as $r) {
    peta_baris_tabel($tabelData, 'CID', (string) $r['judul'], (string) ($r['lokasi'] ?? ''), $r['lat'], $r['lng'], $r['tanggal'], 'cid.php?q=' . rawurlencode((string) $r['judul']));
}

// Laporan Harian
$w = $fCari !== ''
    ? " WHERE (l.kegiatan LIKE '%{$cariEsc}%' OR l.lokasi_teks LIKE '%{$cariEsc}%' OR u.nama LIKE '%{$cariEsc}%')"
    : '';
foreach (peta_ambil($conn, "SELECT l.id, l.kegiatan AS judul, l.lokasi_teks AS lokasi, l.garis_lintang AS lat, l.garis_bujur AS lng,
                                   l.tanggal, u.nama AS pelapor
                              FROM laporan_harian l LEFT JOIN users u ON u.id = l.user_id{$w}
                              ORDER BY l.id DESC LIMIT 60") as $r) {
    peta_baris_tabel($tabelData, 'Laporan', (string) $r['judul'], (string) ($r['lokasi'] ?? '') . ($r['pelapor'] ? ' (' . $r['pelapor'] . ')' : ''), $r['lat'], $r['lng'], $r['tanggal'], 'detail_laporan.php?id=' . (int) $r['id']);
}

// Absensi
$w = $fCari !== ''
    ? " WHERE (a.lokasi_teks LIKE '%{$cariEsc}%' OR u.nama LIKE '%{$cariEsc}%')"
    : '';
foreach (peta_ambil($conn, "SELECT a.id, a.lokasi_teks AS lokasi, a.status, a.garis_lintang AS lat, a.garis_bujur AS lng,
                                   a.tanggal, u.nama AS pelapor
                              FROM absensi a LEFT JOIN users u ON u.id = a.user_id{$w}
                              ORDER BY a.id DESC LIMIT 60") as $r) {
    peta_baris_tabel($tabelData, 'Absensi', 'Absensi ' . (string) ($r['pelapor'] ?? '-'), (string) ($r['lokasi'] ?? ''), $r['lat'], $r['lng'], $r['tanggal'], 'absensi.php');
}

// Jadwal
$w = $fCari !== ''
    ? " WHERE (j.judul LIKE '%{$cariEsc}%' OR j.lokasi LIKE '%{$cariEsc}%' OR u.nama LIKE '%{$cariEsc}%')"
    : '';
foreach (peta_ambil($conn, "SELECT j.id, j.judul, j.lokasi, j.garis_lintang AS lat, j.garis_bujur AS lng, j.mulai, u.nama AS pelapor
                              FROM jadwal j LEFT JOIN users u ON u.id = j.user_id{$w}
                              ORDER BY j.id DESC LIMIT 60") as $r) {
    peta_baris_tabel($tabelData, 'Jadwal', (string) $r['judul'], (string) ($r['lokasi'] ?? ''), $r['lat'], $r['lng'], $r['mulai'], 'jadwal.php');
}

// Urutkan: data terbaru dahulu (tanpa tanggal di bawah), lalu batasi jumlah.
usort($tabelData, static function (array $a, array $b): int {
    $ta = $a['tanggal'] !== '' ? (int) strtotime($a['tanggal']) : 0;
    $tb = $b['tanggal'] !== '' ? (int) strtotime($b['tanggal']) : 0;
    return $tb <=> $ta;
});
$tabelDataJumlah = count($tabelData);
if ($tabelDataJumlah > $tabelDataBatas) {
    $tabelData = array_slice($tabelData, 0, $tabelDataBatas);
}

/* ---------------- Riwayat unggahan (dengan tombol sorot ulang) ---------------- */
$riwayatUnggah = [];
$uidRiwayat = (int) sesi_user_id();
$sqlRiwayat = in_array(sesi_role(), ['mentor', 'admin'], true)
    ? 'SELECT s.id, s.user_id, s.jenis, s.nama_berkas, s.jumlah_masuk, s.jumlah_gagal, s.sumber, s.dibuat_pada, u.nama
         FROM impor_sejarah s LEFT JOIN users u ON u.id = s.user_id
        ORDER BY s.id DESC LIMIT 20'
    : 'SELECT s.id, s.user_id, s.jenis, s.nama_berkas, s.jumlah_masuk, s.jumlah_gagal, s.sumber, s.dibuat_pada, u.nama
         FROM impor_sejarah s LEFT JOIN users u ON u.id = s.user_id
        WHERE s.user_id = ' . $uidRiwayat . '
        ORDER BY s.id DESC LIMIT 20';
$resRiwayat = $conn->query($sqlRiwayat);
while ($resRiwayat && ($r = $resRiwayat->fetch_assoc())) {
    $riwayatUnggah[] = $r;
}
if ($resRiwayat) { $resRiwayat->free(); }

$labelJenisImpor = static function (string $jenis): string {
    return match ($jenis) {
        'absensi'  => 'Absensi',
        'srm_cid'  => 'SRM & CID',
        default    => 'Laporan Harian',
    };
};

mulai_halaman([
    'judul' => 'Peta Lokasi',
    'crumb' => 'Data Sosial',
    'aktif' => 'peta',
    'head'  => '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
             . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>'
             . '<script src="assets/js/vendor/chart.umd.min.js" defer></script>'
             . '<script src="assets/js/grafik.js" defer></script>',
]);
?>

<style>
.peta-ringkas { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:12px; margin-bottom:14px; }
.peta-kotak { background:#fff; border:1px solid var(--abu-200); border-left:4px solid var(--phr-biru); border-radius:10px; padding:12px 14px; }
.peta-kotak .angka { font-size:20px; font-weight:800; color:var(--phr-biru-tua); display:block; line-height:1.15; }
.peta-kotak .ket { font-size:12px; color:#64748b; }
.peta-kotak.kasus { border-left-color:var(--phr-merah); }
.peta-kotak.srm { border-left-color:var(--phr-biru); }
.peta-kotak.cid { border-left-color:var(--phr-hijau); }
.peta-kotak.laporan { border-left-color:#D97706; }
.peta-kotak.absensi { border-left-color:#7C3AED; }
.peta-kotak.jadwal { border-left-color:#0F766E; }
#petaLokasi { height:520px; border-radius:10px; border:1px solid var(--abu-200); }
.legenda-titik { display:flex; gap:14px; flex-wrap:wrap; margin:10px 0 0; font-size:12.5px; }
.legenda-titik span { display:inline-flex; align-items:center; gap:6px; }
.legenda-titik i { width:12px; height:12px; border-radius:50%; display:inline-block; }
.peta-penyaring { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.peta-penyaring label { font-size:12.5px; font-weight:600; color:#475569; }
.peta-penyaring select, .peta-penyaring input { min-width:150px; }
.aksi-baris { display:flex; gap:8px; justify-content:center; align-items:center; margin-top:16px; flex-wrap:wrap; }
.geo-banner { display:flex; gap:12px; align-items:center; flex-wrap:wrap;
    background:#FFF7ED; border:1px solid #FED7AA; border-left:4px solid #D97706;
    border-radius:10px; padding:12px 14px; margin-bottom:14px; }
.geo-banner .ikon { display:inline-flex; color:#D97706; }
.geo-banner .isi { flex:1; min-width:200px; }
.geo-banner .judul { font-weight:700; color:#9A3412; font-size:13.5px; }
.geo-banner .ket { font-size:12.5px; color:#78350F; }
.geo-banner.sukses { background:#ECFDF5; border-color:#A7F3D0; border-left-color:var(--phr-hijau); }
.geo-banner.sukses .ikon { color:var(--phr-hijau); }
.geo-banner.sukses .judul { color:#065F46; }
.geo-banner.sukses .ket { color:#047857; }
.tabel-data-bungkus { overflow-x:auto; margin-top:4px; }
.tabel-data { width:100%; border-collapse:collapse; font-size:12.5px; }
.tabel-data th, .tabel-data td { padding:7px 9px; border-bottom:1px solid var(--abu-200); text-align:left; vertical-align:top; }
.tabel-data th { background:#F8FAFC; font-weight:700; color:#334155; position:sticky; top:0; white-space:nowrap; }
.tabel-data tr:hover td { background:#F8FAFC; }
.tabel-data .kolom-koord { white-space:nowrap; font-variant-numeric:tabular-nums; color:#475569; }
.label-sumber { display:inline-block; font-size:11px; font-weight:700; padding:2px 7px; border-radius:999px; white-space:nowrap; }
.label-sumber.kasus { background:#FEE2E2; color:#991B1B; }
.label-sumber.srm { background:#DBEAFE; color:#1E40AF; }
.label-sumber.cid { background:#DCFCE7; color:#166534; }
.label-sumber.laporan { background:#FFEDD5; color:#9A3412; }
.label-sumber.absensi { background:#EDE9FE; color:#5B21B6; }
.label-sumber.jadwal { background:#CCFBF1; color:#115E59; }
.badge-peta { display:inline-block; font-size:11px; font-weight:600; padding:2px 7px; border-radius:999px; }
.badge-peta.ada { background:#DCFCE7; color:#166534; }
.badge-peta.belum { background:#FEE2E2; color:#991B1B; }
.riwayat-unggah { display:flex; flex-direction:column; gap:8px; }
.riwayat-item { display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;
    border:1px solid var(--abu-200); border-radius:9px; padding:9px 12px; }
.riwayat-item .utama { flex:1; min-width:200px; }
.riwayat-item .nama { font-weight:600; font-size:13px; word-break:break-all; }
.riwayat-item .meta { font-size:11.5px; color:#64748b; }
.popup-foto { max-width:200px; border-radius:6px; margin:5px 0; display:block; }
</style>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Peta Lokasi Semua Data</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Sebaran lokasi Kasus, SRM, CID, Laporan Harian, Absensi, dan Jadwal.
            Klik penanda untuk melihat rincian dan membuka lokasi di Google Maps.
        </p>
    </div>
    <div class="kartu-badan">
        <?php if ($sorotAktif): ?>
        <div class="sorot-banner">
            <span class="ikon" style="color:var(--phr-hijau)"><?= ikon_sp('centang') ?></span>
            <div>
                <div class="sorot-judul">Data baru berhasil diunggah</div>
                <div class="sorot-ket">
                    <?= e(namaAsliLabelPeta((string) ($unggahan['berkas'] ?? 'berkas'))) ?>
                    &middot; <?= (int) $jmlSorot ?> titik ditandai
                    <?php if (!empty($unggahan['terisi'])): ?>
                        &middot; <?= (int) $unggahan['terisi'] ?> lokasi baru ditempatkan
                    <?php endif; ?>
                    <?php if (!empty($unggahan['tunggu'])): ?>
                        &middot; <?= (int) $unggahan['tunggu'] ?> lokasi menunggu geokode
                    <?php endif; ?>
                </div>
            </div>
            <div class="sorot-aksi">
                <a class="tombol tombol-garis tombol-kecil" href="impor_laporan.php">Unggah lagi</a>
                <a class="tombol tombol-kecil" href="peta.php?hapus_sorot=1">Hapus sorotan</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($geokodeHasil !== null && (int) $geokodeHasil['diproses'] > 0): ?>
        <div class="geo-banner sukses">
            <span class="ikon"><?= ikon_sp('peta') ?></span>
            <div class="isi">
                <div class="judul">Lokasi dilanjutkan otomatis</div>
                <div class="ket">
                    <?= (int) $geokodeHasil['terisi'] ?> lokasi baru ditempatkan
                    <?php if ((int) $geokodeHasil['gagal'] > 0): ?>&middot; <?= (int) $geokodeHasil['gagal'] ?> belum ditemukan<?php endif; ?>
                    &middot; sisa <?= (int) $geokodeHasil['sisa'] ?> menunggu geokode.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ((int) $menungguSisa['total'] > 0): ?>
        <div class="geo-banner">
            <span class="ikon"><?= ikon_sp('peringatan') ?></span>
            <div class="isi">
                <div class="judul"><?= number_format((int) $menungguSisa['total'], 0, ',', '.') ?> lokasi menunggu geokode</div>
                <div class="ket">
                    <?php
                    $bagianTunggu = [];
                    foreach ($menungguSisa['per_tabel'] as $tbl => $jmlT) {
                        $bagianTunggu[] = e($tbl) . ': ' . (int) $jmlT;
                    }
                    echo implode(' &middot; ', $bagianTunggu);
                    ?>
                    &mdash; diproses bertahap tiap peta dibuka. Klik tombol untuk memproses lebih banyak sekarang.
                </div>
            </div>
            <div class="sorot-aksi">
                <a class="tombol tombol-kecil" href="peta.php?proses_geokode=1&amp;tahun=<?= e($fTahun) ?>&amp;kabupaten=<?= e($fKab) ?>&amp;q=<?= e($fCari) ?>">Proses sisa lokasi</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="peta-ringkas">
            <div class="peta-kotak kasus"><span class="angka"><?= number_format($jumlah['kasus'], 0, ',', '.') ?></span><span class="ket">Kasus</span></div>
            <div class="peta-kotak srm"><span class="angka"><?= number_format($jumlah['srm'], 0, ',', '.') ?></span><span class="ket">SRM</span></div>
            <div class="peta-kotak cid"><span class="angka"><?= number_format($jumlah['cid'], 0, ',', '.') ?></span><span class="ket">CID</span></div>
            <div class="peta-kotak laporan"><span class="angka"><?= number_format($jumlah['laporan'], 0, ',', '.') ?></span><span class="ket">Laporan Harian</span></div>
            <div class="peta-kotak absensi"><span class="angka"><?= number_format($jumlah['absensi'], 0, ',', '.') ?></span><span class="ket">Absensi</span></div>
            <div class="peta-kotak jadwal"><span class="angka"><?= number_format($jumlah['jadwal'], 0, ',', '.') ?></span><span class="ket">Jadwal</span></div>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kartu-badan">
        <form class="peta-penyaring" method="get" action="peta.php">
            <label>Tahun (SRM/CID)
                <select name="tahun">
                    <option value="">Semua</option>
                    <?php foreach ($daftarTahun as $t): ?>
                        <option value="<?= (int) $t ?>" <?= $fTahun === (string) $t ? 'selected' : '' ?>><?= (int) $t ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kabupaten / Kota
                <select name="kabupaten">
                    <option value="">Semua</option>
                    <?php foreach ($daftarKab as $k): ?>
                        <option value="<?= e($k) ?>" <?= $fKab === $k ? 'selected' : '' ?>><?= e($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="flex:1;min-width:200px">Cari
                <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="judul, lokasi, nama...">
            </label>
            <button class="tombol tombol-utama" type="submit">Terapkan</button>
            <a class="tombol tombol-garis" href="peta.php">Reset</a>
        </form>

        <div class="legenda-titik" id="legendaPeta">
            <span><input type="checkbox" data-lapis="kasus" checked> <i style="background:#EB1C24"></i> Kasus</span>
            <span><input type="checkbox" data-lapis="srm" checked> <i style="background:#005EB8"></i> SRM</span>
            <span><input type="checkbox" data-lapis="cid" checked> <i style="background:#159447"></i> CID</span>
            <span><input type="checkbox" data-lapis="laporan" checked> <i style="background:#D97706"></i> Laporan Harian</span>
            <span><input type="checkbox" data-lapis="absensi" checked> <i style="background:#7C3AED"></i> Absensi</span>
            <span><input type="checkbox" data-lapis="jadwal" checked> <i style="background:#0F766E"></i> Jadwal</span>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2 style="font-size:15px">Peta Sebaran
            <span class="text-redup" style="font-weight:500;font-size:13px">(<?= number_format($jmlTotal, 0, ',', '.') ?> titik)</span>
        </h2>
    </div>
    <div class="kartu-badan">
        <div id="petaLokasi"></div>
        <p class="text-redup text-kecil" id="statusPeta" style="margin-top:8px">
            Memuat peta...
        </p>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2 style="font-size:15px">Visualisasi Data
            <span class="text-redup" style="font-weight:500;font-size:13px">(ringkasan sebaran &amp; tren)</span>
        </h2>
    </div>
    <div class="kartu-badan">
        <div class="panel-grafik">
            <div class="grafik-kotak">
                <h4>Sebaran Titik per Sumber</h4>
                <p class="grafik-ket">Jumlah titik peta sesuai filter saat ini.</p>
                <div class="grafik-kanvas"><canvas id="grafikSumber"></canvas></div>
            </div>
            <div class="grafik-kotak">
                <h4>Sepuluh Kabupaten/Kota Terbanyak</h4>
                <p class="grafik-ket">Berdasarkan kegiatan SRM &amp; program CID.</p>
                <div class="grafik-kanvas"><canvas id="grafikKab"></canvas></div>
            </div>
            <div class="grafik-kotak">
                <h4>Laporan Harian 12 Bulan Terakhir</h4>
                <p class="grafik-ket">Jumlah laporan per bulan.</p>
                <div class="grafik-kanvas"><canvas id="grafikLaporan"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2 style="font-size:15px">Data Terunggah
            <span class="text-redup" style="font-weight:500;font-size:13px">(<?= number_format($tabelDataJumlah, 0, ',', '.') ?> baris)</span>
        </h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Memastikan berkas yang diunggah benar-benar masuk. Baris bertanda &ldquo;Belum di peta&rdquo; belum punya koordinat.
        </p>
    </div>
    <div class="kartu-badan">
        <?php if ($tabelData === []): ?>
            <p class="text-redup">Belum ada data pada sumber ini.</p>
        <?php else: ?>
        <div class="tabel-data-bungkus">
            <table class="tabel-data">
                <thead>
                    <tr>
                        <th>Sumber</th><th>Judul / Keterangan</th><th>Lokasi</th>
                        <th>Status Peta</th><th>Koordinat</th><th>Tanggal</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tabelData as $baris): ?>
                    <?php $adaKoordinat = $baris['lat'] !== null && $baris['lng'] !== null; ?>
                    <tr>
                        <td><span class="label-sumber <?= e(strtolower($baris['sumber'])) ?>"><?= e($baris['sumber']) ?></span></td>
                        <td><?= e($baris['judul']) ?></td>
                        <td><?= e($baris['lokasi']) ?></td>
                        <td>
                            <?php if ($adaKoordinat): ?>
                                <span class="badge-peta ada">Di peta</span>
                            <?php else: ?>
                                <span class="badge-peta belum">Belum di peta</span>
                            <?php endif; ?>
                        </td>
                        <td class="kolom-koord">
                            <?= $adaKoordinat
                                ? number_format((float) $baris['lat'], 5, '.', '') . ', ' . number_format((float) $baris['lng'], 5, '.', '')
                                : '-' ?>
                        </td>
                        <td><?= e($baris['tanggal'] !== '' ? $baris['tanggal'] : '-') ?></td>
                        <td><a href="<?= e($baris['tautan']) ?>">Buka</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($tabelDataJumlah > $tabelDataBatas): ?>
            <p class="text-redup text-kecil" style="margin-top:8px">
                Menampilkan <?= (int) $tabelDataBatas ?> baris terbaru dari <?= number_format($tabelDataJumlah, 0, ',', '.') ?> baris.
            </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2 style="font-size:15px">Riwayat Unggahan</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Sorot kembali titik dari berkas yang pernah diunggah.
        </p>
    </div>
    <div class="kartu-badan">
        <?php if ($riwayatUnggah === []): ?>
            <p class="text-redup">Belum ada riwayat unggahan.</p>
        <?php else: ?>
        <div class="riwayat-unggah">
            <?php foreach ($riwayatUnggah as $ru): ?>
            <div class="riwayat-item">
                <div class="utama">
                    <div class="nama"><?= e(namaAsliLabelPeta((string) $ru['nama_berkas'])) ?></div>
                    <div class="meta">
                        <?= e($labelJenisImpor((string) $ru['jenis'])) ?>
                        &middot; <?= (int) $ru['jumlah_masuk'] ?> masuk
                        <?php if ((int) $ru['jumlah_gagal'] > 0): ?>&middot; <?= (int) $ru['jumlah_gagal'] ?> gagal<?php endif; ?>
                        <?php if (!empty($ru['nama'])): ?>&middot; oleh <?= e((string) $ru['nama']) ?><?php endif; ?>
                        &middot; <?= e(tanggal_id((string) $ru['dibuat_pada'], 'd M Y H:i')) ?>
                    </div>
                </div>
                <a class="tombol tombol-garis tombol-kecil" href="peta.php?sorot_unggah=<?= (int) $ru['id'] ?>">Sorot titik</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script id="dataGrafik" type="application/json"><?= json_encode([
    'sumber'  => $grafikSumber,
    'kab'     => $grafikKab,
    'laporan' => $grafikLaporan,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusPeta = document.getElementById('statusPeta');
    const dataTitik = <?= $jsonTitik ?>;

    if (typeof L === 'undefined') {
        statusPeta.textContent = 'Library peta gagal dimuat. Periksa koneksi internet.';
        return;
    }

    const peta = L.map('petaLokasi');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(peta);

    const warna = {
        kasus:   '#EB1C24',
        srm:     '#005EB8',
        cid:     '#159447',
        laporan: '#D97706',
        absensi: '#7C3AED',
        jadwal:  '#0F766E'
    };

    function aman(nilai) {
        return String(nilai ?? '').replace(/[&<>"']/g, function (k) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[k];
        });
    }

    const lapis = {};
    Object.keys(warna).forEach(function (k) {
        lapis[k] = L.layerGroup().addTo(peta);
    });

    const batas = [];
    const batasSorot = [];

    dataTitik.forEach(function (t) {
        const w = warna[t.sumber] || '#64748b';
        const disorot = t.sorot === true;
        const marker = L.circleMarker([t.lat, t.lng], {
            radius: disorot ? 11 : 7,
            color: disorot ? '#EB1C24' : w,
            fillColor: w,
            fillOpacity: disorot ? 0.95 : 0.85,
            weight: disorot ? 3 : 2
        });

        let isi = '<div style="min-width:200px;line-height:1.6">'
            + '<strong>' + aman(t.id) + '</strong><br>'
            + aman(t.judul) + '<br>'
            + '<span style="color:#64748b">' + aman(t.keterangan) + '</span><br>'
            + '<span style="color:#64748b;font-size:12px">'
            + t.lat.toFixed(5) + ', ' + t.lng.toFixed(5) + '</span><br>';

        if (t.foto_url) {
            isi += '<a href="' + aman(t.foto_url) + '" target="_blank" rel="noopener">'
                + '<img class="popup-foto" src="' + aman(t.foto_url) + '" alt="Foto bukti" loading="lazy">'
                + '</a>';
        }

        if (disorot) {
            isi += '<span class="badge badge-merah badge-tanpa-titik" style="margin:4px 0">Baru diunggah</span><br>';
        }

        if (t.peta_url) {
            isi += '<a href="' + aman(t.peta_url) + '" target="_blank" rel="noopener">'
                + 'Cek Lokasi (Google Maps)</a><br>';
        }
        isi += '<a href="https://www.google.com/maps/@' + t.lat + ',' + t.lng + ',18z" '
            + 'target="_blank" rel="noopener">Street View</a>';
        isi += '</div>';

        marker.bindPopup(isi);
        lapis[t.sumber].addLayer(marker);
        batas.push([t.lat, t.lng]);

        if (disorot) {
            batasSorot.push([t.lat, t.lng]);
            // Cincin denyut untuk menandai data yang baru diunggah.
            L.marker([t.lat, t.lng], {
                interactive: false,
                icon: L.divIcon({
                    className: 'penanda-sorot',
                    html: '<span class="denyut"></span>',
                    iconSize: [0, 0]
                })
            }).addTo(lapis[t.sumber]);
        }
    });

    if (batasSorot.length > 0) {
        peta.fitBounds(batasSorot, { padding: [40, 40], maxZoom: 13 });
        statusPeta.textContent = 'Menampilkan ' + batas.length + ' titik lokasi. '
            + batasSorot.length + ' di antaranya data yang baru diunggah.';
    } else if (batas.length > 0) {
        peta.fitBounds(batas, { padding: [30, 30], maxZoom: 12 });
        statusPeta.textContent = 'Menampilkan ' + batas.length + ' titik lokasi.';
    } else {
        peta.setView([1.28, 101.21], 9);
        statusPeta.textContent = 'Belum ada titik dengan koordinat sesuai filter.';
    }

    document.querySelectorAll('[data-lapis]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const k = cb.getAttribute('data-lapis');
            if (cb.checked) {
                peta.addLayer(lapis[k]);
            } else {
                peta.removeLayer(lapis[k]);
            }
        });
    });

    /* ---------------- Grafik ringkasan (Chart.js) ---------------- */
    (function () {
        const wadah = document.getElementById('dataGrafik');
        if (!wadah || typeof Chart === 'undefined') {
            return;
        }
        let g;
        try {
            g = JSON.parse(wadah.textContent || '{}');
        } catch (e) {
            return;
        }

        const palet = ['#005EB8', '#003D7D', '#159447', '#D97706', '#7C3AED', '#0F766E'];
        Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.color = '#475569';

        const elSumber = document.getElementById('grafikSumber');
        if (elSumber && g.sumber) {
            const label = Object.keys(g.sumber).filter(function (k) { return g.sumber[k] > 0; });
            new Chart(elSumber, {
                type: 'doughnut',
                data: {
                    labels: label,
                    datasets: [{
                        data: label.map(function (k) { return g.sumber[k]; }),
                        backgroundColor: palet,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } }
                    }
                }
            });
        }

        const elKab = document.getElementById('grafikKab');
        if (elKab && g.kab) {
            const kab = Object.keys(g.kab);
            new Chart(elKab, {
                type: 'bar',
                data: {
                    labels: kab,
                    datasets: [{
                        label: 'Kegiatan',
                        data: kab.map(function (k) { return g.kab[k]; }),
                        backgroundColor: '#005EB8',
                        borderRadius: 5
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }

        const elLap = document.getElementById('grafikLaporan');
        if (elLap && g.laporan) {
            const bulan = Object.keys(g.laporan);
            new Chart(elLap, {
                type: 'bar',
                data: {
                    labels: bulan,
                    datasets: [{
                        label: 'Laporan',
                        data: bulan.map(function (k) { return g.laporan[k]; }),
                        backgroundColor: '#159447',
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }
    })();
});
</script>

<?php
akhiri_halaman();
