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

$opsi = [
    'judul' => 'Dashboard Mentor',
    'crumb' => 'Mentor',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="rekap_laporan.php">Rekap Laporan</a>',
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

<?php akhiri_halaman(); ?>
