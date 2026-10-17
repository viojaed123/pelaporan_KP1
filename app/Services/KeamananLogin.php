<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginAttempt;
use App\Models\LoginLock;
use Illuminate\Support\Carbon;

/**
 * Penguncian login & pencatatan percobaan (porting includes/security.php §1).
 *
 * Sistem lama memakai mysqli langsung dengan `INSERT ... ON DUPLICATE KEY
 * UPDATE gagal = gagal + 1`. Di sini logikanya dipindahkan ke Eloquent namun
 * sifat pentingnya DIPERTAHANKAN:
 *
 *   1. Pencacah dinaikkan secara ATOMIK (lewat query builder increment,
 *      yang menjadi `UPDATE ... SET gagal = gagal + 1`), bukan
 *      baca-lalu-tulis. Baca-lalu-tulis akan kehilangan sebagian hitungan
 *      bila beberapa permintaan gagal datang bersamaan -- persis pada
 *      serangan yang ingin dicegah.
 *
 *   2. Penguncian diterapkan pada DUA kunci sekaligus:
 *        ip:<alamat>    -- menahan satu IP mencoba banyak akun (spraying)
 *        email:<surel>  -- menahan satu akun diserang dari banyak IP
 *      Keduanya diperlukan; masing-masing sendirian punya celah.
 *
 *   3. Ada lapis kedua berbasis RIWAYAT (hitungGagal). Penghitung kunci bisa
 *      "lolos" (mis. penyerang berganti IP tiap percobaan); riwayat percobaan
 *      menangkap pola itu dalam jendela waktu.
 */
class KeamananLogin
{
    /**
     * Periksa apakah IP atau surel sedang terkunci.
     *
     * @return array{terkunci: bool, sisa: int, pesan: string}
     */
    public function cekTerkunci(string $ip, string $email): array
    {
        foreach ($this->daftarKunci($ip, $email) as $kunci) {
            $sisa = $this->sisaKunci($kunci);

            if ($sisa > 0) {
                $menit = (int) ceil($sisa / 60);

                $pesan = $menit >= 60
                    ? 'Terlalu banyak percobaan gagal. Coba lagi dalam '
                        . (int) ceil($menit / 60) . ' jam.'
                    : 'Terlalu banyak percobaan gagal. Coba lagi dalam '
                        . $menit . ' menit.';

                return ['terkunci' => true, 'sisa' => $sisa, 'pesan' => $pesan];
            }
        }

        return ['terkunci' => false, 'sisa' => 0, 'pesan' => ''];
    }

    /**
     * Sisa detik penguncian untuk satu kunci; 0 bila tidak terkunci.
     */
    public function sisaKunci(string $kunci): int
    {
        $baris = LoginLock::where('kunci', $kunci)
            ->whereNotNull('terkunci_sampai')
            ->first();

        if ($baris === null || $baris->terkunci_sampai === null) {
            return 0;
        }

        $sisa = (int) now()->diffInSeconds($baris->terkunci_sampai, false);

        return $sisa > 0 ? $sisa : 0;
    }

    /**
     * Catat satu kegagalan login; kunci kunci bila melewati ambang.
     *
     * @param  int|null  $userId  ID pengguna bila surelnya cocok dengan akun
     *                            yang ada (null bila tidak ada).
     */
    public function catatGagal(string $ip, string $email, ?int $userId = null): void
    {
        $email = $this->normalSurel($email);

        foreach ($this->daftarKunci($ip, $email) as $kunci) {
            try {
                /*
                 * firstOrCreate memastikan baris ada; bila belum, dibuat
                 * dengan gagal = 0. `percobaan_pertama` diisi di sini karena
                 * kolomnya NOT NULL tanpa default yang diandalkan Eloquent.
                 */
                LoginLock::firstOrCreate(
                    ['kunci' => $kunci],
                    ['gagal' => 0, 'percobaan_pertama' => now()]
                );

                /*
                 * Kenaikan ATOMIK. increment() menerjemah ke
                 * `UPDATE login_locks SET gagal = gagal + 1`, sehingga dua
                 * permintaan bersamaan tidak saling menimpa.
                 */
                LoginLock::where('kunci', $kunci)->increment('gagal');

                // Baca nilai terbaru untuk memutuskan penguncian.
                $gagal = (int) LoginLock::where('kunci', $kunci)->value('gagal');

                $maks = (int) config('keamanan.login_maks_gagal');

                if ($gagal >= $maks) {
                    /*
                     * Indeks backoff: kegagalan ke-`maks` memakai durasi
                     * pertama, ke-(maks+1) durasi kedua, dan seterusnya.
                     * min() menjaga agar indeks tidak keluar batas array
                     * (kegagalan lanjutan memakai durasi terakhir/terlama).
                     */
                    $backoff = (array) config('keamanan.login_backoff_detik');
                    if ($backoff === []) {
                        $backoff = [300];
                    }

                    $indeks = min(
                        max(0, $gagal - $maks),
                        count($backoff) - 1
                    );
                    $durasi = (int) $backoff[$indeks];

                    LoginLock::where('kunci', $kunci)->update([
                        'terkunci_sampai' => now()->addSeconds($durasi),
                    ]);
                }
            } catch (\Throwable $t) {
                /* Keamanan tidak boleh menjatuhkan proses login. Kegagalan
                 * pencatatan dicatat ke log, dan proses berlanjut. */
                report($t);
            }
        }

        $this->catatPercobaan($email, $userId, $ip, false, 'gagal');
    }

    /**
     * Bersihkan kunci & catat keberhasilan setelah login sukses.
     */
    public function catatSukses(string $ip, string $email, int $userId): void
    {
        $this->resetKunci($ip, $email);

        $this->catatPercobaan(
            $this->normalSurel($email),
            $userId,
            $ip,
            true,
            'sukses'
        );

        /*
         * Pangkas riwayat lama agar tabel tidak membesar tanpa batas.
         * Dibatasi 500 baris sekali jalan supaya penghapusan tidak pernah
         * mengunci tabel dalam waktu lama pada jam sibuk.
         */
        try {
            LoginAttempt::where('dibuat_pada', '<', now()->subDays(30))
                ->limit(500)
                ->delete();
        } catch (\Throwable $t) {
            report($t);
        }
    }

    /**
     * Buang kunci (Ip & surel). Dipanggil setelah login berhasil.
     */
    public function resetKunci(string $ip, string $email): void
    {
        LoginLock::whereIn('kunci', $this->daftarKunci($ip, $email))->delete();
    }

    /**
     * Jumlah kegagalan dalam jendela waktu, dari RIWAYAT (bukan penghitung).
     *
     * Menangkap pola yang lolos dari penghitung kunci, misalnya penyerang
     * yang berganti IP sehingga kunci 'ip:' selalu baru.
     */
    public function hitungGagal(string $ip, string $email): int
    {
        $email = $this->normalSurel($email);
        $batas = now()->subSeconds((int) config('keamanan.login_jendela_detik'));

        return LoginAttempt::where('berhasil', false)
            ->where('dibuat_pada', '>=', $batas)
            ->where(function ($q) use ($ip, $email) {
                $q->where('ip_address', $ip);

                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
            })
            ->count();
    }

    /* =================================================================
     * BAGIAN DALAM
     * =============================================================== */

    /**
     * Susun daftar kunci yang diawasi untuk sebuah percobaan login.
     *
     * @return list<string>
     */
    private function daftarKunci(string $ip, string $email): array
    {
        $kunci = [LoginLock::kunciIp($ip)];

        $email = $this->normalSurel($email);
        if ($email !== '') {
            $kunci[] = LoginLock::kunciEmail($email);
        }

        return $kunci;
    }

    /**
     * Simpan satu baris riwayat percobaan login.
     */
    private function catatPercobaan(
        string $email,
        ?int $userId,
        string $ip,
        bool $berhasil,
        string $alasan,
    ): void {
        try {
            LoginAttempt::create([
                'email'      => $email !== '' ? $email : null,
                'user_id'    => $userId,
                'ip_address' => $ip,
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
                'berhasil'   => $berhasil,
                'alasan'     => $alasan,
            ]);
        } catch (\Throwable $t) {
            report($t);
        }
    }

    private function normalSurel(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
