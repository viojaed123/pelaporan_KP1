<?php
/**
 * lihat_foto.php — menyajikan berkas bukti gambar dari folder uploads/.
 *
 * PERBAIKAN BUG AKSES:
 *   Versi lama memakai JOIN ... AND lh.user_id = ? sehingga hanya pemilik
 *   foto yang boleh melihat. Akibatnya mentor/admin yang memeriksa bukti
 *   selalu mendapat 404. Kini kepemilikan diambil TANPA filter, lalu izin
 *   diberikan bila: sesi = pemilik, ATAU peran sesi mentor/admin.
 *
 * Catatan: file ini mengeluarkan data biner gambar, sehingga TIDAK memakai
 * mulai_halaman()/akhiri_halaman(). Hanya bootstrap + wajib_login().
 * Pemeriksaan MIME/finfo, pola nama berkas, dan header caching dipertahankan.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();
$foto_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$foto_id || $foto_id < 1) {
    http_response_code(400);
    exit('ID foto tidak valid.');
}

/*
 * Ambil data foto beserta pemilik laporannya — tanpa menyaring pemilik,
 * agar mentor/admin dapat memeriksa bukti.
 */
$stmt = $conn->prepare(
    'SELECT bg.nama_file, bg.tipe_mime, lh.user_id AS pemilik
     FROM bukti_gambar bg
     INNER JOIN laporan_harian lh
        ON lh.id = bg.laporan_id
     WHERE bg.id = ?
     LIMIT 1'
);

if (!$stmt) {
    http_response_code(500);
    exit('Terjadi kesalahan saat memuat foto.');
}

$stmt->bind_param('i', $foto_id);
$stmt->execute();
$stmt->bind_result($nama_file, $tipe_mime, $pemilik);

if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(404);
    exit('Foto tidak ditemukan atau akses ditolak.');
}

$stmt->close();

/*
 * Izin: pemilik foto, ATAU mentor, ATAU admin.
 */
$boleh = ((int) $pemilik === $user_id)
    || in_array(sesi_role(), ['mentor', 'admin'], true);

if (!$boleh) {
    http_response_code(403);
    exit('Anda tidak berhak melihat foto ini.');
}

/* Hanya tipe gambar yang diizinkan. */
$tipeDiizinkan = [
    'image/jpeg',
    'image/png'
];

if (!in_array($tipe_mime, $tipeDiizinkan, true)) {
    http_response_code(403);
    exit('Tipe file tidak diizinkan.');
}

/*
 * Pastikan nama file sesuai format nama acak
 * yang digunakan saat unggahan.
 */
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', (string) $nama_file)) {
    http_response_code(404);
    exit('File foto tidak valid.');
}

$folder = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$path = $folder . DIRECTORY_SEPARATOR . $nama_file;

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File foto tidak ditemukan.');
}

/* Verifikasi isi file sebelum dikirim ke browser. */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeAktual = $finfo->file($path);

if ($mimeAktual !== $tipe_mime) {
    http_response_code(403);
    exit('Isi file tidak sesuai dengan tipe yang tercatat.');
}

header('Content-Type: ' . $mimeAktual);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));

readfile($path);
exit;
