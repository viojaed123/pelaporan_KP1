<?php
/**
 * mentor_detail.php — tinjau & periksa satu laporan harian karyawan.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran mentor/admin
 *   · layout terpusat — <style> inline & header lama dihapus
 *   · token CSRF lama ('csrf_token_mentor') diganti skema per-aksi
 *   · karakter ``` nyasar di akhir berkas dihapus
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

/* ---------------------------------------------------------------
 * Validasi ID laporan.
 * ------------------------------------------------------------- */

$laporan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$laporan_id || $laporan_id < 1) {
    http_response_code(400);
    exit('ID laporan tidak valid.');
}

/* ---------------------------------------------------------------
 * Ambil detail laporan dan nama karyawan.
 * ------------------------------------------------------------- */

$stmt = $conn->prepare(
    'SELECT
        l.*,
        u.nama AS nama_karyawan,
        u.email AS email_karyawan,
        u.divisi
     FROM laporan_harian l
     JOIN users u ON l.user_id = u.id
     WHERE l.id = ?'
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
 * Ambil foto bukti kegiatan.
 * ------------------------------------------------------------- */

$stmt = $conn->prepare(
    'SELECT id, nama_asli, ukuran_bytes
     FROM bukti_gambar
     WHERE laporan_id = ?
     ORDER BY id ASC'
);
$stmt->bind_param('i', $laporan_id);
$stmt->execute();
$fotos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$status       = (string) $laporan['status'];
$boleh_proses = in_array($status, ['draft', 'dikirim'], true);

$opsi = [
    'judul' => 'Detail Laporan',
    'crumb' => 'Mentor',
    'aksi'  => '<a class="tombol tombol-kecil" href="mentor_dashboard.php">← Kembali ke Dashboard</a>',
];

mulai_halaman($opsi);
?>

<!-- ================= INFORMASI LAPORAN ================= -->
<section class="kartu">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Informasi Laporan</h3>
            <span class="text-redup text-kecil">Nomor laporan #<?= (int) $laporan['id'] ?></span>
        </div>
        <span class="badge <?= kelas_status($status) ?>"><?= e(label_status($status)) ?></span>
    </div>

    <div class="kartu-tubuh">
        <div class="grid grid-2">
            <div class="rincian">
                <div class="baris">
                    <span class="kunci">Nama Karyawan</span>
                    <span class="nilai"><?= e($laporan['nama_karyawan']) ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Email</span>
                    <span class="nilai"><?= e($laporan['email_karyawan']) ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Divisi</span>
                    <span class="nilai"><?= e($laporan['divisi'] ?: '-') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Tanggal Kegiatan</span>
                    <span class="nilai"><?= e(tanggal_id((string) $laporan['tanggal'])) ?></span>
                </div>
            </div>

            <div class="rincian">
                <div class="baris">
                    <span class="kunci">Jam Mulai</span>
                    <span class="nilai"><?= e(!empty($laporan['jam_mulai']) ? substr((string) $laporan['jam_mulai'], 0, 5) : '-') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Jam Selesai</span>
                    <span class="nilai"><?= e(!empty($laporan['jam_selesai']) ? substr((string) $laporan['jam_selesai'], 0, 5) : '-') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Status Laporan</span>
                    <span class="nilai"><?= e(label_status($status)) ?></span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= ISI LAPORAN ================= -->
<section class="kartu">
    <div class="kartu-header"><h3>Isi Laporan</h3></div>
    <div class="kartu-tubuh">
        <div class="grup-form">
            <label class="label-form">Kegiatan</label>
            <div class="teks-konten"><?= e($laporan['kegiatan']) ?></div>
        </div>

        <div class="grup-form">
            <label class="label-form">Deskripsi Kegiatan</label>
            <div class="teks-konten"><?= e($laporan['deskripsi']) ?></div>
        </div>

        <div class="grup-form">
            <label class="label-form">Hasil Kegiatan</label>
            <div class="teks-konten"><?= e($laporan['hasil']) ?></div>
        </div>

        <div class="grup-form">
            <label class="label-form">Kendala</label>
            <div class="teks-konten"><?= e($laporan['kendala']) ?></div>
        </div>

        <div class="grup-form">
            <label class="label-form">Rencana Berikutnya</label>
            <div class="teks-konten"><?= e($laporan['rencana_berikutnya']) ?></div>
        </div>

        <?php if (!empty($laporan['catatan_mentor'])): ?>
            <div class="grup-form mb-0">
                <label class="label-form">Catatan Mentor Sebelumnya</label>
                <div class="kotak-catatan"><?= e($laporan['catatan_mentor']) ?></div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ================= BUKTI FOTO ================= -->
<section class="kartu">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Bukti Foto Kegiatan</h3>
            <span class="text-redup text-kecil"><?= count($fotos) ?> foto terlampir</span>
        </div>
    </div>
    <div class="kartu-tubuh">
        <?php if (count($fotos) > 0): ?>
            <div class="galeri-foto">
                <?php foreach ($fotos as $foto): ?>
                    <a href="lihat_foto.php?id=<?= (int) $foto['id'] ?>" target="_blank" rel="noopener">
                        <img src="lihat_foto.php?id=<?= (int) $foto['id'] ?>"
                             alt="Bukti kegiatan: <?= e($foto['nama_asli']) ?>" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-redup mb-0">Tidak ada foto bukti kegiatan pada laporan ini.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ================= KEPUTUSAN MENTOR ================= -->
<?php if ($boleh_proses): ?>
    <section class="kartu">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Keputusan Mentor</h3>
                <span class="text-redup text-kecil">Periksa isi laporan sebelum menentukan keputusan.</span>
            </div>
        </div>

        <form action="mentor_proses.php" method="POST">
            <?= csrf_field('periksa_laporan') ?>
            <input type="hidden" name="laporan_id" value="<?= (int) $laporan['id'] ?>">

            <div class="kartu-tubuh">
                <div class="grup-form">
                    <label class="label-form" for="catatan">
                        Catatan atau Arahan Mentor
                    </label>
                    <textarea id="catatan" name="catatan_mentor" maxlength="5000"
                              placeholder="Tuliskan hasil pemeriksaan, apresiasi, atau bagian yang perlu diperbaiki…"></textarea>
                    <span class="text-redup text-kecil">Catatan wajib diisi jika memilih <strong>Perlu Revisi</strong>.</span>
                </div>
            </div>

            <div class="kartu-kaki">
                <button type="submit" name="keputusan" value="diperiksa"
                        class="tombol tombol-sukses"
                        onclick="return confirm('Tandai laporan ini sebagai diperiksa?')">
                    ✓ Diperiksa
                </button>
                <button type="submit" name="keputusan" value="perlu_revisi"
                        class="tombol tombol-bahaya"
                        onclick="return confirm('Minta karyawan merevisi laporan ini?')">
                    ✕ Perlu Revisi
                </button>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="kartu">
        <div class="kartu-tubuh">
            <div class="alert alert-info mb-0" role="status">
                <span class="ikon">ℹ</span>
                <span>
                    <strong>Laporan sudah diproses</strong>
                    Keputusan mentor sudah tercatat. Laporan ini tidak dapat diproses
                    ulang melalui formulir ini.
                </span>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
komentar_tampil(
    $conn,
    (int) $laporan['id'],
    (int) sesi_user_id(),
    sesi_role(),
    'mentor_detail.php'
);

akhiri_halaman();
