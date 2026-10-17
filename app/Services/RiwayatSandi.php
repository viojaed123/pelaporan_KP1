<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PasswordHistory;
use Illuminate\Support\Facades\Hash;

/**
 * Riwayat kata sandi (porting includes/security.php §2 bagian riwayat).
 *
 * Mencegah pengguna langsung kembali ke kata sandi lama setelah menggantinya.
 * Yang disimpan adalah HASH, bukan sandi mentah, dan hanya sejumlah terakhir
 * (PASSWORD_RIWAYAT, bawaan 3) yang disimpan agar tabel tidak membengkak.
 */
class RiwayatSandi
{
    /**
     * Periksa apakah sandi baru pernah dipakai sebelumnya.
     *
     * @return string|null  Pesan kesalahan, atau null bila belum pernah.
     */
    public function terpakaiSebelumnya(int $userId, string $sandiBaru): ?string
    {
        $riwayat = (int) config('keamanan.password_riwayat', 3);

        if ($riwayat <= 0) {
            return null;
        }

        $daftar = PasswordHistory::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($riwayat)
            ->pluck('password_hash');

        foreach ($daftar as $hash) {
            if (is_string($hash) && $hash !== '' && Hash::check($sandiBaru, $hash)) {
                return "Kata sandi tidak boleh sama dengan {$riwayat} kata sandi terakhir Anda.";
            }
        }

        return null;
    }

    /**
     * Simpan hash sandi ke riwayat, lalu pangkas agar hanya tersisa sejumlah
     * terakhir sesuai konfigurasi.
     *
     * @param  string  $hash  Hash kata sandi (bukan sandi mentah).
     */
    public function simpan(int $userId, string $hash): void
    {
        try {
            PasswordHistory::create([
                'user_id'       => $userId,
                'password_hash' => $hash,
            ]);

            $riwayat = (int) config('keamanan.password_riwayat', 3);

            if ($riwayat <= 0) {
                return;
            }

            /*
             * Ambil id yang DIPERTAHANKAN (sejumlah `riwayat` terbaru),
             * lalu hapus sisanya. Cara ini menghindari subquery bersarang
             * pada DELETE yang tidak didukung sebagian versi basis data.
             */
            $idPertahankan = PasswordHistory::where('user_id', $userId)
                ->orderByDesc('id')
                ->limit($riwayat)
                ->pluck('id');

            if ($idPertahankan->isNotEmpty()) {
                PasswordHistory::where('user_id', $userId)
                    ->whereNotIn('id', $idPertahankan)
                    ->delete();
            }
        } catch (\Throwable $t) {
            /* Riwayat bersifat tambahan; kegagalannya tidak boleh
             * menggagalkan penggantian sandi. */
            report($t);
        }
    }
}
