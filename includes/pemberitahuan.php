<?php
/**
 * includes/pemberitahuan.php
 * Pemberitahuan perubahan status laporan ke pemilik laporan melalui
 * tiga kanal: notifikasi dalam aplikasi, surel, dan (bila nomor telepon
 * tersedia & gateway aktif) WhatsApp.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notifikasi.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/whatsapp.php';

if (!function_exists('kabari_status_laporan')) {
    /**
     * Kabari pemilik laporan bahwa statusnya berubah.
     *
     * @param string $keputusan 'diperiksa' | 'perlu_revisi'
     * @return array{aplikasi: bool, surel: bool, whatsapp: bool}
     */
    function kabari_status_laporan(
        mysqli $conn,
        int $laporanId,
        string $keputusan,
        string $catatan = ''
    ): array {
        $hasil = ['aplikasi' => false, 'surel' => false, 'whatsapp' => false];

        // Ambil data pemilik laporan
        $stmt = $conn->prepare(
            'SELECT u.id, u.nama, u.email, u.telepon
               FROM laporan_harian l
               JOIN users u ON u.id = l.user_id
              WHERE l.id = ? LIMIT 1'
        );
        if (!$stmt) {
            return $hasil;
        }
        $stmt->bind_param('i', $laporanId);
        $stmt->execute();
        $res  = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$user) {
            return $hasil;
        }

        $userId = (int) $user['id'];
        $nama   = (string) $user['nama'];
        $email  = (string) $user['email'];
        $telepon = (string) ($user['telepon'] ?? '');

        $revisi = ($keputusan === 'perlu_revisi');

        $ringkas = $revisi
            ? 'Laporan #' . $laporanId . ' perlu direvisi.'
            : 'Laporan #' . $laporanId . ' telah diperiksa.';

        // 1) Notifikasi dalam aplikasi
        $hasil['aplikasi'] = notifikasi_ke_karyawan($conn, $laporanId, $ringkas);

        // 2) Surel
        $namaAman = e($nama);
        if ($revisi) {
            $badan = '<p style="margin:0 0 14px;color:#334155;font-size:14px;">'
                . 'Halo ' . $namaAman . ', laporan harian Anda perlu <strong>direvisi</strong> '
                . 'oleh mentor.</p>'
                . '<p style="margin:0 0 6px;color:#64748b;font-size:13px;"><strong>Catatan mentor:</strong></p>'
                . '<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;'
                . 'padding:12px 14px;color:#7F1D1D;font-size:14px;">' . nl2br(e($catatan)) . '</div>';
            $subjek = 'Laporan Harian Perlu Revisi';
            $pesanWa = "Laporan harian Anda perlu direvisi.\n\nCatatan mentor:\n" . $catatan;
        } else {
            $badan = '<p style="margin:0 0 14px;color:#334155;font-size:14px;">'
                . 'Halo ' . $namaAman . ', laporan harian Anda telah <strong>diperiksa</strong> '
                . 'dan disetujui oleh mentor.</p>';
            $subjek = 'Laporan Harian Telah Diperiksa';
            $pesanWa = 'Laporan harian Anda telah diperiksa dan disetujui mentor.';
        }

        $badan .= '<p style="margin:16px 0 0;"><a href="' . e(basis_url() . '/riwayat.php') . '"'
            . ' style="color:' . e(WARNA_BIRU) . ';font-weight:600;text-decoration:none;">'
            . 'Buka riwayat laporan &rarr;</a></p>';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $html = template_surat($subjek, $badan, 'Pesan otomatis dari ' . e(APP_NAMA) . '.');
            $teks = $ringkas . ($revisi && $catatan !== '' ? "\n\nCatatan mentor:\n" . $catatan : '');
            $kirim = kirim_email($email, $subjek . ' - ' . APP_NAMA, $html, $teks);
            $hasil['surel'] = (bool) ($kirim['ok'] ?? false);
        }

        // 3) WhatsApp (bila nomor tersedia & gateway aktif)
        if ($telepon !== '' && wa_aktif()) {
            $wa = kirim_whatsapp($telepon, '*' . $subjek . "*\n\n" . $pesanWa);
            $hasil['whatsapp'] = (bool) ($wa['ok'] ?? false);
        }

        return $hasil;
    }
}
