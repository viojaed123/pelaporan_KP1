<?php
/**
 * riwayat.php — daftar laporan milik pengguna yang sedang masuk.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + wajib_login() + penjaga peran
 *   · layout terpusat — <style> inline dihapus
 *   · HANYA menampilkan laporan milik sendiri (mentor/admin pun melihat
 *     datanya sendiri, bukan laporan karyawan lain) — sekaligus menutup
 *     kebocoran data lintas pengguna
 *   · filter status draft/dikirim/diperiksa/perlu_revisi + paginasi
 *   · memakai helper label_status()/kelas_status()/tanggal_id()
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();
$role    = sesi_role();

/* Penjaga peran: halaman ini untuk melihat laporan sendiri. Peran yang
 * tidak dikenal diarahkan ke halaman default sesuai perannya. */
if (!in_array($role, ['karyawan', 'mentor', 'admin'], true)) {
    header('Location: ' . halaman_default_peran($role));
    exit;
}

/* ---------------------------------------------------------------
 * Filter status
 * ------------------------------------------------------------- */
$status_diizinkan = ['draft', 'dikirim', 'diperiksa', 'perlu_revisi'];
$status_filter    = (string) ($_GET['status'] ?? '');
if (!in_array($status_filter, $status_diizinkan, true)) {
    $status_filter = '';
}
$mode_revisi = ($status_filter === 'perlu_revisi');

/* ---------------------------------------------------------------
 * Paginasi
 * ------------------------------------------------------------- */
$per_halaman = 10;
$halaman     = max(1, (int) ($_GET['hal'] ?? 1));
$offset      = ($halaman - 1) * $per_halaman;

/* Hitung total untuk halaman ini. */
if ($status_filter !== '') {
    $stmtHitung = $conn->prepare(
        'SELECT COUNT(*) FROM laporan_harian WHERE user_id = ? AND status = ?'
    );
    $stmtHitung->bind_param('is', $user_id, $status_filter);
} else {
    $stmtHitung = $conn->prepare(
        'SELECT COUNT(*) FROM laporan_harian WHERE user_id = ?'
    );
    $stmtHitung->bind_param('i', $user_id);
}
$stmtHitung->execute();
$stmtHitung->bind_result($total_laporan);
$stmtHitung->fetch();
$stmtHitung->close();

$total_halaman = max(1, (int) ceil($total_laporan / $per_halaman));
if ($halaman > $total_halaman) {
    $halaman = $total_halaman;
    $offset  = ($halaman - 1) * $per_halaman;
}

/* Ambil laporan milik pengguna yang sedang login. */
$sql = 'SELECT id, tanggal, jam_mulai, jam_selesai,
               kegiatan, deskripsi, hasil, kendala,
               rencana_berikutnya, status, created_at
        FROM laporan_harian
        WHERE user_id = ?';
if ($status_filter !== '') {
    $sql .= ' AND status = ?';
}
$sql .= ' ORDER BY tanggal DESC, id DESC LIMIT ? OFFSET ?';

$stmt = $conn->prepare($sql);

if ($status_filter !== '') {
    $stmt->bind_param('isii', $user_id, $status_filter, $per_halaman, $offset);
} else {
    $stmt->bind_param('iii', $user_id, $per_halaman, $offset);
}

$stmt->execute();
$laporan = $stmt->get_result();

/* Tautan bantu: pertahankan filter status saat berpindah halaman. */
$tautan = static function (string $status, int $h) : string {
    $q = [];
    if ($status !== '') {
        $q['status'] = $status;
    }
    if ($h > 1) {
        $q['hal'] = $h;
    }
    return 'riwayat.php' . ($q !== [] ? '?' . http_build_query($q) : '');
};

$opsi_status = [
    ''             => 'Semua',
    'draft'        => 'Draft',
    'dikirim'      => 'Menunggu Pemeriksaan',
    'diperiksa'    => 'Diperiksa',
    'perlu_revisi' => 'Perlu Revisi',
];
mulai_halaman([
    'judul' => $mode_revisi ? 'Laporan Perlu Revisi' : 'Riwayat Laporan',
    'crumb' => 'Karyawan',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="buat_laporan.php">＋ Buat Laporan</a>',
]);
?>

<?php if ($mode_revisi): ?>
    <div class="alert alert-peringatan" role="alert">
        <span class="ikon">!</span>
        <span>
            <strong>Laporan yang perlu diperbaiki</strong>
            Ikuti catatan mentor pada setiap laporan sebelum mengirimkan perbaikan.
        </span>
    </div>
<?php endif; ?>

<?php if (!in_array($role, ['karyawan'], true)): ?>
    <div class="alert alert-info" role="status">
        <span class="ikon">ℹ</span>
        <span>
            Halaman ini hanya menampilkan laporan milik akun Anda sendiri.
            Untuk meninjau laporan karyawan lain, gunakan menu <em>Rekap Laporan</em>.
        </span>
    </div>
<?php endif; ?>

<section class="filter-bar">
    <span class="text-redup text-kecil text-tebal">Filter status</span>
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($opsi_status as $nilai => $teks): ?>
            <a class="tombol tombol-kecil <?= e($status_filter === $nilai ? 'tombol-utama' : 'tombol-garis') ?>"
               href="<?= e($tautan($nilai, 1)) ?>"><?= e($teks) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3><?= e($mode_revisi ? 'Laporan Perlu Revisi' : 'Laporan Saya') ?></h3>
            <span class="text-redup text-kecil">
                <?= (int) $total_laporan ?> laporan
                <?= $status_filter !== '' ? 'berstatus ' . e(label_status($status_filter)) : 'tercatat' ?>.
            </span>
        </div>
    </div>

    <div class="kartu-badan padat">
        <?php if ($laporan->num_rows === 0): ?>
            <div class="tabel-kosong">
                <span class="ikon">▤</span>
                <?php if ($mode_revisi): ?>
                    Saat ini tidak ada laporan yang perlu direvisi.<br>
                    <a class="tombol tombol-garis tombol-kecil" style="margin-top:12px"
                       href="riwayat.php">Lihat semua riwayat</a>
                <?php elseif ($status_filter !== ''): ?>
                    Tidak ada laporan dengan status <?= e(label_status($status_filter)) ?>.<br>
                    <a class="tombol tombol-garis tombol-kecil" style="margin-top:12px"
                       href="riwayat.php">Lihat semua riwayat</a>
                <?php else: ?>
                    Belum ada laporan yang tersimpan.<br>
                    <a class="tombol tombol-utama tombol-kecil" style="margin-top:12px"
                       href="buat_laporan.php">Buat laporan pertama</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Kegiatan</th>
                            <th>Status</th>
                            <th class="text-kanan">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($item = $laporan->fetch_assoc()): ?>
                        <tr>
                            <td class="text-kecil text-redup">
                                <?= e(tanggal_id((string) $item['tanggal'])) ?>
                                <?php if (!empty($item['jam_mulai'])): ?>
                                    <br><?= e(substr((string) $item['jam_mulai'], 0, 5)) ?>
                                    <?php if (!empty($item['jam_selesai'])): ?>
                                        – <?= e(substr((string) $item['jam_selesai'], 0, 5)) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="potong-2"><?= e(potong((string) $item['kegiatan'], 90)) ?></span>
                                <div class="text-redup text-kecil">#<?= (int) $item['id'] ?></div>
                            </td>
                            <td>
                                <span class="badge <?= kelas_status((string) $item['status']) ?>">
                                    <?= e(label_status((string) $item['status'])) ?>
                                </span>
                            </td>
                            <td class="text-kanan">
                                <div class="aksi-baris">
                                    <a class="tombol tombol-kecil"
                                       href="detail_laporan.php?id=<?= (int) $item['id'] ?>">Lihat</a>
                                    <?php if ((string) $item['status'] === 'perlu_revisi'): ?>
                                        <a class="tombol tombol-bahaya tombol-kecil"
                                           href="edit_revisi.php?id=<?= (int) $item['id'] ?>">Ajukan Revisi</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_halaman > 1): ?>
        <div class="kartu-kaki">
            <nav class="paginasi" aria-label="Navigasi halaman" style="margin:0 auto">
                <?php if ($halaman > 1): ?>
                    <a href="<?= e($tautan($status_filter, $halaman - 1)) ?>" rel="prev">‹ Sebelumnya</a>
                <?php else: ?>
                    <span class="mati">‹ Sebelumnya</span>
                <?php endif; ?>

                <?php for ($h = 1; $h <= $total_halaman; $h++): ?>
                    <?php if ($h === $halaman): ?>
                        <span class="aktif" aria-current="page"><?= (int) $h ?></span>
                    <?php else: ?>
                        <a href="<?= e($tautan($status_filter, $h)) ?>"><?= (int) $h ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($halaman < $total_halaman): ?>
                    <a href="<?= e($tautan($status_filter, $halaman + 1)) ?>" rel="next">Berikutnya ›</a>
                <?php else: ?>
                    <span class="mati">Berikutnya ›</span>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>
</section>

<?php
$stmt->close();
akhiri_halaman();
?>
