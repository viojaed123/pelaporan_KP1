<?php
/**
 * mentor_dashboard.php - ruang kerja mentor & admin.
 *
 * Ringkasan:
 *   - bootstrap + penjaga peran mentor/admin
 *   - layout terpusat (memakai kelas app.css)
 *   - statistik, filter status, dan pencarian laporan
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

$user_id = (int) sesi_user_id();

$mentor = user_dari_db($conn, $user_id);
if ($mentor === null) {
    $mentor = ['nama' => sesi_nama(), 'role' => sesi_role()];
}

/* ---------------------------------------------------------------
 * Statistik seluruh laporan yang sudah dikirim.
 * ------------------------------------------------------------- */

$sqlStat = "
    SELECT status, COUNT(*) AS jumlah
    FROM laporan_harian
    WHERE status IN ('dikirim', 'diperiksa', 'perlu_revisi')
    GROUP BY status
";

$stat = [
    'dikirim'      => 0,
    'diperiksa'    => 0,
    'perlu_revisi' => 0,
];

$resultStat = $conn->query($sqlStat);
if ($resultStat) {
    while ($row = $resultStat->fetch_assoc()) {
        if (isset($stat[$row['status']])) {
            $stat[$row['status']] = (int) $row['jumlah'];
        }
    }
    $resultStat->free();
}

$totalLaporan = array_sum($stat);

/* ---------------------------------------------------------------
 * Filter status & pencarian laporan.
 * ------------------------------------------------------------- */

$statusFilter = $_GET['status'] ?? 'semua';
if (!is_string($statusFilter)) {
    $statusFilter = 'semua';
}
$statusValid  = ['semua', 'dikirim', 'diperiksa', 'perlu_revisi'];
if (!in_array($statusFilter, $statusValid, true)) {
    $statusFilter = 'semua';
}

$cari = $_GET['q'] ?? '';
if (!is_string($cari)) {
    $cari = '';
}
$cari = trim(substr($cari, 0, 100));

/* ---------------------------------------------------------------
 * Query daftar laporan (dengan paginasi).
 * ------------------------------------------------------------- */

$perHalaman = 25;

$halaman = isset($_GET['hal']) ? (int) $_GET['hal'] : 1;
if ($halaman < 1) {
    $halaman = 1;
}

$where = "
    FROM laporan_harian l
    JOIN users u ON u.id = l.user_id
    WHERE l.status IN ('dikirim', 'diperiksa', 'perlu_revisi')
";

$params = [];
$types  = '';

if ($statusFilter !== 'semua') {
    $where .= ' AND l.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($cari !== '') {
    $where .= "
        AND (
            u.nama LIKE ?
            OR u.divisi LIKE ?
            OR l.kegiatan LIKE ?
        )
    ";
    $types .= 'sss';
    $kata = '%' . $cari . '%';
    $params[] = $kata;
    $params[] = $kata;
    $params[] = $kata;
}

/* Hitung total baris (untuk paginasi). */
$sqlHitung  = 'SELECT COUNT(*) AS total ' . $where;
$stmtHitung = $conn->prepare($sqlHitung);

if (!$stmtHitung) {
    error_log('Gagal menyiapkan query hitung dashboard mentor.');
    http_response_code(500);
    exit('Terjadi kesalahan saat memuat laporan.');
}

if ($types !== '') {
    $stmtHitung->bind_param($types, ...$params);
}
$stmtHitung->execute();
$hasilHitung = $stmtHitung->get_result();
$totalBaris  = (int) ($hasilHitung->fetch_assoc()['total'] ?? 0);
$stmtHitung->close();

$totalHalaman = max(1, (int) ceil($totalBaris / $perHalaman));
if ($halaman > $totalHalaman) {
    $halaman = $totalHalaman;
}
$offset    = ($halaman - 1) * $perHalaman;
$nomorAwal = $offset + 1;

$sql = 'SELECT
        l.id,
        l.tanggal,
        l.kegiatan,
        l.status,
        u.nama AS nama_karyawan,
        u.divisi '
    . $where
    . " ORDER BY
        CASE WHEN l.status = 'dikirim' THEN 0 ELSE 1 END,
        l.tanggal DESC,
        l.id DESC
    LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Gagal menyiapkan query dashboard mentor.');
    http_response_code(500);
    exit('Terjadi kesalahan saat memuat laporan.');
}

$typesData  = $types . 'ii';
$paramsData = $params;
$paramsData[] = $perHalaman;
$paramsData[] = $offset;
$stmt->bind_param($typesData, ...$paramsData);

$stmt->execute();
$hasilLaporan = $stmt->get_result();
$laporanList  = [];
while ($row = $hasilLaporan->fetch_assoc()) {
    $laporanList[] = $row;
}
$stmt->close();

/* Parameter dasar untuk membangun tautan paginasi & chip filter. */
$tautanDasar = ['q' => $cari, 'status' => $statusFilter];

$jumlahNotifikasi = jumlah_notifikasi($conn, $user_id);

/* ---------------------------------------------------------------
 * Data grafik (Chart.js vendor lokal: assets/js/vendor + grafik.js).
 * ------------------------------------------------------------- */

/* Tren 14 hari laporan masuk (seluruh status terkirim). */
$tren14 = [];
$res = $conn->query(
    "SELECT DATE(tanggal) AS tgl, status, COUNT(*) AS jumlah
       FROM laporan_harian
      WHERE status IN ('dikirim', 'diperiksa', 'perlu_revisi')
        AND tanggal >= CURDATE() - INTERVAL 13 DAY
      GROUP BY DATE(tanggal), status"
);
while ($res && ($r = $res->fetch_assoc())) {
    $tren14[(string) $r['tgl']][(string) $r['status']] = (int) $r['jumlah'];
}

$labelTren   = [];
$trenKirim   = [];
$trenPeriksa = [];
$trenRevisi  = [];
for ($i = 13; $i >= 0; $i--) {
    $tgl           = date('Y-m-d', strtotime("-{$i} days"));
    $baris         = $tren14[$tgl] ?? [];
    $labelTren[]   = tanggal_id($tgl, 'd M');
    $trenKirim[]   = $baris['dikirim'] ?? 0;
    $trenPeriksa[] = $baris['diperiksa'] ?? 0;
    $trenRevisi[]  = $baris['perlu_revisi'] ?? 0;
}

/* Laporan terkirim per divisi (8 teratas). */
$perDivisi = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(u.divisi), ''), '(Tanpa Divisi)') AS divisi,
            COUNT(*) AS jumlah
       FROM laporan_harian l
       JOIN users u ON u.id = l.user_id
      WHERE l.status IN ('dikirim', 'diperiksa', 'perlu_revisi')
      GROUP BY COALESCE(NULLIF(TRIM(u.divisi), ''), '(Tanpa Divisi)')
      ORDER BY jumlah DESC
      LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $perDivisi[] = ['divisi' => (string) $r['divisi'], 'jumlah' => (int) $r['jumlah']];
}

$flagsJson    = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$labelTrenJson  = json_encode($labelTren, $flagsJson) ?: '[]';
$trenKirimJson  = json_encode($trenKirim) ?: '[]';
$trenPeriksaJson = json_encode($trenPeriksa) ?: '[]';
$trenRevisiJson = json_encode($trenRevisi) ?: '[]';
$labelDivisiJson = json_encode(array_column($perDivisi, 'divisi'), $flagsJson) ?: '[]';
$jumlahDivisiJson = json_encode(array_column($perDivisi, 'jumlah')) ?: '[]';

/* ---------------------------------------------------------------
 * Grafik lanjutan: per karyawan, absensi tim, komentar.
 * ------------------------------------------------------------- */

/* Laporan terkirim per karyawan (8 teratas). */
$perKaryawan = [];
$res = $conn->query(
    "SELECT u.nama AS nama, COUNT(*) AS jumlah
       FROM laporan_harian l
       JOIN users u ON u.id = l.user_id
      WHERE l.status IN ('dikirim', 'diperiksa', 'perlu_revisi')
      GROUP BY u.nama
      ORDER BY jumlah DESC
      LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $perKaryawan[] = ['nama' => (string) $r['nama'], 'jumlah' => (int) $r['jumlah']];
}

/* Absensi tim 30 hari terakhir per status. */
$absensiTim = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
$res = $conn->query(
    "SELECT status, COUNT(*) AS jumlah FROM absensi
      WHERE tanggal >= CURDATE() - INTERVAL 29 DAY GROUP BY status"
);
while ($res && ($r = $res->fetch_assoc())) {
    $k = strtolower((string) ($r['status'] ?? ''));
    if (array_key_exists($k, $absensiTim)) {
        $absensiTim[$k] = (int) $r['jumlah'];
    }
}

/* Kehadiran per divisi 30 hari (hadir vs tidak hadir). */
$divisiAbsensi = [];
$res = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(u.divisi), ''), '(Tanpa Divisi)') AS divisi,
            SUM(a.status = 'hadir') AS hadir, COUNT(*) AS total
       FROM absensi a JOIN users u ON u.id = a.user_id
      WHERE a.tanggal >= CURDATE() - INTERVAL 29 DAY
      GROUP BY 1 ORDER BY total DESC LIMIT 8"
);
while ($res && ($r = $res->fetch_assoc())) {
    $hadir = (int) $r['hadir'];
    $divisiAbsensi[] = [
        'label' => (string) $r['divisi'],
        'hadir' => $hadir,
        'tidak' => (int) $r['total'] - $hadir,
    ];
}

/* Komentar pemeriksaan 30 hari (per hari). */
$komentar30 = [];
$res = $conn->query(
    'SELECT DATE(dibuat_pada) AS tgl, COUNT(*) AS jumlah FROM komentar_laporan
      WHERE dibuat_pada >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(dibuat_pada)'
);
while ($res && ($r = $res->fetch_assoc())) {
    $komentar30[(string) $r['tgl']] = (int) $r['jumlah'];
}
$labelKomentar = [];
$angkaKomentar = [];
for ($i = 29; $i >= 0; $i--) {
    $tgl            = date('Y-m-d', strtotime("-{$i} days"));
    $labelKomentar[] = tanggal_id($tgl, 'd M');
    $angkaKomentar[] = $komentar30[$tgl] ?? 0;
}

$labelKaryawanJson  = json_encode(array_column($perKaryawan, 'nama'), $flagsJson) ?: '[]';
$jumlahKaryawanJson = json_encode(array_column($perKaryawan, 'jumlah')) ?: '[]';
$labelDivAbsJson    = json_encode(array_column($divisiAbsensi, 'label'), $flagsJson) ?: '[]';
$hadirDivAbsJson    = json_encode(array_column($divisiAbsensi, 'hadir')) ?: '[]';
$tidakDivAbsJson    = json_encode(array_column($divisiAbsensi, 'tidak')) ?: '[]';
$labelKomentarJson  = json_encode($labelKomentar, $flagsJson) ?: '[]';
$angkaKomentarJson  = json_encode($angkaKomentar) ?: '[]';

$mentorHead = '<script src="assets/js/vendor/chart.umd.min.js" defer></script>'
    . '<script src="assets/js/grafik.js" defer></script>';

$opsi = [
    'judul' => 'Dashboard Mentor',
    'crumb' => 'Mentor',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="rekap_laporan.php">Rekap Laporan</a>',
    'head'  => $mentorHead,
];

mulai_halaman($opsi);
?>

<!-- ================= SAMBUTAN ================= -->
<section class="kartu" style="background:linear-gradient(135deg, var(--navy-600), var(--navy-700) 55%, var(--phr-biru) 165%);border:0;color:#fff;">
    <div class="kartu-tubuh" style="padding:26px 26px 24px">
        <span class="badge badge-tanpa-titik" style="background:rgba(255,255,255,.16);color:#cfe6f7">
            PORTAL MENTOR <?= e(APP_ORGANISASI) ?>
        </span>
        <h2 style="color:#fff;font-size:23px;margin:12px 0 6px">
            Selamat datang, <?= e((string) $mentor['nama']) ?>!
        </h2>
        <p style="color:#c2d7ea;max-width:640px;margin-bottom:18px">
            Pantau laporan kegiatan karyawan, tinjau bukti, dan berikan
            keputusan pemeriksaan melalui halaman ini.
        </p>
        <div class="d-flex gap-2 flex-wrap">
            <a class="tombol" style="background:#fff;border-color:#fff;color:var(--navy-600)" href="rekap_laporan.php">Rekap Laporan</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="rekap_absensi.php">Rekap Excel &amp; PDF</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="mentor_absensi.php">Absensi Karyawan</a>
        </div>
    </div>
</section>

<!-- ================= STATISTIK ================= -->
<h2 class="bagian-judul">Ringkasan Laporan</h2>

<section class="grid grid-4">
    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('dokumen') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $totalLaporan ?></div>
            <div class="keterangan">Total Laporan Terkirim</div>
            <div class="tambahan">Seluruh laporan yang sudah dikirim</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('jam') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $stat['dikirim'] ?></div>
            <div class="keterangan">Menunggu Pemeriksaan</div>
            <div class="tambahan">Perlu ditinjau segera</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('centang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $stat['diperiksa'] ?></div>
            <div class="keterangan">Sudah Diperiksa</div>
            <div class="tambahan">Laporan selesai diperiksa</div>
        </div>
    </div>

    <div class="stat merah">
        <span class="ikon"><?= ikon_sp('silang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $stat['perlu_revisi'] ?></div>
            <div class="keterangan">Perlu Revisi</div>
            <div class="tambahan">Menunggu perbaikan karyawan</div>
        </div>
    </div>
</section>

<!-- ================= SOROTAN CEPAT ================= -->
<section class="sorotan-ringkas" style="margin-top:16px">
    <a class="s-chip" href="mentor_dashboard.php?status=dikirim">
        <span class="s-ikon">&#9203;</span>
        <span class="s-teks">
            <span class="s-judul">Perlu Ditinjau</span>
            <span class="s-ket"><?= (int) $stat['dikirim'] ?> laporan menunggu</span>
        </span>
    </a>
    <a class="s-chip" href="mentor_dashboard.php?status=diperiksa">
        <span class="s-ikon">&#10003;</span>
        <span class="s-teks">
            <span class="s-judul">Sudah Diperiksa</span>
            <span class="s-ket"><?= (int) $stat['diperiksa'] ?> laporan selesai</span>
        </span>
    </a>
    <a class="s-chip" href="mentor_dashboard.php?status=perlu_revisi">
        <span class="s-ikon">&#9998;</span>
        <span class="s-teks">
            <span class="s-judul">Perlu Revisi</span>
            <span class="s-ket"><?= (int) $stat['perlu_revisi'] ?> laporan perbaikan</span>
        </span>
    </a>
    <a class="s-chip" href="rekap_laporan.php">
        <span class="s-ikon">&#128202;</span>
        <span class="s-teks">
            <span class="s-judul">Rekap &amp; Ekspor</span>
            <span class="s-ket"><?= (int) $totalLaporan ?> laporan terkirim</span>
        </span>
    </a>
</section>

<!-- ================= GRAFIK ================= -->
<h2 class="bagian-judul">Analitik Visual</h2>

<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Status Laporan Terkirim</h3>
                <span class="text-redup text-kecil">Proporsi laporan menunggu, diperiksa, dan revisi.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:250px">
                <canvas id="grafikStatusMentor" aria-label="Status laporan terkirim" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Tren Laporan Masuk 14 Hari</h3>
                <span class="text-redup text-kecil">Jumlah laporan karyawan per tanggal.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:250px">
                <canvas id="grafikTrenMentor" aria-label="Tren laporan masuk 14 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Laporan per Divisi (8 Teratas)</h3>
                <span class="text-redup text-kecil">Distribusi laporan terkirim berdasarkan divisi karyawan.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:260px">
                <canvas id="grafikDivisiMentor" aria-label="Laporan per divisi" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= GRAFIK LANJUTAN ================= -->
<section class="grid grid-2" style="margin-top:20px">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Laporan per Karyawan (8 Teratas)</h3>
                <span class="text-redup text-kecil">Karyawan dengan laporan terkirim terbanyak.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikKaryawanMentor" aria-label="Laporan per karyawan" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Absensi Tim 30 Hari</h3>
                <span class="text-redup text-kecil">Status kehadiran seluruh karyawan bulan berjalan.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:280px">
                <canvas id="grafikAbsensiMentor" aria-label="Absensi tim 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kehadiran per Divisi 30 Hari</h3>
                <span class="text-redup text-kecil">Catatan hadir vs tidak hadir (izin, sakit, alpa) per divisi.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:270px">
                <canvas id="grafikDivAbsMentor" aria-label="Kehadiran per divisi" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0;grid-column:1/-1">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Komentar Pemeriksaan 30 Hari</h3>
                <span class="text-redup text-kecil">Jumlah komentar yang Anda berikan pada laporan per hari.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:250px">
                <canvas id="grafikKomentarMentor" aria-label="Komentar pemeriksaan 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= FILTER ================= -->
<form method="GET" action="mentor_dashboard.php" class="filter-bar" style="margin-top:20px">
    <div class="grup-form">
        <label class="label-form" for="q">Cari laporan</label>
        <input type="search" id="q" name="q"
               placeholder="Cari nama, divisi, atau kegiatan..."
               value="<?= e($cari) ?>">
    </div>

    <div class="grup-form">
        <label class="label-form" for="status">Status laporan</label>
        <select id="status" name="status">
            <option value="semua"          <?= $statusFilter === 'semua' ? 'selected' : '' ?>>Semua status</option>
            <option value="dikirim"        <?= $statusFilter === 'dikirim' ? 'selected' : '' ?>>Menunggu Pemeriksaan</option>
            <option value="diperiksa"      <?= $statusFilter === 'diperiksa' ? 'selected' : '' ?>>Diperiksa</option>
            <option value="perlu_revisi"   <?= $statusFilter === 'perlu_revisi' ? 'selected' : '' ?>>Perlu Revisi</option>
        </select>
    </div>

    <div class="aksi-filter">
        <button type="submit" class="tombol tombol-utama">Cari</button>
        <a class="tombol" href="mentor_dashboard.php">Reset</a>
    </div>
</form>

<!-- ================= CHIP FILTER ================= -->
<?php
$chipStatus = [
    'semua'        => 'Semua',
    'dikirim'      => 'Menunggu',
    'diperiksa'    => 'Diperiksa',
    'perlu_revisi' => 'Perlu Revisi',
];
?>
<nav class="chip-filter" aria-label="Filter status cepat">
    <?php foreach ($chipStatus as $nilai => $teks): ?>
        <?php
        $tautanChip = 'mentor_dashboard.php?' . http_build_query([
            'q'      => $cari,
            'status' => $nilai,
        ]);
        ?>
        <a class="chip<?= $statusFilter === $nilai ? ' aktif' : '' ?>"
           href="<?= e($tautanChip) ?>"><?= e($teks) ?></a>
    <?php endforeach; ?>
</nav>

<!-- ================= DAFTAR LAPORAN ================= -->
<section class="kartu">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Daftar Laporan Karyawan</h3>
            <span class="text-redup text-kecil">
                Laporan draft tidak ditampilkan. Laporan yang menunggu pemeriksaan ditampilkan lebih dahulu.
            </span>
        </div>
        <span class="badge badge-navy badge-tanpa-titik"><?= (int) $totalBaris ?> laporan</span>
    </div>

    <div class="kartu-tubuh padat">
        <form method="post" action="mentor_bulk.php" id="formBulk">
            <?= csrf_field('mentor_bulk') ?>

            <div class="baris-massal">
                <label class="pilih-semua" for="centangSemua">
                    <input type="checkbox" id="centangSemua">
                    <span>Pilih semua yang menunggu</span>
                </label>

                <select name="keputusan" id="keputusanMassal" class="isian-massal">
                    <option value="diperiksa">Tandai diperiksa</option>
                    <option value="perlu_revisi">Minta revisi</option>
                </select>

                <input type="text" name="catatan_mentor" id="catatanMassal" maxlength="5000"
                       class="isian-massal catatan-massal"
                       placeholder="Catatan (wajib bila minta revisi)">

                <button type="submit" class="tombol tombol-utama tombol-kecil">
                    Terapkan ke terpilih
                </button>

                <span class="text-kecil text-redup" id="hitungTerpilih">0 dipilih</span>
            </div>

            <div class="bungkus-tabel">
                <table class="tabel tabel-padat tabel-lengket">
                    <thead>
                        <tr>
                            <th style="width:38px"></th>
                            <th>No.</th>
                            <th>Nama Karyawan</th>
                            <th>Divisi</th>
                            <th>Tanggal</th>
                            <th>Kegiatan</th>
                            <th>Status</th>
                            <th class="text-kanan">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($laporanList) > 0): ?>
                        <?php $no = $nomorAwal; ?>
                        <?php foreach ($laporanList as $row): ?>
                            <?php $bisaDiproses = ($row['status'] === 'dikirim'); ?>
                            <tr>
                                <td data-label="Pilih"<?= $bisaDiproses ? '' : ' class="sel-kosong"' ?>>
                                    <?php if ($bisaDiproses): ?>
                                        <input type="checkbox" class="centang-laporan"
                                               name="laporan_id[]" value="<?= (int) $row['id'] ?>"
                                               aria-label="Pilih laporan <?= (int) $row['id'] ?>">
                                    <?php endif; ?>
                                </td>
                                <td data-label="No."><?= (int) $no++ ?></td>
                                <td data-label="Nama"><?= e((string) $row['nama_karyawan']) ?></td>
                                <td data-label="Divisi"><?= e((string) ($row['divisi'] ?? '-')) ?></td>
                                <td data-label="Tanggal" class="text-kecil text-redup"><?= e(tanggal_id((string) $row['tanggal'])) ?></td>
                                <td data-label="Kegiatan">
                                    <span class="potong-2"><?= e(potong((string) $row['kegiatan'], 80)) ?></span>
                                </td>
                                <td data-label="Status"><?= badge_status((string) $row['status']) ?></td>
                                <td data-label="Aksi" class="text-kanan">
                                    <a class="tombol tombol-utama tombol-kecil"
                                       href="mentor_detail.php?id=<?= (int) $row['id'] ?>">
                                        Lihat Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="tabel-kosong">
                                <span class="ikon"><?= ikon_sp('grafik') ?></span>
                                Tidak ada laporan yang sesuai dengan pencarian atau filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalHalaman > 1): ?>
                <nav class="paginasi" aria-label="Navigasi halaman">
                    <?php if ($halaman > 1): ?>
                        <a href="mentor_dashboard.php?<?= e(http_build_query($tautanDasar + ['hal' => $halaman - 1])) ?>">&laquo; Sebelumnya</a>
                    <?php else: ?>
                        <span class="mati">&laquo; Sebelumnya</span>
                    <?php endif; ?>

                    <span class="info-halaman">Halaman <?= (int) $halaman ?> dari <?= (int) $totalHalaman ?></span>

                    <?php if ($halaman < $totalHalaman): ?>
                        <a href="mentor_dashboard.php?<?= e(http_build_query($tautanDasar + ['hal' => $halaman + 1])) ?>">Berikutnya &raquo;</a>
                    <?php else: ?>
                        <span class="mati">Berikutnya &raquo;</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </form>
    </div>
</section>

<script>
(function () {
    var semua = document.getElementById('centangSemua');
    var hitung = document.getElementById('hitungTerpilih');
    var keputusan = document.getElementById('keputusanMassal');
    var catatan = document.getElementById('catatanMassal');

    function kotak() {
        return Array.prototype.slice.call(document.querySelectorAll('.centang-laporan'));
    }

    function perbarui() {
        var daftar = kotak();
        var n = daftar.filter(function (c) { return c.checked; }).length;
        if (hitung) {
            hitung.textContent = n + ' dipilih';
        }
        if (semua) {
            semua.checked = (daftar.length > 0 && n === daftar.length);
            semua.indeterminate = (n > 0 && n < daftar.length);
        }
    }

    if (semua) {
        semua.addEventListener('change', function () {
            kotak().forEach(function (c) { c.checked = semua.checked; });
            perbarui();
        });
    }

    kotak().forEach(function (c) { c.addEventListener('change', perbarui); });

    if (keputusan && catatan) {
        var sinkronCatatan = function () {
            var perlu = (keputusan.value === 'perlu_revisi');
            catatan.required = perlu;
            catatan.style.display = perlu ? '' : 'none';
            if (!perlu) { catatan.value = ''; }
        };
        keputusan.addEventListener('change', sinkronCatatan);
        sinkronCatatan();
    }

    perbarui();
})();
</script>

<!-- ================= JAVASCRIPT GRAFIK ================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof grafikSiap === 'undefined') return;

    var W = window.WARNA_GRAFIK;

    grafikSiap(function (Chart) {
        /* Donut status laporan terkirim. */
        var cStatus = document.getElementById('grafikStatusMentor');
        if (cStatus) {
            new Chart(cStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Menunggu', 'Diperiksa', 'Perlu Revisi'],
                    datasets: [{
                        data: <?= json_encode([$stat['dikirim'], $stat['diperiksa'], $stat['perlu_revisi']]) ?: '[]' ?>,
                        backgroundColor: [W.kuning, W.hijau, W.merah],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                }
            });
        }

        /* Tren 14 hari (garis bertumpuk per status). */
        var cTren = document.getElementById('grafikTrenMentor');
        if (cTren) {
            new Chart(cTren, {
                type: 'line',
                data: {
                    labels: <?= $labelTrenJson ?>,
                    datasets: [
                        { label: 'Menunggu', data: <?= $trenKirimJson ?>, borderColor: W.kuning, backgroundColor: 'rgba(214,158,46,.10)', fill: true, tension: .35, pointRadius: 2.5 },
                        { label: 'Diperiksa', data: <?= $trenPeriksaJson ?>, borderColor: W.hijau, backgroundColor: 'rgba(21,148,71,.10)', fill: true, tension: .35, pointRadius: 2.5 },
                        { label: 'Perlu Revisi', data: <?= $trenRevisiJson ?>, borderColor: W.merah, backgroundColor: 'rgba(235,28,36,.08)', fill: true, tension: .35, pointRadius: 2.5 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                }
            });
        }

        /* Bar per divisi. */
        var cDivisi = document.getElementById('grafikDivisiMentor');
        if (cDivisi) {
            if (!window.grafikAdaData(<?= $jumlahDivisiJson ?>)) {
                window.grafikKosong('grafikDivisiMentor', 'Belum ada laporan terkirim.');
            } else {
                new Chart(cDivisi, {
                    type: 'bar',
                    data: {
                        labels: <?= $labelDivisiJson ?>,
                        datasets: [{
                            label: 'Laporan',
                            data: <?= $jumlahDivisiJson ?>,
                            backgroundColor: W.biru,
                            borderRadius: 6,
                            maxBarThickness: 48
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        }

        /* Laporan per karyawan (batang horizontal). */
        var cKaryawan = document.getElementById('grafikKaryawanMentor');
        if (cKaryawan) {
            if (!window.grafikAdaData(<?= $jumlahKaryawanJson ?>)) {
                window.grafikKosong('grafikKaryawanMentor', 'Belum ada laporan terkirim.');
            } else {
                new Chart(cKaryawan, {
                    type: 'bar',
                    data: {
                        labels: <?= $labelKaryawanJson ?>,
                        datasets: [{
                            label: 'Laporan',
                            data: <?= $jumlahKaryawanJson ?>,
                            backgroundColor: W.navy,
                            borderRadius: 6,
                            maxBarThickness: 26
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: { beginAtZero: true, ticks: { precision: 0 } },
                            y: { grid: { display: false } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        }

        /* Absensi tim 30 hari (donat). */
        var cAbsTim = document.getElementById('grafikAbsensiMentor');
        if (cAbsTim) {
            var dataAbsTim = <?= json_encode([$absensiTim['hadir'], $absensiTim['izin'], $absensiTim['sakit'], $absensiTim['alpa']]) ?: '[]' ?>;
            if (!window.grafikAdaData(dataAbsTim)) {
                window.grafikKosong('grafikAbsensiMentor', 'Belum ada catatan absensi 30 hari terakhir.');
            } else {
                new Chart(cAbsTim, {
                    type: 'doughnut',
                    data: {
                        labels: ['Hadir', 'Izin', 'Sakit', 'Alpa'],
                        datasets: [{
                            data: dataAbsTim,
                            backgroundColor: window.WARNA_ABSENSI,
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
                    }
                });
            }
        }

        /* Kehadiran per divisi 30 hari (batang bertumpuk). */
        var cDivAbs = document.getElementById('grafikDivAbsMentor');
        if (cDivAbs) {
            var dataDivAbs = <?= json_encode(array_map(static fn (array $d): int => $d['hadir'] + $d['tidak'], $divisiAbsensi)) ?: '[]' ?>;
            if (!window.grafikAdaData(dataDivAbs)) {
                window.grafikKosong('grafikDivAbsMentor', 'Belum ada catatan absensi 30 hari terakhir.');
            } else {
                new Chart(cDivAbs, {
                    type: 'bar',
                    data: {
                        labels: <?= $labelDivAbsJson ?>,
                        datasets: [
                            { label: 'Hadir', data: <?= $hadirDivAbsJson ?>, backgroundColor: W.hijau, borderRadius: 4 },
                            { label: 'Tidak Hadir', data: <?= $tidakDivAbsJson ?>, backgroundColor: W.merah, borderRadius: 4 }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { stacked: true, grid: { display: false } },
                            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } }
                    }
                });
            }
        }

        /* Komentar pemeriksaan 30 hari (garis). */
        var cKomentar = document.getElementById('grafikKomentarMentor');
        if (cKomentar) {
            if (!window.grafikAdaData(<?= $angkaKomentarJson ?>)) {
                window.grafikKosong('grafikKomentarMentor', 'Belum ada komentar pemeriksaan 30 hari terakhir.');
            } else {
                new Chart(cKomentar, {
                    type: 'line',
                    data: {
                        labels: <?= $labelKomentarJson ?>,
                        datasets: [{
                            label: 'Komentar',
                            data: <?= $angkaKomentarJson ?>,
                            borderColor: W.kuning,
                            backgroundColor: 'rgba(214,158,46,.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2.5,
                            pointBackgroundColor: W.kuning
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        }
    });
});
</script>



<?php akhiri_halaman(); ?>
