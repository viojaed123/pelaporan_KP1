<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SesiAktif;
use Illuminate\Support\Facades\DB;

/**
 * Pelacakan sesi perangkat (porting includes/security.php §4: sesi_daftar).
 *
 * Setiap login mencatat satu baris di `sesi_aktif` yang mewakili satu
 * perangkat/peramban. Bila jumlah sesi aktif melebihi batas
 * (SESI_MAKS_AKTIF, bawaan 3), sesi TERLAMA dicabut sehingga pengguna yang
 * kehilangan perangkat tidak meninggalkan sesi hidup tanpa batas.
 *
 * `sesi_hash` = sha256(id sesi). Nilai mentah id sesi TIDAK pernah disimpan,
 * sehingga isi tabel tidak bisa dipakai untuk membajak sesi walau bocor.
 */
class PendaftaranSesi
{
    /**
     * Catat sesi perangkat sekarang dan cabut sesi lama bila melebihi batas.
     */
    public function daftar(int $userId, string $ip, ?string $userAgent): void
    {
        $idSesi = session()->getId();

        /*
         * Bila id sesi tidak tersedia (mis. dipanggil di luar konteks web),
         * pakai nilai acak supaya baris tetap unik dan tidak bertabrakan.
         */
        $hash = $idSesi !== ''
            ? hash('sha256', $idSesi)
            : bin2hex(random_bytes(32));

        try {
            /*
             * updateOrCreate meniru `INSERT ... ON DUPLICATE KEY UPDATE`:
             * login berulang dari perangkat yang sama menghidupkan kembali
             * sesi yang pernah dicabut, bukan membuat baris ganda.
             */
            SesiAktif::updateOrCreate(
                ['sesi_hash' => $hash],
                [
                    'user_id'        => $userId,
                    'ip_address'     => $ip,
                    'user_agent'     => mb_substr((string) $userAgent, 0, 255),
                    'dicabut'        => false,
                    'terakhir_aktif' => now(),
                ]
            );

            /*
             * Cabut sesi lain milik pengguna yang sama, sisakan sejumlah
             * SESI_MAKS_AKTIF - 1 selain sesi yang baru dicatat.
             *
             * Urut menaik berdasarkan terakhir_aktif: yang paling lama tidak
             * dipakai dicabut lebih dulu. Menggunakan subquery-id agar LIMIT
             * pada UPDATE aman di semua versi MySQL (tanpa ORDER BY ... LIMIT
             * langsung di UPDATE yang bergantung sql_safe_updates).
             */
            $maks = max(1, (int) config('keamanan.sesi_maks_aktif'));
            $sisakan = max(0, $maks - 1);

            if ($sisakan > 0) {
                $idDicabut = SesiAktif::where('user_id', $userId)
                    ->where('dicabut', false)
                    ->where('sesi_hash', '<>', $hash)
                    ->orderBy('terakhir_aktif')
                    ->limit($sisakan)
                    ->pluck('id');

                if ($idDicabut->isNotEmpty()) {
                    SesiAktif::whereIn('id', $idDicabut)->update(['dicabut' => true]);
                }
            }
        } catch (\Throwable $t) {
            /* Pelacakan sesi bersifat tambahan; kegagalannya tidak boleh
             * menggagalkan login. Cukup dicatat. */
            report($t);
        }
    }

    /**
     * Pastikan baris `user_security` ada untuk pengguna.
     *
     * Padanan `INSERT IGNORE INTO user_security (user_id) VALUES (?)`.
     * `user_id` adalah kunci utama tabel itu dan (seperti semua PK) sengaja
     * tidak ada di $fillable, sehingga insert memakai query builder langsung.
     */
    public function pastikanKeamananPengguna(int $userId): void
    {
        try {
            DB::table('user_security')->insertOrIgnore(['user_id' => $userId]);
        } catch (\Throwable $t) {
            report($t);
        }
    }
}
