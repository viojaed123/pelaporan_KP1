<?php
/**
 * edit_riwayat.php — mengubah satu catatan riwayat tindak lanjut kasus.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran terpusat (kini: wajib_login + izin per-kasus)
 *   · layout terpusat — <style> inline, <head>, dan <body> lama dihapus
 *   · fungsi e() lokal dihapus (sudah tersedia di includes/helpers.php)
 *   · token CSRF global diganti skema per-aksi (csrf_field/csrf_cek_*)
 *   · warna teal #0f766e dihapus, memakai palet PHR lewat kelas app.css
 *   · catat_audit() setiap perubahan + flash + PRG (header Location)
 *
 * KEAMANAN (IDOR): izin menulis kini diperiksa per-kasus melalui
 * kasus_izin_kelola() — pembuat, PIC, anggota tim, atau mentor/admin.
 * (Semula wajib_role(['mentor','admin']) yang memaksa semua riwayat hanya
 * bisa diubah mentor/admin; sekarang karyawan boleh mengubah riwayat
 * kasus miliknya sendiri.)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

/* ==========================================
 * 1. VALIDASI ID RIWAYAT
 * ========================================== */

$riwayat_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$riwayat_id || $riwayat_id < 1) {
    http_response_code(400);
    exit('ID riwayat tidak valid.');
}

/* ==========================================
 * 2. AMBIL DATA RIWAYAT
 * ========================================== */

$sql = "
    SELECT
        r.id,
        r.kasus_id,
        r.user_id,
        r.tindakan,
        r.hasil,
        r.kendala,
        r.rencana_selanjutnya,
        r.tanggal_tindak_lanjut,
        k.kode_kasus,
        k.judul,
        u.nama AS nama_pencatat
    FROM riwayat_tindak_lanjut r
    INNER JOIN kasus k ON k.id = r.kasus_id
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Gagal menyiapkan query riwayat.');
}

$stmt->bind_param('i', $riwayat_id);
$stmt->execute();

$result = $stmt->get_result();
$riwayat = $result->fetch_assoc();

$stmt->close();

if (!$riwayat) {
    http_response_code(404);
    exit('Riwayat tindak lanjut tidak ditemukan.');
}

$kasus_id = (int) $riwayat['kasus_id'];

/* Izin: pembuat, PIC, anggota tim kasus, atau mentor/admin. */
$kasus_induk = kasus_ambil($conn, $kasus_id);
if (!$kasus_induk || !kasus_izin_kelola($conn, $kasus_induk, (int) sesi_user_id(), sesi_role())) {
    http_response_code(403);
    exit('Anda tidak berhak mengubah riwayat tindak lanjut kasus ini.');
}

/* Simpan nilai lama SEBELUM ditimpa input baru (untuk audit trail). */
$data_lama = [
    'tindakan'            => (string) ($riwayat['tindakan'] ?? ''),
    'hasil'               => (string) ($riwayat['hasil'] ?? ''),
    'kendala'             => (string) ($riwayat['kendala'] ?? ''),
    'rencana_selanjutnya' => (string) ($riwayat['rencana_selanjutnya'] ?? ''),
];

/* ==========================================
 * 3. PROSES SIMPAN PERUBAHAN
 * ========================================== */

$pesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('edit_riwayat');

    $tindakan = trim((string) ($_POST['tindakan'] ?? ''));
    $hasil    = trim((string) ($_POST['hasil'] ?? ''));
    $kendala  = trim((string) ($_POST['kendala'] ?? ''));
    $rencana  = trim((string) ($_POST['rencana_selanjutnya'] ?? ''));

    /* Pertahankan input jika validasi gagal. */
    $riwayat['tindakan']            = $tindakan;
    $riwayat['hasil']               = $hasil;
    $riwayat['kendala']             = $kendala;
    $riwayat['rencana_selanjutnya'] = $rencana;

    if ($tindakan === '') {
        $pesan = 'Tindakan wajib diisi.';
    } elseif (
        mb_strlen($tindakan) > 10000 ||
        mb_strlen($hasil) > 10000 ||
        mb_strlen($kendala) > 10000 ||
        mb_strlen($rencana) > 10000
    ) {
        $pesan = 'Setiap isian maksimal 10.000 karakter.';
    } else {
        $sql_update = "
            UPDATE riwayat_tindak_lanjut
            SET
                tindakan = ?,
                hasil = ?,
                kendala = ?,
                rencana_selanjutnya = ?
            WHERE id = ?
        ";

        $stmt_update = $conn->prepare($sql_update);

        if (!$stmt_update) {
            $pesan = 'Gagal menyiapkan perubahan riwayat.';
        } else {
            $stmt_update->bind_param(
                'ssssi',
                $tindakan,
                $hasil,
                $kendala,
                $rencana,
                $riwayat_id
            );

            if ($stmt_update->execute()) {
                $stmt_update->close();

                /* Catat perubahan data (audit trail). */
                catat_audit(
                    $conn,
                    'edit_riwayat',
                    'riwayat_tindak_lanjut',
                    (int) $riwayat_id,
                    'Mengubah riwayat tindak lanjut kasus ' . (string) $riwayat['kode_kasus'],
                    $data_lama,
                    [
                        'tindakan'            => $tindakan,
                        'hasil'               => $hasil,
                        'kendala'             => $kendala,
                        'rencana_selanjutnya' => $rencana,
                    ]
                );

                flash_set('sukses', 'Riwayat tindak lanjut berhasil diperbarui.');
                header('Location: detail_kasus.php?id=' . $kasus_id);
                exit;
            }

            $pesan = 'Perubahan gagal disimpan. Silakan coba lagi.';
            $stmt_update->close();
        }
    }
}

/* ==========================================
 * 4. TAMPILAN
 * ========================================== */

mulai_halaman([
    'judul' => 'Edit Riwayat Tindak Lanjut',
    'crumb' => 'Kasus',
    'aktif' => 'kasus',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="detail_kasus.php?id=' . $kasus_id . '">← Detail Kasus</a>',
]);
?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Edit Riwayat Tindak Lanjut</h3>
            <span class="text-redup text-kecil">
                Kasus: <strong><?= e($riwayat['kode_kasus']) ?></strong> — <?= e($riwayat['judul']) ?>
            </span>
        </div>
    </div>
    <div class="kartu-badan">
        <div class="rincian">
            <div class="baris">
                <span class="kunci">Pencatat Asli</span>
                <span class="nilai"><?= e($riwayat['nama_pencatat'] ?? 'Pengguna tidak diketahui') ?></span>
            </div>
            <div class="baris">
                <span class="kunci">Waktu Pencatatan Asli</span>
                <span class="nilai"><?= e($riwayat['tanggal_tindak_lanjut']) ?></span>
            </div>
        </div>
    </div>
</section>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Perbarui Catatan</h3>
            <span class="text-redup text-kecil">Perubahan akan tercatat pada riwayat audit.</span>
        </div>
    </div>

    <form method="POST" action="edit_riwayat.php?id=<?= (int) $riwayat_id ?>">
        <?= csrf_field('edit_riwayat') ?>

        <div class="kartu-badan">
            <?php if ($pesan !== ''): ?>
                <div class="alert alert-gagal" role="alert">
                    <span class="ikon">✕</span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <div class="grup-form">
                <label class="label-form" for="tindakan">
                    Tindakan yang Dilakukan <span class="wajib">*</span>
                </label>
                <textarea id="tindakan" name="tindakan" maxlength="10000" required><?= e($riwayat['tindakan']) ?></textarea>
            </div>

            <div class="grup-form">
                <label class="label-form" for="hasil">Hasil Tindak Lanjut</label>
                <textarea id="hasil" name="hasil" maxlength="10000"><?= e($riwayat['hasil']) ?></textarea>
            </div>

            <div class="grup-form">
                <label class="label-form" for="kendala">Kendala</label>
                <textarea id="kendala" name="kendala" maxlength="10000"><?= e($riwayat['kendala']) ?></textarea>
            </div>

            <div class="grup-form mb-0">
                <label class="label-form" for="rencana_selanjutnya">Rencana Selanjutnya</label>
                <textarea id="rencana_selanjutnya" name="rencana_selanjutnya" maxlength="10000"><?= e($riwayat['rencana_selanjutnya']) ?></textarea>
            </div>
        </div>

        <div class="kartu-kaki">
            <button type="submit" class="tombol tombol-utama">Simpan Perubahan</button>
            <a class="tombol tombol-garis" href="detail_kasus.php?id=<?= $kasus_id ?>">Batal</a>
        </div>
    </form>
</section>

<?php akhiri_halaman(); ?>
