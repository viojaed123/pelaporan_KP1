<?php
/**
 * detail_laporan.php — detail satu laporan harian.
 *
 * Pembaruan dari versi lama (perbaikan bug kritis):
 *   · bug lama merotasi token CSRF GLOBAL ($_SESSION['csrf_token']) setiap
 *     kali laporan dikirim, sehingga form di tab lain jadi tidak valid.
 *     Sekarang memakai token PER-AKSI: csrf_field('kirim_laporan') +
 *     csrf_cek_atau_matikan('kirim_laporan') — tanpa rotasi global.
 *   · kepemilikan: pemilik, mentor, dan admin boleh membuka; selain itu 403.
 *     Mentor/admin melihat semua laporan, karyawan hanya miliknya sendiri.
 *   · tombol aksi disesuaikan status laporan
 *     (draft: Kirim ke Mentor · perlu_revisi: Ajukan Revisi ·
 *      dikirim: menunggu · diperiksa: selesai).
 *   · layout terpusat — <style> inline dihapus, flash + PRG + audit.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();
$role    = sesi_role();

/*
 * Notifikasi ke mentor kini memakai includes/notifikasi.php
 * (fungsi notifikasi_ke_mentor) — tidak lagi diduplikasi di sini.
 */

$laporan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$laporan_id || $laporan_id < 1) {
    http_response_code(400);
    exit('Nomor laporan tidak valid.');
}

/* Ambil laporan TANPA filter pemilik agar mentor/admin dapat membuka. */
$stmt = $conn->prepare(
    'SELECT l.id, l.user_id, l.tanggal, l.jam_mulai, l.jam_selesai,
            l.kegiatan, l.deskripsi, l.hasil, l.kendala,
            l.rencana_berikutnya, l.status, l.catatan_mentor,
            u.nama AS pemilik
     FROM laporan_harian l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.id = ? LIMIT 1'
);
$stmt->bind_param('i', $laporan_id);
$stmt->execute();
$laporan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$laporan) {
    http_response_code(404);
    exit('Laporan tidak ditemukan.');
}

/* ---------------------------------------------------------------
 * Kepemilikan
 * ------------------------------------------------------------- */
$pemilik_id     = (int) $laporan['user_id'];
$adalah_pemilik = ($pemilik_id === $user_id);
$boleh_lihat    = $adalah_pemilik || in_array($role, ['mentor', 'admin'], true);

if (!$boleh_lihat) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke laporan ini.');
}

$pesan_galat = '';

/* ---------------------------------------------------------------
 * Proses pengiriman laporan (hanya pemilik, hanya saat draft).
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('kirim_laporan');

    if (!$adalah_pemilik) {
        http_response_code(403);
        exit('Hanya pemilik laporan yang dapat mengirim.');
    }

    $stmtKirim = $conn->prepare(
        "UPDATE laporan_harian
         SET status = 'dikirim'
         WHERE id = ? AND user_id = ? AND status = 'draft'"
    );

    if (!$stmtKirim) {
        error_log('Gagal menyiapkan pengiriman laporan: ' . $conn->error);
        $pesan_galat = 'Terjadi kesalahan saat menyiapkan pengiriman laporan.';
    } else {
        $stmtKirim->bind_param('ii', $laporan_id, $user_id);

        if ($stmtKirim->execute() && $stmtKirim->affected_rows === 1) {
            $stmtKirim->close();

            catat_audit(
                $conn,
                'kirim_laporan',
                'laporan_harian',
                $laporan_id,
                'Mengirim laporan kepada mentor',
                ['status' => 'draft'],
                ['status' => 'dikirim']
            );

            /* Beri tahu seluruh mentor. */
            notifikasi_ke_mentor(
                $conn,
                $laporan_id,
                'Ada laporan baru #' . $laporan_id . ' yang perlu diperiksa.'
            );

            flash_set('sukses', 'Laporan berhasil dikirim kepada mentor untuk diperiksa.');
            header('Location: detail_laporan.php?id=' . $laporan_id);
            exit;
        }

        $stmtKirim->close();
        $pesan_galat = 'Laporan tidak dapat dikirim. Periksa status laporan dan coba kembali.';
    }
}

/* ---------------------------------------------------------------
 * Foto bukti
 * ------------------------------------------------------------- */
$stmtFoto = $conn->prepare(
    'SELECT id, nama_asli
     FROM bukti_gambar
     WHERE laporan_id = ?
     ORDER BY id ASC'
);
$stmtFoto->bind_param('i', $laporan_id);
$stmtFoto->execute();
$foto = $stmtFoto->get_result();

$status     = (string) $laporan['status'];
$bolehKirim = $adalah_pemilik && $status === 'draft';

$tautan_kembali = in_array($role, ['mentor', 'admin'], true) && !$adalah_pemilik
    ? 'rekap_laporan.php'
    : 'riwayat.php';
mulai_halaman([
    'judul' => 'Detail Laporan',
    'crumb' => 'Laporan',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="' . e($tautan_kembali) . '">← Kembali</a>',
]);
?>

<?php if ($pesan_galat !== ''): ?>
    <div class="alert alert-gagal" role="alert">
        <span class="ikon">✕</span>
        <span><?= e($pesan_galat) ?></span>
    </div>
<?php endif; ?>

<section class="kartu">
    <div class="kartu-header">
        <div class="flex-1">
            <h2><?= e($laporan['kegiatan']) ?></h2>
            <span class="text-redup text-kecil">Nomor laporan #<?= (int) $laporan['id'] ?></span>
        </div>
        <span class="badge <?= kelas_status($status) ?>">
            <?= e(label_status($status)) ?>
        </span>
    </div>

    <div class="kartu-tubuh">
        <div class="rincian">
            <?php if (!$adalah_pemilik): ?>
                <div class="baris">
                    <div class="kunci">Pemilik laporan</div>
                    <div class="nilai"><?= e($laporan['pemilik'] ?? '-') ?></div>
                </div>
            <?php endif; ?>

            <div class="baris">
                <div class="kunci">Tanggal kegiatan</div>
                <div class="nilai"><?= e(tanggal_id((string) $laporan['tanggal'])) ?></div>
            </div>

            <div class="baris">
                <div class="kunci">Jam kegiatan</div>
                <div class="nilai">
                    <?= e($laporan['jam_mulai'] ? substr((string) $laporan['jam_mulai'], 0, 5) : '-') ?>
                    sampai
                    <?= e($laporan['jam_selesai'] ? substr((string) $laporan['jam_selesai'], 0, 5) : '-') ?>
                </div>
            </div>

            <div class="baris">
                <div class="kunci">Deskripsi kegiatan</div>
                <div class="nilai teks-konten"><?= e($laporan['deskripsi']) ?></div>
            </div>

            <div class="baris">
                <div class="kunci">Hasil kegiatan</div>
                <div class="nilai teks-konten"><?= e($laporan['hasil'] ?: '-') ?></div>
            </div>

            <div class="baris">
                <div class="kunci">Kendala</div>
                <div class="nilai teks-konten"><?= e($laporan['kendala'] ?: '-') ?></div>
            </div>

            <div class="baris">
                <div class="kunci">Rencana berikutnya</div>
                <div class="nilai teks-konten"><?= e($laporan['rencana_berikutnya'] ?: '-') ?></div>
            </div>
        </div>

        <?php if (!empty($laporan['catatan_mentor'])): ?>
            <div class="mt-3">
                <div class="kunci text-redup text-kecil" style="margin-bottom:6px">Catatan mentor</div>
                <div class="kotak-catatan teks-konten"><?= e($laporan['catatan_mentor']) ?></div>
            </div>
        <?php elseif ($status === 'perlu_revisi'): ?>
            <div class="mt-3">
                <div class="kunci text-redup text-kecil" style="margin-bottom:6px">Catatan mentor</div>
                <div class="kotak-catatan kosong">Belum ada catatan dari mentor.</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="kartu-kaki">
        <?php if ($status === 'perlu_revisi'): ?>
            <?php if ($adalah_pemilik): ?>
                <span class="text-redup text-kecil flex-1">
                    Mentor meminta laporan ini diperbaiki. Baca catatan mentor, lalu ajukan revisi.
                </span>
                <a class="tombol tombol-bahaya"
                   href="edit_revisi.php?id=<?= (int) $laporan['id'] ?>">Ajukan Revisi</a>
            <?php else: ?>
                <span class="text-redup text-kecil">
                    Laporan sedang menunggu diperbaiki oleh karyawan.
                </span>
            <?php endif; ?>

        <?php elseif ($bolehKirim): ?>
            <span class="text-redup text-kecil flex-1">
                Pastikan isi laporan dan foto bukti sudah benar sebelum dikirim ke mentor.
            </span>
            <form method="POST"
                  action="detail_laporan.php?id=<?= (int) $laporan['id'] ?>"
                  onsubmit="return confirm('Kirim laporan ini kepada mentor untuk diperiksa?');">
                <?= csrf_field('kirim_laporan') ?>
                <button class="tombol tombol-utama" type="submit">Kirim ke Mentor</button>
            </form>

        <?php elseif ($status === 'dikirim'): ?>
            <span class="text-redup text-kecil">
                Laporan sudah dikirim dan sedang menunggu pemeriksaan mentor.
            </span>

        <?php elseif ($status === 'diperiksa'): ?>
            <span class="text-redup text-kecil">
                Laporan telah diperiksa mentor. Tidak ada tindakan yang diperlukan.
            </span>

        <?php elseif ($status === 'draft'): ?>
            <span class="text-redup text-kecil">
                Laporan masih berupa draft milik karyawan.
            </span>
        <?php endif; ?>
    </div>
</section>

<section class="kartu">
    <div class="kartu-header">
        <h3>Foto Bukti Kegiatan</h3>
    </div>
    <div class="kartu-tubuh">
        <?php if ($foto->num_rows === 0): ?>
            <p class="text-redup mb-0">Belum ada foto bukti untuk laporan ini.</p>
        <?php else: ?>
            <div class="galeri">
                <?php while ($gambar = $foto->fetch_assoc()): ?>
                    <a href="lihat_foto.php?id=<?= (int) $gambar['id'] ?>"
                       title="<?= e($gambar['nama_asli']) ?>">
                        <img src="lihat_foto.php?id=<?= (int) $gambar['id'] ?>"
                             alt="<?= e($gambar['nama_asli']) ?>" loading="lazy">
                    </a>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
komentar_tampil($conn, (int) $laporan_id, $user_id, $role, 'detail_laporan.php');

$stmtFoto->close();
akhiri_halaman();
?>
