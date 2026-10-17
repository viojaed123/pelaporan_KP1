<?php
/**
 * notifikasi_helper.php
 * Pembungkus kompatibilitas untuk kode lama yang memanggil buatNotifikasi().
 * Implementasi sebenarnya ada di includes/notifikasi.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!function_exists('buatNotifikasi')) {
    /**
     * Kompatibilitas mundur.
     *
     * @param string $link bawaan lama 'riwayat_laporan.php' (halaman itu tidak
     *                     ada) — bila tidak diisi, tautan ditentukan otomatis.
     */
    function buatNotifikasi(
        int $userId,
        string $pesan,
        ?int $laporanId = null,
        string $link = ''
    ): bool {
        global $conn;

        if (!$conn instanceof mysqli) {
            return false;
        }

        return buat_notifikasi(
            $conn,
            $userId,
            $pesan,
            $laporanId,
            $link !== '' ? $link : null
        );
    }
}
