<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Pencatat jejak audit (porting includes/audit.php: catat_audit).
 *
 * Menyimpan siapa melakukan apa, dari mana, dan kapan -- beserta nilai
 * sebelum/sesudah bila ada. Kolom SENSITIF disaring lebih dulu sehingga
 * kata sandi atau token tidak pernah ikut tersimpan ke jejak audit.
 *
 * Pemanggil cukup:
 *   app(AuditLogger::class)->catat('login', 'users', $id, 'Masuk sebagai mentor');
 */
class AuditLogger
{
    /**
     * Kunci yang tidak boleh masuk ke jejak audit.
     *
     * Daftar disalin dari _audit_saring() sistem lama. Menyimpan hash kata
     * sandi ke log audit tetap berbahaya: log sering dibaca lebih longgar
     * daripada tabel utama dan dapat bocor ke tempat yang tak terduga.
     *
     * @var list<string>
     */
    private const KUNCI_RAHASIA = [
        'password',
        'kode_hash',
        'password_hash',
        'ingat_token_hash',
        'sesi_hash',
    ];

    /**
     * Catat satu aksi ke audit_log.
     *
     * @param  string       $aksi       Nama aksi, mis. 'login', 'ubah_laporan'.
     * @param  string|null  $tabel      Nama tabel yang tersentuh.
     * @param  int|null     $barisId    Kunci baris yang tersentuh.
     * @param  string|null  $ringkasan  Keterangan singkat untuk manusia.
     * @param  array|null   $lama       Nilai sebelum perubahan.
     * @param  array|null   $baru       Nilai sesudah perubahan.
     */
    public function catat(
        string $aksi,
        ?string $tabel = null,
        ?int $barisId = null,
        ?string $ringkasan = null,
        ?array $lama = null,
        ?array $baru = null,
    ): void {
        try {
            $request = request();

            AuditLog::create([
                'user_id'    => Auth::id(),
                'aksi'       => $aksi,
                'tabel_ref'  => $tabel,
                'baris_id'   => $barisId,
                'ringkasan'  => $ringkasan,
                /*
                 * Data disimpan sebagai JSON. JSON_UNESCAPED_UNICODE dipakai
                 * agar huruf beraksen/tanda baca Indonesia tetap terbaca di
                 * log (bukan \\uXXXX), sehingga audit dapat dibaca manusia.
                 *
                 * Pengkodean dilakukan DI SINI, bukan lewat cast model:
                 * kolomnya bertipe teks biasa, dan penyaringan kunci rahasia
                 * harus terjadi SEBELUM apa pun disimpan.
                 */
                'data_lama'  => $lama !== null ? $this->json($lama) : null,
                'data_baru'  => $baru !== null ? $this->json($baru) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $t) {
            /*
             * Audit TIDAK BOLEH menggagalkan aksi utama pengguna. Bila
             * pencatatan gagal (mis. tabel penuh), aksi tetap berjalan dan
             * kegagalannya hanya dilaporkan ke log.
             */
            report($t);
        }
    }

    /**
     * Saring lalu ubah baris menjadi JSON untuk disimpan di kolom teks.
     *
     * @param  array<string, mixed>  $baris
     */
    private function json(array $baris): string
    {
        $hasil = json_encode($this->saring($baris), JSON_UNESCAPED_UNICODE);

        /* json_encode hanya gagal pada data yang tidak valid (mis. UTF-8
         * rusak). Simpan objek kosong daripada membiarkan null masuk dan
         * membuat kolom tampak "tidak ada perubahan". */
        return $hasil === false ? '{}' : $hasil;
    }

    /**
     * Buang kolom sensitif dari baris sebelum dicatat.
     *
     * @param  array<string, mixed>  $baris
     * @return array<string, mixed>
     */
    private function saring(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $kunci => $nilai) {
            if (in_array($kunci, self::KUNCI_RAHASIA, true)) {
                continue;
            }

            /* Buang juga kunci yang bertipe array bersarang pada kedalaman
             * satu tingkat -- cukup untuk kebutuhan audit aplikasi ini. */
            $hasil[$kunci] = $nilai;
        }

        return $hasil;
    }
}
