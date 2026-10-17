<?php
/**
 * komentar_proses.php — simpan komentar/diskusi pada sebuah laporan.
 *
 * Boleh: pemilik laporan (karyawan) atau mentor/admin. Pihak lain yang
 * terkait diberi notifikasi dalam aplikasi.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_cek_atau_matikan('komentar_laporan');

$uid  = (int) sesi_user_id();
$role = sesi_role();

$laporanId = filter_input(INPUT_POST, 'laporan_id', FILTER_VALIDATE_INT);
$isi       = trim((string) ($_POST['isi'] ?? ''));

/* Tujuan kembali: hanya halaman yang dikenal (cegah open redirect). */
$balik = (string) ($_POST['balik'] ?? '');
$tujuan = 'detail_laporan.php?id=' . (int) $laporanId;
if ($balik !== '' && preg_match('#^[a-z_]+\.php$#', $balik) === 1) {
    $tujuan = $balik . '?id=' . (int) $laporanId . '#diskusi';
}

if (!$laporanId || $laporanId < 1) {
    flash_set('gagal', 'Laporan tidak ditemukan.');
    header('Location: dashboard.php');
    exit;
}

if (!komentar_boleh($conn, (int) $laporanId, $uid, $role)) {
    http_response_code(403);
    exit('Anda tidak berhak berkomentar pada laporan ini.');
}

$isi = (string) preg_replace('/\r\n?/', "\n", $isi);
if ($isi === '' || mb_strlen($isi) > 2000) {
    flash_set('gagal', 'Komentar harus 1-2000 karakter.');
    header('Location: ' . $tujuan);
    exit;
}

$pemilikId = null;
$st = $conn->prepare('SELECT user_id FROM laporan_harian WHERE id = ? LIMIT 1');
if ($st) {
    $st->bind_param('i', $laporanId);
    $st->execute();
    $st->bind_result($pemilikId);
    $st->fetch();
    $st->close();
}

$stmt = $conn->prepare(
    'INSERT INTO komentar_laporan (laporan_id, user_id, isi) VALUES (?, ?, ?)'
);
if (!$stmt) {
    flash_set('gagal', 'Gagal menyimpan komentar.');
    header('Location: ' . $tujuan);
    exit;
}
$stmt->bind_param('iis', $laporanId, $uid, $isi);
$stmt->execute();
$stmt->close();

catat_audit(
    $conn,
    'komentar_laporan',
    'laporan_harian',
    (int) $laporanId,
    'Menambahkan komentar pada laporan',
    null,
    null
);

/* Beri tahu pihak terkait. */
if ((int) $pemilikId === $uid) {
    // Karyawan berkomentar -> beri tahu mentor
    notifikasi_ke_mentor(
        $conn,
        (int) $laporanId,
        'Komentar baru dari karyawan pada laporan #' . $laporanId . '.'
    );
} else {
    // Mentor/admin berkomentar -> beri tahu karyawan
    notifikasi_ke_karyawan(
        $conn,
        (int) $laporanId,
        'Mentor menambahkan komentar pada laporan #' . $laporanId . '.'
    );
}

flash_set('sukses', 'Komentar berhasil dikirim.');
header('Location: ' . $tujuan);
exit;
