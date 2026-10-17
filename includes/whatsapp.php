<?php
/**
 * includes/whatsapp.php
 * Pengiriman pesan WhatsApp melalui gateway pihak ketiga.
 *
 * Gateway didukung: 'fonnte', 'wablas'. Bila WA_GATEWAY kosong
 * (bawaan), fungsi mengembalikan status nonaktif tanpa galat — sehingga
 * alur pengiriman laporan tetap dapat diuji memakai email.
 *
 * Konfigurasi (includes/config.php):
 *   WA_GATEWAY = '' | 'fonnte' | 'wablas'
 *   WA_API_KEY = token gateway
 */

declare(strict_types=1);

if (!function_exists('wa_normalkan_nomor')) {
    /**
     * Normalkan nomor telepon Indonesia ke format internasional tanpa
     * tanda baca, mis. 0812-3456-7890 -> 6281234567890.
     */
    function wa_normalkan_nomor(string $nomor): string
    {
        $angka = preg_replace('/\D+/', '', $nomor) ?? '';
        if ($angka === '') {
            return '';
        }
        if (str_starts_with($angka, '0')) {
            $angka = '62' . substr($angka, 1);
        } elseif (str_starts_with($angka, '8')) {
            $angka = '62' . $angka;
        }
        return $angka;
    }
}

if (!function_exists('wa_aktif')) {
    /** Apakah gateway WhatsApp dikonfigurasi? */
    function wa_aktif(): bool
    {
        return WA_GATEWAY !== '' && WA_API_KEY !== '';
    }
}

if (!function_exists('kirim_whatsapp')) {
    /**
     * Kirim pesan WhatsApp.
     *
     * @return array{ok: bool, galat: ?string, mode: string}
     */
    function kirim_whatsapp(string $nomor, string $pesan): array
    {
        $tujuan = wa_normalkan_nomor($nomor);

        if ($tujuan === '') {
            return ['ok' => false, 'galat' => 'Nomor WhatsApp tidak valid.', 'mode' => '-'];
        }
        if (!wa_aktif()) {
            return [
                'ok'    => false,
                'galat' => 'Gateway WhatsApp belum dikonfigurasi (WA_GATEWAY/WA_API_KEY kosong).',
                'mode'  => 'nonaktif',
            ];
        }

        $url     = '';
        $payload = [];
        $header  = [];

        switch (WA_GATEWAY) {
            case 'fonnte':
                $url     = 'https://api.fonnte.com/send';
                $header  = ['Authorization: ' . WA_API_KEY];
                $payload = ['target' => $tujuan, 'message' => $pesan];
                break;

            case 'wablas':
                $url     = 'https://jogja.wablas.com/api/send-message';
                $header  = ['Authorization: ' . WA_API_KEY];
                $payload = ['phone' => $tujuan, 'message' => $pesan];
                break;

            default:
                return ['ok' => false, 'galat' => 'Gateway WhatsApp tidak dikenal.', 'mode' => '-'];
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $header,
                CURLOPT_POSTFIELDS     => http_build_query($payload),
            ]);
            $balasan = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $galat   = (string) curl_error($ch);
            curl_close($ch);

            if ($balasan === false) {
                return ['ok' => false, 'galat' => 'Gagal menghubungi gateway: ' . $galat, 'mode' => WA_GATEWAY];
            }
            if ($status < 200 || $status >= 300) {
                return [
                    'ok'    => false,
                    'galat' => 'Gateway menolak (HTTP ' . $status . '): ' . potong((string) $balasan, 160),
                    'mode'  => WA_GATEWAY,
                ];
            }

            return ['ok' => true, 'galat' => null, 'mode' => WA_GATEWAY];
        } catch (Throwable $t) {
            return ['ok' => false, 'galat' => 'Kesalahan pengiriman: ' . $t->getMessage(), 'mode' => WA_GATEWAY];
        }
    }
}
