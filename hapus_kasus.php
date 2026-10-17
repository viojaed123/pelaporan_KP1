<?php
/**
 * hapus_kasus.php — menghapus satu kasus beserta data turunannya.
 *
 * Alur aman:
 *   - GET  : menampilkan halaman konfirmasi (tanpa menghapus apa pun).
 *   - POST : menghapus setelah verifikasi CSRF.
 *
 * Izin: pembuat kasus sendiri, atau mentor/admin. Pengguna lain ditolak (403).
 * Penghapusan dicatat pada audit trail beserta ringkasan data lama.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid  = (int) sesi_user_id();
$role = sesi_role();

$kasus_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$kasus_id || $kasus_id < 1) {
    http_response_code(400);
    exit('ID kasus tidak valid.');
}

$kasus = kasus_ambil($conn, (int) $kasus_id);
if (!$kasus) {
    http_response_code(404);
    exit('Kasus tidak ditemukan.');
}

if (!kasus_izin_hapus($conn, $kasus, $uid, $role)) {
    http_response_code(403);
    exit('Anda tidak berhak menghapus kasus ini.');
}

/* ==========================================
 * PROSES HAPUS (POST)
 * ========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('hapus_kasus');

    $idHapus = (int) $kasus_id;
    $ringkasan = [
        'kode_kasus'   => (string) $kasus['kode_kasus'],
        'judul'        => (string) $kasus['judul'],
        'status_kasus' => (string) $kasus['status_kasus'],
    ];

    /* Hapus data turunan lebih dulu (aman walau FK sudah CASCADE). */
    foreach (['kasus_pihak', 'kasus_penugasan', 'kasus_status_log', 'riwayat_tindak_lanjut'] as $tabel) {
        $del = $conn->prepare("DELETE FROM {$tabel} WHERE kasus_id = ?");
        if ($del) {
            $del->bind_param('i', $idHapus);
            $del->execute();
            $del->close();
        }
    }

    $stmt = $conn->prepare('DELETE FROM kasus WHERE id = ? LIMIT 1');
    if (!$stmt) {
        flash_set('gagal', 'Gagal menyiapkan penghapusan kasus.');
        header('Location: detail_kasus.php?id=' . $idHapus);
        exit;
    }
    $stmt->bind_param('i', $idHapus);
    $ok = $stmt->execute();
    $terhapus = $stmt->affected_rows;
    $stmt->close();

    if (!$ok || $terhapus !== 1) {
        flash_set('gagal', 'Kasus gagal dihapus. Silakan coba lagi.');
        header('Location: detail_kasus.php?id=' . $idHapus);
        exit;
    }

    catat_audit(
        $conn,
        'hapus_kasus',
        'kasus',
        $idHapus,
        'Menghapus kasus ' . $ringkasan['kode_kasus'],
        $ringkasan,
        null
    );

    flash_set('sukses', 'Kasus ' . $ringkasan['kode_kasus'] . ' berhasil dihapus.');
    header('Location: dashboard_kasus.php');
    exit;
}

/* ==========================================
 * HALAMAN KONFIRMASI (GET)
 * ========================================== */

$jmlRiwayat = 0;
$stmt = $conn->prepare('SELECT COUNT(*) AS j FROM riwayat_tindak_lanjut WHERE kasus_id = ?');
if ($stmt) {
    $stmt->bind_param('i', $kasus_id);
    $stmt->execute();
    $jmlRiwayat = (int) ($stmt->get_result()->fetch_assoc()['j'] ?? 0);
    $stmt->close();
}

mulai_halaman([
    'judul' => 'Hapus Kasus',
    'crumb' => 'Kasus',
    'aktif' => 'kasus',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="detail_kasus.php?id=' . (int) $kasus_id . '">← Detail Kasus</a>',
]);
?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Hapus Kasus <?= e((string) $kasus['kode_kasus']) ?></h3>
            <span class="text-redup text-kecil">Tindakan ini tidak dapat dibatalkan.</span>
        </div>
    </div>

    <form method="POST" action="hapus_kasus.php?id=<?= (int) $kasus_id ?>">
        <?= csrf_field('hapus_kasus') ?>

        <div class="kartu-badan">
            <div class="alert alert-gagal" role="alert">
                <span class="ikon"><?= ikon_sp('peringatan') ?></span>
                <span>
                    Kasus berikut beserta pihak terlibat, penugasan tim, linimasa status,
                    dan<?= $jmlRiwayat > 0 ? ' ' . (int) $jmlRiwayat . ' catatan' : ' semua catatan' ?>
                    riwayat tindak lanjutnya akan dihapus permanen.
                </span>
            </div>

            <div class="rincian">
                <div class="baris"><span class="kunci">Kode Kasus</span><span class="nilai"><?= e((string) $kasus['kode_kasus']) ?></span></div>
                <div class="baris"><span class="kunci">Judul</span><span class="nilai"><?= e((string) $kasus['judul']) ?></span></div>
                <div class="baris"><span class="kunci">Status</span><span class="nilai"><?= e(label_status_kasus((string) $kasus['status_kasus'])) ?></span></div>
                <div class="baris"><span class="kunci">Lokasi</span><span class="nilai"><?= e((string) ($kasus['nama_lokasi'] ?? '-')) ?></span></div>
            </div>
        </div>

        <div class="kartu-kaki">
            <button type="submit" class="tombol tombol-bahaya">Ya, Hapus Permanen</button>
            <a class="tombol tombol-garis" href="detail_kasus.php?id=<?= (int) $kasus_id ?>">Batal</a>
        </div>
    </form>
</section>

<?php akhiri_halaman(); ?>
