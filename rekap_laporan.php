<?php
/**
 * rekap_laporan.php — rekap & pencarian laporan kegiatan karyawan.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran mentor/admin (semula cek $_SESSION manual)
 *   · layout terpusat — <style> inline & header lama dihapus
 *   · tautan mati admin_dashboard.php -> mentor_dashboard.php
 *   · seluruh parameter filter & tautan ekspor (export_pdf / export_excel)
 *     dipertahankan apa adanya
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

$role = sesi_role();

function tanggalValid(string $tanggal): bool
{
    if ($tanggal === '') {
        return true;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date && $date->format('Y-m-d') === $tanggal;
}

/* ---------------------------------------------------------------
 * Ambil filter dari URL.
 * ------------------------------------------------------------- */

$tanggal_mulai   = trim((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggal_akhir   = trim((string) ($_GET['tanggal_akhir'] ?? ''));
$status_filter   = trim((string) ($_GET['status'] ?? ''));
$karyawan_filter = trim((string) ($_GET['karyawan'] ?? ''));

$pesan_error = '';

// Validasi tanggal.
if (!tanggalValid($tanggal_mulai) || !tanggalValid($tanggal_akhir)) {
    $pesan_error = 'Format tanggal tidak valid.';
} elseif (
    $tanggal_mulai !== ''
    && $tanggal_akhir !== ''
    && $tanggal_mulai > $tanggal_akhir
) {
    $pesan_error = 'Tanggal awal tidak boleh melewati tanggal akhir.';
}

$status_valid = ['', 'draft', 'dikirim', 'diperiksa', 'perlu_revisi'];

if (!in_array($status_filter, $status_valid, true)) {
    $pesan_error = 'Filter status tidak valid.';
}

if (
    $karyawan_filter !== ''
    && (!ctype_digit($karyawan_filter) || (int) $karyawan_filter < 1)
) {
    $pesan_error = 'Pilihan karyawan tidak valid.';
}

/* ---------------------------------------------------------------
 * Ambil daftar karyawan.
 * ------------------------------------------------------------- */

$daftar_karyawan = [];

$stmtKaryawan = $conn->prepare(
    "SELECT id, nama
     FROM users
     WHERE role = 'karyawan'
     ORDER BY nama ASC"
);

if ($stmtKaryawan) {
    $stmtKaryawan->execute();
    $resultKaryawan = $stmtKaryawan->get_result();

    while ($row = $resultKaryawan->fetch_assoc()) {
        $daftar_karyawan[] = $row;
    }

    $stmtKaryawan->close();
}

/* ---------------------------------------------------------------
 * Siapkan data rekap.
 * ------------------------------------------------------------- */

$laporan          = [];
$total_laporan    = 0;
$jumlah_draft     = 0;
$jumlah_dikirim   = 0;
$jumlah_diperiksa = 0;
$jumlah_revisi    = 0;

if ($pesan_error === '') {
    $sql = "
        SELECT
            l.id,
            l.tanggal,
            l.kegiatan,
            l.status,
            u.nama AS nama_karyawan,
            u.divisi
        FROM laporan_harian l
        INNER JOIN users u ON l.user_id = u.id
        WHERE u.role = 'karyawan'
    ";

    $types  = '';
    $params = [];

    if ($tanggal_mulai !== '') {
        $sql .= ' AND l.tanggal >= ?';
        $types .= 's';
        $params[] = $tanggal_mulai;
    }

    // Mencakup seluruh waktu pada tanggal akhir.
    if ($tanggal_akhir !== '') {
        $sql .= ' AND l.tanggal < DATE_ADD(?, INTERVAL 1 DAY)';
        $types .= 's';
        $params[] = $tanggal_akhir;
    }

    if ($status_filter !== '') {
        $sql .= ' AND l.status = ?';
        $types .= 's';
        $params[] = $status_filter;
    }

    if ($karyawan_filter !== '') {
        $sql .= ' AND l.user_id = ?';
        $types .= 'i';
        $params[] = (int) $karyawan_filter;
    }

    $sql .= ' ORDER BY l.tanggal DESC, l.id DESC';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        http_response_code(500);
        exit('Gagal menyiapkan data rekap laporan.');
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $laporan[] = $row;
        $total_laporan++;

        if ($row['status'] === 'draft') {
            $jumlah_draft++;
        } elseif ($row['status'] === 'dikirim') {
            $jumlah_dikirim++;
        } elseif ($row['status'] === 'diperiksa') {
            $jumlah_diperiksa++;
        } elseif ($row['status'] === 'perlu_revisi') {
            $jumlah_revisi++;
        }
    }

    $stmt->close();
}

/* ---------------------------------------------------------------
 * Parameter filter untuk ekspor PDF dan Excel (nama tetap).
 * ------------------------------------------------------------- */

$parameter_filter = http_build_query([
    'tanggal_mulai' => $tanggal_mulai,
    'tanggal_akhir' => $tanggal_akhir,
    'status'        => $status_filter,
    'karyawan'      => $karyawan_filter,
]);

$url_pdf   = 'export_pdf.php?' . $parameter_filter;
$url_excel = 'export_excel.php?' . $parameter_filter;

$opsi = [
    'judul' => 'Rekap Laporan',
    'crumb' => 'Mentor',
    'head'  => '<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>',
];

mulai_halaman($opsi);
?>

<!-- ================= FILTER ================= -->
<form method="GET" action="rekap_laporan.php" class="filter-bar">
    <div class="grup-form">
        <label class="label-form" for="tanggal_mulai">Tanggal awal</label>
        <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e($tanggal_mulai) ?>">
    </div>

    <div class="grup-form">
        <label class="label-form" for="tanggal_akhir">Tanggal akhir</label>
        <input type="date" id="tanggal_akhir" name="tanggal_akhir" value="<?= e($tanggal_akhir) ?>">
    </div>

    <div class="grup-form">
        <label class="label-form" for="status">Status laporan</label>
        <select id="status" name="status">
            <option value="">Semua Status</option>
            <option value="draft"        <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="dikirim"      <?= $status_filter === 'dikirim' ? 'selected' : '' ?>>Menunggu Pemeriksaan</option>
            <option value="diperiksa"    <?= $status_filter === 'diperiksa' ? 'selected' : '' ?>>Sudah Diperiksa</option>
            <option value="perlu_revisi" <?= $status_filter === 'perlu_revisi' ? 'selected' : '' ?>>Perlu Revisi</option>
        </select>
    </div>

    <div class="grup-form">
        <label class="label-form" for="karyawan">Karyawan</label>
        <select id="karyawan" name="karyawan">
            <option value="">Semua Karyawan</option>
            <?php foreach ($daftar_karyawan as $karyawan): ?>
                <option value="<?= (int) $karyawan['id'] ?>"
                    <?= $karyawan_filter === (string) $karyawan['id'] ? 'selected' : '' ?>>
                    <?= e($karyawan['nama']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="aksi-filter">
        <button class="tombol tombol-utama" type="submit">Terapkan Filter</button>
        <a class="tombol" href="rekap_laporan.php">Reset Filter</a>

        <?php if ($pesan_error === ''): ?>
            <a class="tombol tombol-bahaya" href="<?= e($url_pdf) ?>" target="_blank" rel="noopener">Download PDF</a>
            <a class="tombol tombol-sukses" href="<?= e($url_excel) ?>">Download Excel</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($pesan_error !== ''): ?>
    <div class="alert alert-gagal" role="alert">
        <span class="ikon">✕</span>
        <span><?= e($pesan_error) ?></span>
    </div>
<?php endif; ?>

<?php if ($pesan_error === ''): ?>
    <!-- ================= RINGKASAN ================= -->
    <section class="grid grid-auto">
        <div class="stat biru">
            <span class="ikon">▤</span>
            <div class="flex-1">
                <div class="nilai"><?= (int) $total_laporan ?></div>
                <div class="keterangan">Total Laporan</div>
            </div>
        </div>

        <div class="stat navy">
            <span class="ikon">✎</span>
            <div class="flex-1">
                <div class="nilai"><?= (int) $jumlah_draft ?></div>
                <div class="keterangan">Draft</div>
            </div>
        </div>

        <div class="stat kuning">
            <span class="ikon">◷</span>
            <div class="flex-1">
                <div class="nilai"><?= (int) $jumlah_dikirim ?></div>
                <div class="keterangan">Menunggu Pemeriksaan</div>
            </div>
        </div>

        <div class="stat hijau">
            <span class="ikon">✓</span>
            <div class="flex-1">
                <div class="nilai"><?= (int) $jumlah_diperiksa ?></div>
                <div class="keterangan">Sudah Diperiksa</div>
            </div>
        </div>

        <div class="stat merah">
            <span class="ikon">!</span>
            <div class="flex-1">
                <div class="nilai"><?= (int) $jumlah_revisi ?></div>
                <div class="keterangan">Perlu Revisi</div>
            </div>
        </div>
    </section>

    <!-- ================= GRAFIK ================= -->
    <section class="grid grid-2" style="margin-top:20px">
        <div class="kartu" style="margin-bottom:0">
            <div class="kartu-header"><h3>Grafik Jumlah Laporan</h3></div>
            <div class="kartu-tubuh">
                <div style="position:relative;height:300px">
                    <canvas id="grafikBatang" aria-label="Grafik jumlah laporan" role="img"></canvas>
                </div>
            </div>
        </div>

        <div class="kartu" style="margin-bottom:0">
            <div class="kartu-header"><h3>Persentase Status Laporan</h3></div>
            <div class="kartu-tubuh">
                <div style="position:relative;height:300px">
                    <canvas id="grafikLingkaran" aria-label="Persentase status laporan" role="img"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= HASIL REKAP ================= -->
    <section class="kartu" style="margin-top:20px">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Hasil Rekap Laporan</h3>
                <span class="text-redup text-kecil">
                    Jumlah laporan sesuai filter: <?= (int) $total_laporan ?> laporan
                </span>
            </div>
            <a class="tombol tombol-kecil" href="mentor_dashboard.php">← Dashboard Mentor</a>
        </div>

        <div class="kartu-tubuh padat">
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <thead>
                        <tr>
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
                    <?php if (count($laporan) === 0): ?>
                        <tr>
                            <td colspan="7" class="tabel-kosong">
                                <span class="ikon">▤</span>
                                Tidak ada laporan yang cocok dengan filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($laporan as $index => $item): ?>
                            <tr>
                                <td><?= (int) ($index + 1) ?></td>
                                <td><?= e($item['nama_karyawan']) ?></td>
                                <td><?= e($item['divisi'] ?: '-') ?></td>
                                <td class="text-kecil text-redup"><?= e(tanggal_id((string) $item['tanggal'])) ?></td>
                                <td><span class="potong-2"><?= e(potong((string) $item['kegiatan'], 80)) ?></span></td>
                                <td>
                                    <span class="badge <?= kelas_status((string) $item['status']) ?>">
                                        <?= e(label_status((string) $item['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-kanan">
                                    <a class="tombol tombol-kecil"
                                       href="mentor_detail.php?id=<?= (int) $item['id'] ?>">
                                        <?= in_array($item['status'], ['draft', 'dikirim'], true) ? 'Periksa' : 'Detail' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($pesan_error === ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.kartu-tubuh canvas').forEach(function (el) {
            var box = el.parentElement;
            if (box) {
                box.innerHTML = '<p class="text-redup text-kecil">Grafik tidak dapat dimuat. Periksa koneksi internet untuk memuat Chart.js.</p>';
            }
        });
        return;
    }

    var labelStatus = ['Draft', 'Menunggu Pemeriksaan', 'Sudah Diperiksa', 'Perlu Revisi'];
    var jumlahStatus = [
        <?= (int) $jumlah_draft ?>,
        <?= (int) $jumlah_dikirim ?>,
        <?= (int) $jumlah_diperiksa ?>,
        <?= (int) $jumlah_revisi ?>
    ];
    var warnaStatus = ['#94a3b8', '#d69e2e', '#0072BC', '#E31E24'];

    new Chart(document.getElementById('grafikBatang'), {
        type: 'bar',
        data: {
            labels: labelStatus,
            datasets: [{
                label: 'Jumlah Laporan',
                data: jumlahStatus,
                backgroundColor: warnaStatus,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { legend: { display: false } }
        }
    });

    new Chart(document.getElementById('grafikLingkaran'), {
        type: 'doughnut',
        data: {
            labels: labelStatus,
            datasets: [{ data: jumlahStatus, backgroundColor: warnaStatus, borderWidth: 2 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
});
</script>
<?php endif; ?>

<?php akhiri_halaman(); ?>
