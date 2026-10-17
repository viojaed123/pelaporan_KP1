<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\KodeVerifikasi;
use App\Models\UserOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Kode OTP sekali pakai (porting includes/security.php §3).
 *
 * Dipakai untuk dua hal:
 *   jenis = 'login'       -- verifikasi dua faktor saat masuk
 *   jenis = 'reset_sandi' -- verifikasi saat lupa kata sandi
 *
 * Kode MENTAH hanya ada di memori dan di surel pengguna. Yang tersimpan di
 * basis data hanyalah HASH-nya, sehingga bila tabel bocor, kode yang masih
 * berlaku tidak bisa dipakai untuk masuk.
 *
 * Ciri penting:
 *   - Membuat kode baru MENONAKTIFKAN kode lama sejenis yang belum dipakai,
 *     sehingga hanya satu kode berlaku pada satu waktu.
 *   - Percobaan dibatasi (OTP_MAKS_COBA) dan dihitung SEBELUM dicocokkan,
 *     bukan sesudah -- mencegah percobaan tanpa batas pada satu kode.
 *   - Kode kedaluwarsa setelah OTP_DETIK (bawaan 5 menit).
 */
class Otp
{
    public const JENIS_LOGIN = 'login';

    public const JENIS_RESET_SANDI = 'reset_sandi';

    /**
     * Buat kode OTP baru untuk pengguna.
     *
     * @return string Kode mentah (hanya untuk dikirim; jangan disimpan).
     */
    public function buat(
        int $userId,
        string $tujuan,
        string $jenis = self::JENIS_LOGIN,
    ): string {
        $jenis = $this->normalJenis($jenis);
        $panjang = max(4, (int) config('keamanan.otp_panjang', 6));

        /*
         * random_int (bukan rand/mt_rand) dipakai karena kriptografis aman --
         * kode OTP yang dapat diprediksi berarti 2FA tidak berguna.
         * Rentangnya [10^(n-1), 10^n - 1] sehingga selalu tepat n digit
         * (tanpa nol di depan yang bisa hilang saat dibaca pengguna).
         */
        $kode = (string) random_int(
            (int) (10 ** ($panjang - 1)),
            (int) (10 ** $panjang) - 1
        );

        /*
         * Nonaktifkan kode lama sejenis yang belum dipakai. Tanpa ini, kode
         * lama yang masih berlaku akan tetap sah walau pengguna baru saja
         * meminta kode baru -- memperbesar jendela penyalahgunaan.
         */
        UserOtp::where('user_id', $userId)
            ->where('jenis', $jenis)
            ->where('dipakai', false)
            ->update(['dipakai' => true]);

        UserOtp::create([
            'user_id'     => $userId,
            'jenis'       => $jenis,
            'kode_hash'   => Hash::make($kode),
            'tujuan'      => $tujuan,
            'percobaan'   => 0,
            'dipakai'     => false,
            'kedaluwarsa' => now()->addSeconds((int) config('keamanan.otp_detik', 300)),
        ]);

        return $kode;
    }

    /**
     * Kirim kode OTP lewat surel.
     *
     * @return array{ok: bool, galat: string, mode: string}
     */
    public function kirim(string $ke, string $kode, string $nama): array
    {
        if ($ke === '' || ! filter_var($ke, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'galat' => 'Alamat tujuan tidak valid.', 'mode' => '-'];
        }

        try {
            Mail::to($ke)->send(new KodeVerifikasi($kode, $nama));

            return [
                'ok'    => true,
                'galat' => '',
                'mode'  => (string) config('mail.default', 'smtp'),
            ];
        } catch (\Throwable $t) {
            report($t);

            return [
                'ok'    => false,
                'galat' => 'Gagal mengirim surat: ' . $t->getMessage(),
                'mode'  => (string) config('mail.default', 'smtp'),
            ];
        }
    }

    /**
     * Verifikasi kode OTP terbaru yang aktif.
     *
     * @return array{ok: bool, pesan: string, sisa: int|null}
     */
    public function verifikasi(
        int $userId,
        string $kode,
        string $jenis = self::JENIS_LOGIN,
    ): array {
        $jenis = $this->normalJenis($jenis);
        $panjang = max(4, (int) config('keamanan.otp_panjang', 6));
        $maksCoba = max(1, (int) config('keamanan.otp_maks_coba', 5));

        $kode = trim($kode);

        /*
         * Tolak lebih dulu bentuk yang jelas salah (bukan angka / panjang
         * tidak sesuai) TANPA menambah penghitung percobaan. Ini menolong
         * pengguna yang salah ketik panjang, bukan menyerang.
         */
        if ($kode === '' || strlen($kode) !== $panjang || ! ctype_digit($kode)) {
            return ['ok' => false, 'pesan' => "Kode harus {$panjang} angka.", 'sisa' => null];
        }

        $baris = UserOtp::where('user_id', $userId)
            ->where('jenis', $jenis)
            ->where('dipakai', false)
            ->orderByDesc('id')
            ->first();

        if ($baris === null) {
            return ['ok' => false, 'pesan' => 'Tidak ada kode aktif. Minta kode baru.', 'sisa' => null];
        }

        if ($baris->kedaluwarsa !== null && $baris->kedaluwarsa->isPast()) {
            return ['ok' => false, 'pesan' => 'Kode sudah kedaluwarsa. Minta kode baru.', 'sisa' => null];
        }

        if ((int) $baris->percobaan >= $maksCoba) {
            return [
                'ok'    => false,
                'pesan' => 'Terlalu banyak percobaan. Minta kode baru.',
                'sisa'  => null,
            ];
        }

        /*
         * Naikkan penghitung SEBELUM mencocokkan. Bila pemeriksaan hash gagal
         * karena suatu hal, percobaan tetap terhitung, sehingga penyerang
         * tidak bisa "mengulang gratis" saat terjadi kesalahan tak terduga.
         */
        $baris->increment('percobaan');
        $percobaanSekarang = (int) $baris->percobaan;

        if (! Hash::check($kode, (string) $baris->kode_hash)) {
            $sisa = max(0, $maksCoba - $percobaanSekarang);

            return [
                'ok'    => false,
                'pesan' => "Kode tidak sesuai. Sisa percobaan: {$sisa}.",
                'sisa'  => $sisa,
            ];
        }

        /* Cocok: tandai terpakai (sekali pakai). */
        $baris->update(['dipakai' => true]);

        return ['ok' => true, 'pesan' => 'Verifikasi berhasil.', 'sisa' => null];
    }

    private function normalJenis(string $jenis): string
    {
        return in_array($jenis, [self::JENIS_LOGIN, self::JENIS_RESET_SANDI], true)
            ? $jenis
            : self::JENIS_LOGIN;
    }
}
