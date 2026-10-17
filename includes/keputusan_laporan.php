<?php
/**
 * includes/keputusan_laporan.php
 * Logika tunggal untuk menerapkan keputusan mentor pada sebuah laporan.
 *
 * Dipakai bersama oleh mentor_proses.php (satu laporan) dan mentor_bulk.php
 * (banyak laporan sekaligus) agar perilaku, audit, dan notifikasi konsisten.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('laporan_putuskan')) {
    /**
     * Terapkan keputusan ('diperiksa' | 'perlu_revisi') pada satu laporan.
     *
     * @return array{ok: bool, kode: string, pesan: string}
     *   kode: 'ok' | 'tidak_ada' | 'tidak_valid' | 'gagal'
     */
    function laporan_putuskan(
        mysqli $conn,
        int $laporanId,
        string $keputusan,
        string $catatan
    ): array {
        if (!in_array($keputusan, ['diperiksa', 'perlu_revisi'], true)) {
            return ['ok' => false, 'kode' => 'tidak_valid', 'pesan' => 'Keputusan tidak valid.'];
        }

        $stmt = $conn->prepare(
            "SELECT * FROM laporan_harian
              WHERE id = ? AND status IN ('draft', 'dikirim')"
        );
        if (!$stmt) {
            return ['ok' => false, 'kode' => 'gagal', 'pesan' => 'Gagal menyiapkan query.'];
        }
        $stmt->bind_param('i', $laporanId);
        $stmt->execute();
        $lama = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lama) {
            return [
                'ok'    => false,
                'kode'  => 'tidak_ada',
                'pesan' => 'Laporan #' . $laporanId
                    . ' tidak diproses (status berubah atau sudah ditangani).',
            ];
        }

        $stmt = $conn->prepare(
            "UPDATE laporan_harian
                SET status = ?, catatan_mentor = ?
              WHERE id = ? AND status IN ('draft', 'dikirim')"
        );
        if (!$stmt) {
            return ['ok' => false, 'kode' => 'gagal', 'pesan' => 'Gagal menyiapkan pembaruan.'];
        }
        $stmt->bind_param('ssi', $keputusan, $catatan, $laporanId);
        $okExec = $stmt->execute();
        $diubah = $stmt->affected_rows;
        $stmt->close();

        if (!$okExec || $diubah !== 1) {
            return [
                'ok'    => false,
                'kode'  => 'tidak_ada',
                'pesan' => 'Laporan #' . $laporanId . ' tidak diproses (status berubah).',
            ];
        }

        $baru = array_merge($lama, [
            'status'         => $keputusan,
            'catatan_mentor' => $catatan,
        ]);

        $ringkasan = $keputusan === 'diperiksa'
            ? 'Laporan #' . $laporanId . ' ditandai diperiksa oleh ' . sesi_nama()
            : 'Laporan #' . $laporanId . ' dikembalikan untuk revisi oleh ' . sesi_nama();

        catat_audit(
            $conn,
            'mentor_periksa',
            'laporan_harian',
            $laporanId,
            $ringkasan,
            $lama,
            $baru
        );

        /* Notifikasi in-app + surel + WhatsApp (bila nomor ada). */
        $terkirim = kabari_status_laporan($conn, $laporanId, $keputusan, $catatan);
        if (!$terkirim['aplikasi']) {
            error_log('Notifikasi status laporan gagal dibuat. Laporan ID: ' . $laporanId);
        }

        return [
            'ok'    => true,
            'kode'  => 'ok',
            'pesan' => $keputusan === 'diperiksa' ? 'diperiksa' : 'perlu_revisi',
        ];
    }
}
