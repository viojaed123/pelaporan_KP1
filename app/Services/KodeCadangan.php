<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupCode;
use Illuminate\Support\Facades\Hash;

/**
 * Kode cadangan dua faktor (porting includes/security.php §3 bagian
 * backup_codes_*).
 *
 * Kode cadangan adalah jalan masuk terakhir bila surel tidak bisa diakses.
 * Karena itu daya tahannya lebih tinggi daripada kode OTP biasa: TIDAK
 * kedaluwarsa, tetapi sekali pakai dan hanya berlaku sejumlah terbatas.
 *
 * Yang disimpan hanyalah HASH (bcrypt). Kode mentah ditampilkan SATU KALI
 * saat dibuat, lalu tidak pernah bisa dibaca lagi -- bahkan oleh
 * administrator, karena memang tidak disimpan.
 */
class KodeCadangan
{
    /** Jumlah kode yang dibuat sekali generate. */
    private const JUMLAH = 10;

    /**
     * Buat ulang seluruh kode cadangan pengguna.
     *
     * Kode lama DIHAPUS semua. Tanpa ini, kode yang pernah bocor tetap sah
     * walaupun pengguna sudah membuat set baru.
     *
     * @return list<string> Kode mentah (hanya ditampilkan sekali).
     */
    public function buat(int $userId): array
    {
        BackupCode::where('user_id', $userId)->delete();

        $mentah = [];

        for ($i = 0; $i < self::JUMLAH; $i++) {
            /*
             * Format XXXXXX-XXXX: mudah dibaca dan diketik ulang dari catatan
             * fisik. Dipisah tanda hubung agar tidak salah hitung saat
             * pengguna menyalinnya.
             */
            $kode = strtoupper(
                bin2hex(random_bytes(3)) . '-' . substr(bin2hex(random_bytes(3)), 0, 4)
            );

            BackupCode::create([
                'user_id'   => $userId,
                'kode_hash' => Hash::make($kode),
                'dipakai'   => false,
            ]);

            $mentah[] = $kode;
        }

        return $mentah;
    }

    /**
     * Pakai satu kode cadangan.
     *
     * @return bool  Benar bila kode cocok dan berhasil dipakai.
     */
    public function pakai(int $userId, string $kode): bool
    {
        $kode = strtoupper(trim($kode));

        if ($kode === '') {
            return false;
        }

        $daftar = BackupCode::where('user_id', $userId)
            ->where('dipakai', false)
            ->get();

        foreach ($daftar as $baris) {
            if (Hash::check($kode, (string) $baris->kode_hash)) {
                /*
                 * Tandai terpakai SEBELUM keluar. Eloquent memakai kunci
                 * utama baris ini, sehingga hanya baris yang cocok yang
                 * berubah -- kode lain tetap bisa dipakai.
                 */
                $baris->update([
                    'dipakai'      => true,
                    'dipakai_pada' => now(),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Jumlah kode cadangan yang masih tersisa.
     */
    public function sisa(int $userId): int
    {
        return BackupCode::where('user_id', $userId)
            ->where('dipakai', false)
            ->count();
    }
}
