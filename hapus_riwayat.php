<?php
/**
 * hapus_riwayat.php — menghapus satu catatan riwayat tindak lanjut kasus.
 *
 * Pembaruan dari versi lama (perbaikan bug keamanan):
 *   · bootstrap + penjaga peran terpusat (kini: wajib_login + izin per-kasus)
 *   · WAJIB POST (semula hanya menerima POST di satu bagian, namun tombol di
 *     detail_kasus.php masih memakai mekanisme lama) — metode lain ditolak 405
 *   · verifikasi CSRF memakai skema per-aksi: csrf_cek_atau_matikan('hapus_riwayat')
 *     (semula membandingkan $_SESSION['csrf_token'] global)
 *   · catat_audit() menyimpan data_lama sebelum penghapusan
 *   · setelah menghapus: flash_set + header Location + exit (pola PRG)
 *
 * KEAMANAN (IDOR): izin penghapusan diperiksa per-kasus melalui
 * kasus_izin_kelola() — pembuat, PIC, anggota tim, atau mentor/admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

/**
 * Kembalikan pengguna ke detail kasus (atau daftar kasus bila kasus
 * tidak diketahui) dengan pesan flash. Menghentikan eksekusi.
 */
function kembali_ke_detail_kasus(int $kasus_id, string $jenis, string $pesan): never
{
    flash_set($jenis, $pesan);

    $tujuan = $kasus_id > 0
        ? 'detail_kasus.php?id=' . $kasus_id
        : 'dashboard_kasus.php';

    header('Location: ' . $tujuan);
    exit;
}

/* ==========================================
 * 1. HANYA IZINKAN POST
 * ========================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode permintaan tidak diizinkan.');
}

/* ==========================================
 * 2. VALIDASI CSRF (per-aksi)
 * ========================================== */

csrf_cek_atau_matikan('hapus_riwayat');

/* ==========================================
 * 3. VALIDASI ID RIWAYAT
 * ========================================== */

$riwayat_id = filter_input(
    INPUT_POST,
    'riwayat_id',
    FILTER_VALIDATE_INT
);

if (!$riwayat_id || $riwayat_id < 1) {
    http_response_code(400);
    exit('ID riwayat tidak valid.');
}

/* ==========================================
 * 4. AMBIL DATA RIWAYAT (untuk audit & redirect)
 * ========================================== */

$sql = "
    SELECT *
    FROM riwayat_tindak_lanjut
    WHERE id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Gagal menyiapkan pemeriksaan riwayat.');
}

$stmt->bind_param('i', $riwayat_id);
$stmt->execute();

$result = $stmt->get_result();
$riwayat = $result->fetch_assoc();

$stmt->close();

if (!$riwayat) {
    kembali_ke_detail_kasus(
        0,
        'gagal',
        'Riwayat tindak lanjut tidak ditemukan.'
    );
}

$kasus_id = (int) ($riwayat['kasus_id'] ?? 0);

/* Izin: pembuat, PIC, anggota tim kasus, atau mentor/admin. */
$kasus_induk = kasus_ambil($conn, $kasus_id);
if (!$kasus_induk || !kasus_izin_kelola($conn, $kasus_induk, (int) sesi_user_id(), sesi_role())) {
    http_response_code(403);
    exit('Anda tidak berhak menghapus riwayat tindak lanjut kasus ini.');
}

/* ==========================================
 * 5. HAPUS RIWAYAT
 * ========================================== */

$sql_delete = "
    DELETE FROM riwayat_tindak_lanjut
    WHERE id = ?
    LIMIT 1
";

$stmt_delete = $conn->prepare($sql_delete);

if (!$stmt_delete) {
    kembali_ke_detail_kasus(
        $kasus_id,
        'gagal',
        'Gagal menyiapkan proses penghapusan riwayat.'
    );
}

$stmt_delete->bind_param('i', $riwayat_id);
$berhasil = $stmt_delete->execute();
$terhapus = $stmt_delete->affected_rows;
$stmt_delete->close();

if (!$berhasil || $terhapus !== 1) {
    kembali_ke_detail_kasus(
        $kasus_id,
        'gagal',
        'Riwayat gagal dihapus. Silakan coba lagi.'
    );
}

/* Catat penghapusan beserta data lama (audit trail). */
catat_audit(
    $conn,
    'hapus_riwayat',
    'riwayat_tindak_lanjut',
    (int) $riwayat_id,
    'Menghapus riwayat tindak lanjut kasus',
    $riwayat,
    null
);

kembali_ke_detail_kasus(
    $kasus_id,
    'sukses',
    'Riwayat tindak lanjut berhasil dihapus.'
);
