<?php
/**
 * edit_revisi.php — memperbaiki laporan yang diminta revisi oleh mentor.
 *
 * Pembaruan dari versi lama (perbaikan bug):
 *   · karakter ``` (tiga backtick) nyasar yang tercetak ke halaman — DIHAPUS.
 *   · validasi memakai validasi_revisi()/validasi_laporan() yang SAMA dengan
 *     buat_laporan.php (semula aturannya timpang).
 *   · saat karyawan mengajukan revisi: status -> 'dikirim' DAN
 *     catatan_mentor di-set NULL (semula catatan lama menetap).
 *   · penjaga kepemilikan: hanya pemilik (user_id = sesi_user_id()) yang boleh.
 *   · token CSRF per-aksi, layout terpusat, flash + PRG + audit.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();

/*
 * Notifikasi ke mentor kini memakai includes/notifikasi.php
 * (fungsi notifikasi_ke_mentor) — tidak lagi diduplikasi di sini.
 */

$laporan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$laporan_id || $laporan_id < 1) {
    http_response_code(400);
    exit('ID laporan tidak valid.');
}

/* Penjaga kepemilikan: hanya pemilik laporan yang boleh mengedit. */
$stmt = $conn->prepare(
    'SELECT * FROM laporan_harian WHERE id = ? AND user_id = ? LIMIT 1'
);
$stmt->bind_param('ii', $laporan_id, $user_id);
$stmt->execute();
$laporan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$laporan) {
    http_response_code(404);
    exit('Laporan tidak ditemukan atau Anda tidak memiliki akses.');
}

/* Hanya laporan yang diminta revisi yang boleh diedit. */
if ((string) $laporan['status'] !== 'perlu_revisi') {
    header('Location: detail_laporan.php?id=' . $laporan_id);
    exit;
}

$galat = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('edit_revisi');

    $post = [
        'tanggal'            => (string) ($_POST['tanggal'] ?? ''),
        'jam_mulai'          => (string) ($_POST['jam_mulai'] ?? ''),
        'jam_selesai'        => (string) ($_POST['jam_selesai'] ?? ''),
        'kegiatan'           => (string) ($_POST['kegiatan'] ?? ''),
        'deskripsi'          => (string) ($_POST['deskripsi'] ?? ''),
        'hasil'              => (string) ($_POST['hasil'] ?? ''),
        'kendala'            => (string) ($_POST['kendala'] ?? ''),
        'rencana_berikutnya' => (string) ($_POST['rencana_berikutnya'] ?? ($_POST['rencana'] ?? '')),
    ];

    /* Aturan validasi identik dengan buat_laporan.php. */
    $validasi = validasi_revisi($post);
    $galat    = $validasi['galat'];
    $bersih   = $validasi['bersih'];

    if ($galat === []) {
        $tanggal            = (string) $bersih['tanggal'];
        $jam_mulai          = (string) ($bersih['jam_mulai'] ?? '');
        $jam_selesai        = (string) ($bersih['jam_selesai'] ?? '');
        $kegiatan           = (string) ($bersih['kegiatan'] ?? '');
        $deskripsi          = (string) ($bersih['deskripsi'] ?? '');
        $hasil              = (string) ($bersih['hasil'] ?? '');
        $kendala            = (string) ($bersih['kendala'] ?? '');
        $rencana_berikutnya = (string) ($bersih['rencana_berikutnya'] ?? '');

        $stmtUpdate = $conn->prepare(
            "UPDATE laporan_harian
             SET tanggal = ?,
                 jam_mulai = NULLIF(?, ''),
                 jam_selesai = NULLIF(?, ''),
                 kegiatan = ?,
                 deskripsi = ?,
                 hasil = NULLIF(?, ''),
                 kendala = NULLIF(?, ''),
                 rencana_berikutnya = NULLIF(?, ''),
                 status = 'dikirim',
                 catatan_mentor = NULL
             WHERE id = ?
               AND user_id = ?
               AND status = 'perlu_revisi'"
        );

        if (!$stmtUpdate) {
            error_log('Gagal menyiapkan penyimpanan revisi laporan ID: ' . $laporan_id);
            $galat['umum'] = 'Gagal menyimpan revisi. Silakan coba kembali.';
        } else {
            $stmtUpdate->bind_param(
                'ssssssssii',
                $tanggal,
                $jam_mulai,
                $jam_selesai,
                $kegiatan,
                $deskripsi,
                $hasil,
                $kendala,
                $rencana_berikutnya,
                $laporan_id,
                $user_id
            );

            $ok      = $stmtUpdate->execute();
            $changed = $stmtUpdate->affected_rows;
            $stmtUpdate->close();

            if ($ok && $changed === 1) {
                catat_audit(
                    $conn,
                    'ajukan_revisi',
                    'laporan_harian',
                    $laporan_id,
                    'Mengajukan revisi laporan kepada mentor',
                    [
                        'status'         => 'perlu_revisi',
                        'catatan_mentor' => $laporan['catatan_mentor'],
                    ],
                    [
                        'status'         => 'dikirim',
                        'catatan_mentor' => null,
                    ]
                );

                /* Beri tahu seluruh mentor bahwa revisi sudah diajukan. */
                notifikasi_ke_mentor(
                    $conn,
                    $laporan_id,
                    'Revisi laporan #' . $laporan_id . ' telah diajukan dan perlu diperiksa.'
                );

                flash_set('sukses', 'Revisi berhasil diajukan dan sudah dikirim kepada mentor.');
                header('Location: detail_laporan.php?id=' . $laporan_id);
                exit;
            }

            if ($ok && $changed === 0) {
                $galat['umum'] = 'Laporan tidak berubah atau statusnya sudah berubah. Muat ulang halaman dan periksa kembali.';
            } else {
                error_log('Gagal menyimpan revisi laporan ID: ' . $laporan_id);
                $galat['umum'] = 'Gagal menyimpan revisi. Silakan coba kembali.';
            }
        }
    }

    /* Isi formulir tetap tampil bila validasi gagal. */
    $laporan['tanggal']            = $post['tanggal'];
    $laporan['jam_mulai']          = $post['jam_mulai'];
    $laporan['jam_selesai']        = $post['jam_selesai'];
    $laporan['kegiatan']           = $post['kegiatan'];
    $laporan['deskripsi']          = $post['deskripsi'];
    $laporan['hasil']              = $post['hasil'];
    $laporan['kendala']            = $post['kendala'];
    $laporan['rencana_berikutnya'] = $post['rencana_berikutnya'];
}
mulai_halaman([
    'judul' => 'Edit Revisi Laporan',
    'crumb' => 'Karyawan',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="detail_laporan.php?id=' . (int) $laporan_id . '">← Detail Laporan</a>',
]);
?>

<?php if (isset($galat['umum'])): ?>
    <div class="alert alert-gagal" role="alert">
        <span class="ikon">✕</span>
        <span><?= e($galat['umum']) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($laporan['catatan_mentor'])): ?>
    <div class="kotak-catatan" role="note" style="margin-bottom:20px">
        <strong>Catatan dari mentor:</strong><br>
        <?= e($laporan['catatan_mentor']) ?>
    </div>
<?php endif; ?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h2>Perbaiki Laporan</h2>
            <span class="text-redup text-kecil">
                Perbaiki isian sesuai catatan mentor. Setelah disimpan, laporan langsung
                dikirim ulang kepada mentor untuk diperiksa.
            </span>
        </div>
    </div>

    <div class="kartu-badan">
        <form method="POST" action="edit_revisi.php?id=<?= (int) $laporan_id ?>">

            <?= csrf_field('edit_revisi') ?>

            <div class="grup-form">
                <label class="label-form" for="tanggal">Tanggal kegiatan <span class="wajib">*</span></label>
                <input type="date" id="tanggal" name="tanggal"
                       class="<?= isset($galat['tanggal']) ? 'galat' : '' ?>"
                       value="<?= e($laporan['tanggal']) ?>" required>
                <?php if (isset($galat['tanggal'])): ?>
                    <span class="pesan-galat"><?= e($galat['tanggal']) ?></span>
                <?php endif; ?>
            </div>

            <div class="baris-form">
                <div class="grup-form">
                    <label class="label-form" for="jam_mulai">Jam mulai</label>
                    <input type="time" id="jam_mulai" name="jam_mulai"
                           class="<?= isset($galat['jam_mulai']) ? 'galat' : '' ?>"
                           value="<?= e($laporan['jam_mulai']) ?>">
                    <?php if (isset($galat['jam_mulai'])): ?>
                        <span class="pesan-galat"><?= e($galat['jam_mulai']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="jam_selesai">Jam selesai</label>
                    <input type="time" id="jam_selesai" name="jam_selesai"
                           class="<?= isset($galat['jam_selesai']) ? 'galat' : '' ?>"
                           value="<?= e($laporan['jam_selesai']) ?>">
                    <?php if (isset($galat['jam_selesai'])): ?>
                        <span class="pesan-galat"><?= e($galat['jam_selesai']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grup-form">
                <label class="label-form" for="kegiatan">Nama kegiatan <span class="wajib">*</span></label>
                <input type="text" id="kegiatan" name="kegiatan" maxlength="255"
                       class="<?= isset($galat['kegiatan']) ? 'galat' : '' ?>"
                       value="<?= e($laporan['kegiatan']) ?>" required>
                <?php if (isset($galat['kegiatan'])): ?>
                    <span class="pesan-galat"><?= e($galat['kegiatan']) ?></span>
                <?php endif; ?>
            </div>

            <div class="grup-form">
                <label class="label-form" for="deskripsi">Deskripsi kegiatan <span class="wajib">*</span></label>
                <textarea id="deskripsi" name="deskripsi"
                          class="<?= isset($galat['deskripsi']) ? 'galat' : '' ?>"
                          required><?= e($laporan['deskripsi']) ?></textarea>
                <?php if (isset($galat['deskripsi'])): ?>
                    <span class="pesan-galat"><?= e($galat['deskripsi']) ?></span>
                <?php endif; ?>
            </div>

            <div class="grup-form">
                <label class="label-form" for="hasil">Hasil kegiatan</label>
                <textarea id="hasil" name="hasil"
                          class="<?= isset($galat['hasil']) ? 'galat' : '' ?>"><?= e($laporan['hasil']) ?></textarea>
                <?php if (isset($galat['hasil'])): ?>
                    <span class="pesan-galat"><?= e($galat['hasil']) ?></span>
                <?php endif; ?>
            </div>

            <div class="grup-form">
                <label class="label-form" for="kendala">Kendala</label>
                <textarea id="kendala" name="kendala"
                          class="<?= isset($galat['kendala']) ? 'galat' : '' ?>"><?= e($laporan['kendala']) ?></textarea>
                <?php if (isset($galat['kendala'])): ?>
                    <span class="pesan-galat"><?= e($galat['kendala']) ?></span>
                <?php endif; ?>
            </div>

            <div class="grup-form">
                <label class="label-form" for="rencana_berikutnya">Rencana berikutnya</label>
                <textarea id="rencana_berikutnya" name="rencana_berikutnya"
                          class="<?= isset($galat['rencana_berikutnya']) ? 'galat' : '' ?>"><?= e($laporan['rencana_berikutnya']) ?></textarea>
                <?php if (isset($galat['rencana_berikutnya'])): ?>
                    <span class="pesan-galat"><?= e($galat['rencana_berikutnya']) ?></span>
                <?php endif; ?>
            </div>

            <div class="akhir-halaman mt-3">
                <button type="submit" class="tombol tombol-utama">Ajukan Revisi</button>
                <button type="button" class="tombol tombol-garis"
                        data-validasi-ai data-hasil="#hasilValidasiAI">✦ Periksa kelengkapan (AI)</button>
                <a class="tombol tombol-garis"
                   href="detail_laporan.php?id=<?= (int) $laporan_id ?>">Batal</a>
            </div>

            <div id="hasilValidasiAI" class="narasi-hasil" style="margin-top:14px" hidden></div>
        </form>
    </div>
</section>

<?php akhiri_halaman(); ?>
