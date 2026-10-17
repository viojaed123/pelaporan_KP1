<?php
/**
 * mentor_absensi.php — pemantauan absensi peserta (mentor/admin).
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran terpusat: wajib_role(['mentor','admin'])
 *   · layout terpusat — <style> inline dihapus
 *   · verifikasi CSRF untuk permintaan POST
 *   · filter periode/pencarian & ringkasan tetap dipertahankan
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_role(['mentor', 'admin'], null, $conn);

$role = sesi_role();

date_default_timezone_set('Asia/Jakarta');

// Semua perubahan data (bila ada) wajib membawa token CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('mentor_absensi');
}

$tanggal_awal = $_GET['dari'] ?? date('Y-m-01');
$tanggal_akhir = $_GET['sampai'] ?? date('Y-m-d');
$cari = trim((string) ($_GET['cari'] ?? ''));

$format_tanggal = '/^\d{4}-\d{2}-\d{2}$/';

if (!is_string($tanggal_awal) || !preg_match($format_tanggal, $tanggal_awal)) {
    $tanggal_awal = date('Y-m-01');
}
if (!is_string($tanggal_akhir) || !preg_match($format_tanggal, $tanggal_akhir)) {
    $tanggal_akhir = date('Y-m-d');
}
if ($tanggal_awal > $tanggal_akhir) {
    [$tanggal_awal, $tanggal_akhir] = [$tanggal_akhir, $tanggal_awal];
}

$sql = "SELECT a.id, a.tanggal, a.jam_masuk, a.jam_pulang,
               a.status, a.keterangan, a.kegiatan_hari_ini, a.kondisi_kesehatan,
               u.nama, u.email, u.divisi
        FROM absensi a
        INNER JOIN users u ON u.id = a.user_id
        WHERE a.tanggal BETWEEN ? AND ?
          AND u.role = 'karyawan'
          AND (u.nama LIKE ? OR u.email LIKE ? OR u.divisi LIKE ?)
        ORDER BY a.tanggal DESC, u.nama ASC";

$stmt = $conn->prepare($sql);
$kata = '%' . $cari . '%';
$stmt->bind_param('sssss', $tanggal_awal, $tanggal_akhir, $kata, $kata, $kata);
$stmt->execute();
$data = $stmt->get_result();

$sql_ringkasan = "SELECT
    COUNT(*) AS total_catatan,
    SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) AS total_hadir,
    SUM(CASE WHEN a.status = 'izin' THEN 1 ELSE 0 END) AS total_izin,
    SUM(CASE WHEN a.status = 'sakit' THEN 1 ELSE 0 END) AS total_sakit,
    SUM(CASE WHEN a.status = 'alpa' THEN 1 ELSE 0 END) AS total_alpa,
    SUM(CASE WHEN a.kondisi_kesehatan IN ('kurang_sehat','sakit') THEN 1 ELSE 0 END) AS total_perlu_perhatian
    FROM absensi a
    INNER JOIN users u ON u.id = a.user_id
    WHERE a.tanggal BETWEEN ? AND ?
      AND u.role = 'karyawan'";

$stmt_ringkasan = $conn->prepare($sql_ringkasan);
$stmt_ringkasan->bind_param('ss', $tanggal_awal, $tanggal_akhir);
$stmt_ringkasan->execute();
$ringkasan = $stmt_ringkasan->get_result()->fetch_assoc();

mulai_halaman([
    'judul' => 'Absensi Karyawan',
    'crumb' => ucfirst($role),
    'aksi'  => '<a class="tombol tombol-kecil" href="rekap_absensi.php">' . ikon_sp('kalender') . ' Rekap Absensi</a>',
]);
?>

<!-- ================= RINGKASAN ================= -->
<section class="grid grid-4">
    <div class="stat navy">
        <span class="ikon">▤</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) ($ringkasan['total_catatan'] ?? 0) ?></div>
            <div class="keterangan">Total Catatan</div>
        </div>
    </div>
    <div class="stat hijau">
        <span class="ikon">✓</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) ($ringkasan['total_hadir'] ?? 0) ?></div>
            <div class="keterangan">Hadir</div>
        </div>
    </div>
    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('surat') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) ($ringkasan['total_izin'] ?? 0) ?></div>
            <div class="keterangan">Izin</div>
        </div>
    </div>
    <div class="stat kuning">
        <span class="ikon">✚</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) ($ringkasan['total_sakit'] ?? 0) ?></div>
            <div class="keterangan">Sakit</div>
        </div>
    </div>
    <div class="stat merah">
        <span class="ikon">✕</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) ($ringkasan['total_alpa'] ?? 0) ?></div>
            <div class="keterangan">Alpa</div>
        </div>
    </div>
    <div class="stat merah">
        <span class="ikon"><?= ikon_sp('kesehatan') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) ($ringkasan['total_perlu_perhatian'] ?? 0) ?></div>
            <div class="keterangan">Perlu Perhatian</div>
        </div>
    </div>
</section>

<!-- ================= FILTER ================= -->
<form method="get" class="filter-bar" style="margin-top:20px">
    <div class="grup-form">
        <label class="label-form" for="dari">Tanggal mulai</label>
        <input id="dari" type="date" name="dari" value="<?= e($tanggal_awal) ?>" required>
    </div>
    <div class="grup-form">
        <label class="label-form" for="sampai">Tanggal akhir</label>
        <input id="sampai" type="date" name="sampai" value="<?= e($tanggal_akhir) ?>" required>
    </div>
    <div class="grup-form">
        <label class="label-form" for="cari">Cari peserta</label>
        <input id="cari" type="text" name="cari" value="<?= e($cari) ?>"
               placeholder="Nama, email, divisi">
    </div>
    <div class="aksi-filter">
        <button type="submit" class="tombol tombol-utama">Terapkan Filter</button>
    </div>
</form>

<!-- ================= DATA ================= -->
<section class="kartu">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Data Kehadiran</h3>
            <span class="text-redup text-kecil">
                Periode <?= e($tanggal_awal) ?> sampai <?= e($tanggal_akhir) ?>.
            </span>
        </div>
    </div>
    <div class="kartu-tubuh padat">
        <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama</th>
                        <th>Divisi</th>
                        <th>Status</th>
                        <th>Jam Masuk</th>
                        <th>Jam Pulang</th>
                        <th>Kesehatan</th>
                        <th>Kegiatan hari ini</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($data->num_rows === 0): ?>
                    <tr>
                        <td colspan="9" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('kalender') ?></span>
                            Tidak ada catatan absensi untuk filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = $data->fetch_assoc()): ?>
                        <tr>
                            <td class="text-kecil text-redup"><?= e($row['tanggal']) ?></td>
                            <td>
                                <strong><?= e($row['nama']) ?></strong><br>
                                <span class="text-redup text-kecil"><?= e($row['email']) ?></span>
                            </td>
                            <td><?= e(($row['divisi'] ?? '') ?: '-') ?></td>
                            <td>
                                <span class="badge absensi-<?= e(strtolower(trim((string) $row['status']))) ?>">
                                    <?= e(label_status_absensi((string) $row['status'])) ?>
                                </span>
                            </td>
                            <td><?= e(($row['jam_masuk'] ?? '') ?: '-') ?></td>
                            <td><?= e(($row['jam_pulang'] ?? '') ?: '-') ?></td>
                            <td>
                                <span class="badge <?= e(badge_kondisi_kesehatan((string) ($row['kondisi_kesehatan'] ?? ''))) ?>">
                                    <?= e(label_kondisi_kesehatan((string) ($row['kondisi_kesehatan'] ?? ''))) ?>
                                </span>
                            </td>
                            <td class="text-kecil" style="max-width:280px;white-space:pre-wrap">
                                <?= e(($row['kegiatan_hari_ini'] ?? '') ?: '-') ?>
                            </td>
                            <td><?= e(($row['keterangan'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php
$stmt->close();
$stmt_ringkasan->close();
akhiri_halaman();
?>
