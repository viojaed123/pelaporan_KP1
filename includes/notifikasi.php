<?php
/**
 * includes/notifikasi.php
 * Satu sumber untuk pembuatan notifikasi.
 *
 * Sebelumnya logika ini terduplikasi di notifikasi_helper.php,
 * detail_laporan.php, dan edit_revisi.php dengan perilaku yang sedikit
 * berbeda-beda. Sekarang semua memakai berkas ini.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('buat_notifikasi')) {
    /**
     * Buat satu notifikasi untuk seorang pengguna.
     *
     * @param int         $userId    penerima
     * @param string      $pesan     isi singkat
     * @param int|null    $laporanId laporan terkait (opsional)
     * @param string|null $link      tautan internal; null = pakai bawaan
     */
    function buat_notifikasi(
        mysqli $conn,
        int $userId,
        string $pesan,
        ?int $laporanId = null,
        ?string $link = null
    ): bool {
        if ($userId < 1) {
            return false;
        }

        $link = $link ?? ($laporanId !== null ? 'detail_laporan.php?id=' . $laporanId : 'riwayat.php');
        $link = bersihkan_tautan($link);

        try {
            $stmt = $conn->prepare(
                'INSERT INTO notifikasi (user_id, laporan_id, pesan, link)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log('notifikasi: prepare gagal - ' . $conn->error);
                return false;
            }
            $stmt->bind_param('iiss', $userId, $laporanId, $pesan, $link);
            $ok = $stmt->execute();
            if (!$ok) {
                error_log('notifikasi: execute gagal - ' . $stmt->error);
            }
            $stmt->close();
            return (bool) $ok;
        } catch (Throwable $t) {
            error_log('notifikasi: ' . $t->getMessage());
            return false;
        }
    }
}

if (!function_exists('bersihkan_tautan')) {
    /**
     * Hanya izinkan tautan relatif internal (mencegah open redirect /
     * javascript: dari data tak terpercaya).
     */
    function bersihkan_tautan(string $tautan): string
    {
        $tautan = trim($tautan);

        if ($tautan === '' || strlen($tautan) > 255) {
            return 'riwayat.php';
        }
        // Tolak skema absolut / protokol-relatif / javascript:
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $tautan) === 1
            || str_starts_with($tautan, '//')
            || str_contains($tautan, '..')) {
            return 'riwayat.php';
        }

        return $tautan;
    }
}

if (!function_exists('notifikasi_ke_mentor')) {
    /** Kabari seluruh akun mentor (mis. ada laporan baru dikirim). */
    function notifikasi_ke_mentor(mysqli $conn, int $laporanId, string $pesan): void
    {
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'mentor'");
            if (!$stmt) {
                return;
            }
            $stmt->execute();
            $res = $stmt->get_result();

            $idMentor = [];
            while ($baris = $res->fetch_assoc()) {
                $idMentor[] = (int) $baris['id'];
            }
            $stmt->close();

            $link = 'mentor_detail.php?id=' . $laporanId;
            foreach ($idMentor as $id) {
                buat_notifikasi($conn, $id, $pesan, $laporanId, $link);
            }
        } catch (Throwable $t) {
            error_log('notifikasi_ke_mentor: ' . $t->getMessage());
        }
    }
}

if (!function_exists('notifikasi_ke_karyawan')) {
    /** Kabari pemilik laporan (mis. mentor meminta revisi / menyetujui). @return bool */
    function notifikasi_ke_karyawan(mysqli $conn, int $laporanId, string $pesan): bool
    {
        try {
            $stmt = $conn->prepare('SELECT user_id FROM laporan_harian WHERE id = ? LIMIT 1');
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('i', $laporanId);
            $stmt->execute();
            $stmt->bind_result($pemilik);
            $ada = $stmt->fetch();
            $stmt->close();

            if ($ada && (int) $pemilik > 0) {
                return buat_notifikasi(
                    $conn,
                    (int) $pemilik,
                    $pesan,
                    $laporanId,
                    'detail_laporan.php?id=' . $laporanId
                );
            }
        } catch (Throwable $t) {
            error_log('notifikasi_ke_karyawan: ' . $t->getMessage());
        }
        return false;
    }
}

if (!function_exists('jumlah_notifikasi_belum_dibaca')) {
    function jumlah_notifikasi_belum_dibaca(mysqli $conn, int $userId): int
    {
        try {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) FROM notifikasi WHERE user_id = ? AND sudah_dibaca = 0'
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->bind_result($n);
            $stmt->fetch();
            $stmt->close();
            return (int) $n;
        } catch (Throwable $t) {
            return 0;
        }
    }
}
