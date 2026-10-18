<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus permanen SATU akun pengguna beserta jejak sistemnya.
 *
 * Dibutuhkan untuk pembersihan akun ganda sebelum Go-Live (lihat
 * `php artisan pengguna:audit`): beberapa akun tidak terpakai tetapi
 * menyandang nama sama, sehingga pencocokan PIC/anggota kasus yang
 * DIIKETIK BEBAS menjadi ambigu.
 *
 * Prinsip keamanan:
 *   1. Tanpa `--yakin`, perintah ini HANYA melaporkan (read-only).
 *   2. Akun yang masih memiliki DATA bisnis (laporan, kasus, absensi,
 *      tugas, ...) ditolak kecuali `--paksakan`.
 *   3. Kasus di mana akun ini hanyalah PIC TIDAK ikut terhapus:
 *      `penanggung_jawab_id` dikosongkan dan nama ketikan
 *      (`penanggung_jawab_teks`) tetap tersimpan.
 *   4. Seluruhnya dibungkus satu transaksi: bila gagal, tidak ada yang
 *      berubah.
 *   5. Penghapusan dicatat pada `audit_log` (user_id NULL, karena tidak
 *      ada operator pada baris perintah CLI).
 *
 * Tabel yang tidak ada pada suatu lingkungan dilewati, sehingga perintah
 * ini juga aman dijalankan pada skema uji sqlite yang hanya memuat
 * sebagian tabel.
 *
 * Contoh:
 *   php artisan pengguna:hapus 46            // laporan saja
 *   php artisan pengguna:hapus 46 --yakin    // eksekusi
 */
class HapusPengguna extends Command
{
    /**
     * Data bisnis yang dimiliki pengguna: tabel => kolom pemilik.
     * Kehadirannya MENGHALANGI penghapusan tanpa `--paksakan`.
     */
    private const DATA = [
        'absensi'               => 'user_id',
        'ai_jobs'               => 'user_id',
        'backup_riwayat'        => 'dibuat_oleh',
        'impor_profil'          => 'user_id',
        'impor_sejarah'         => 'user_id',
        'jadwal'                => 'user_id',
        'komentar_laporan'      => 'user_id',
        'laporan_harian'        => 'user_id',
        'pengingat'             => 'user_id',
        'riwayat_tindak_lanjut' => 'user_id',
        'tugas'                 => 'user_id',
    ];

    /** Jejak sistem: bukan data bisnis, selalu ikut dihapus. */
    private const JEJAK = [
        'audit_log'        => 'user_id',
        'backup_codes'     => 'user_id',
        'kasus_penugasan'  => 'user_id',
        'kasus_status_log' => 'user_id',
        'login_attempts'   => 'user_id',
        'notifikasi'       => 'user_id',
        'password_history' => 'user_id',
        'sesi_aktif'       => 'user_id',
        'sessions'         => 'user_id',
        'user_otp'         => 'user_id',
        'user_security'    => 'user_id',
        'user_tokens'      => 'user_id',
    ];

    /** Turunan yang barisnya ikut hilang mengikuti induknya. */
    private const TURUNAN_KASUS = ['kasus_pihak', 'kasus_penugasan', 'kasus_status_log'];
    private const TURUNAN_LAPORAN = ['pengiriman_laporan', 'bukti_gambar'];

    protected $signature = 'pengguna:hapus
        {id : ID pengguna yang akan dihapus}
        {--yakin : Eksekusi penghapusan (tanpa opsi ini hanya laporan/read-only)}
        {--paksakan : Izinkan menghapus akun yang masih memiliki data bisnis}';

    protected $description = 'Hapus permanen satu akun pengguna beserta jejak sistemnya (pembersihan akun ganda).';

    public function handle(): int
    {
        $id   = (int) $this->argument('id');
        $user = User::find($id);

        if (!$user instanceof User) {
            $this->error("Pengguna #{$id} tidak ditemukan.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Pengguna #%d  %s  <%s>  %s/%s',
            $user->id,
            (string) $user->nama,
            (string) $user->email,
            (string) $user->role,
            (string) $user->status
        ));
        $this->newLine();

        /* --- Perkiraan dampak (read-only) ---------------------------- */
        $data   = $this->dataMilik($id, $user->email);
        $jejak  = $this->jejakMilik($id);
        $kasusPIC = $this->kasusHanyaPic($id);

        $this->line('Data bisnis milik akun ini:');
        $adaData = false;
        foreach ($data as $label => $jml) {
            if ($jml > 0) {
                $this->line(sprintf('  %-28s %d', $label, $jml));
                $adaData = true;
            }
        }
        if (!$adaData) {
            $this->line('  (tidak ada)');
        }

        if ($kasusPIC > 0) {
            $this->line(sprintf(
                '  %-28s %d  (bukan dihapus: penanggung_jawab_id dikosongkan, nama ketikan tetap)',
                'kasus (hanya sebagai PIC)',
                $kasusPIC
            ));
        }

        $totalJejak = array_sum($jejak) + $this->jumlahTokenApi($id);
        $this->newLine();
        $this->line('Jejak sistem yang ikut dihapus: ' . $totalJejak . ' baris');

        /* --- Penjaga ------------------------------------------------- */
        $totalData = array_sum($data);

        if (!$this->option('yakin')) {
            $this->newLine();
            $this->warn('Mode laporan (read-only). Tidak ada data yang dihapus.');
            $this->line('Tambahkan --yakin untuk mengeksekusi.');

            return self::SUCCESS;
        }

        if ($totalData > 0 && !$this->option('paksakan')) {
            $this->newLine();
            $this->error('Akun ini masih memiliki data bisnis. Hapus/pindahkan datanya dahulu,');
            $this->error('atau ulangi dengan --paksakan bila memang data ikut dihapus.');

            return self::FAILURE;
        }

        if ((string) $user->role === 'admin' && User::where('role', 'admin')->count() === 1) {
            $this->newLine();
            $this->error('Ini adalah akun ADMIN terakhir. Buat admin lain sebelum menghapusnya');

            return self::FAILURE;
        }

        if ((string) $user->role === 'mentor' && User::where('role', 'mentor')->count() === 1) {
            $this->newLine();
            $this->error('Ini adalah akun MENTOR terakhir. Buat mentor lain sebelum menghapusnya');

            return self::FAILURE;
        }

        /* --- Eksekusi ------------------------------------------------ */
        try {
            DB::transaction(function () use ($id, $user, $data, $jejak): void {
                /* 1. Kasus yang DIBUAT akun ini: hapus seluruh kasus + turunannya. */
                $kasusDibuat = $this->idKasus($id, 'dibuat_oleh');
                foreach (self::TURUNAN_KASUS as $t) {
                    if ($this->ada($t, 'kasus_id')) {
                        DB::table($t)->whereIn('kasus_id', $kasusDibuat)->delete();
                    }
                }
                if (Schema::hasTable('kasus') && Schema::hasColumn('kasus', 'id')) {
                    DB::table('kasus')->whereIn('id', $kasusDibuat)->delete();
                }

                /* 2. Kasus di mana akun ini hanya PIC: lepas PIC, teks tetap. */
                if (Schema::hasTable('kasus') && Schema::hasColumn('kasus', 'penanggung_jawab_id')) {
                    DB::table('kasus')->where('penanggung_jawab_id', $id)
                        ->update(['penanggung_jawab_id' => null]);
                }

                /* 3. Turunan laporan & percakapan AI. */
                $laporan = $this->idKolom('laporan_harian', 'user_id', $id);
                foreach (self::TURUNAN_LAPORAN as $t) {
                    if ($this->ada($t, 'laporan_id')) {
                        DB::table($t)->whereIn('laporan_id', $laporan)->delete();
                    }
                }
                $sesi = $this->idKolom('chat_sessions', 'user_id', $id);
                if ($this->ada('chat_messages', 'session_id')) {
                    DB::table('chat_messages')->whereIn('session_id', $sesi)->delete();
                }

                /* 4. Data bisnis milik akun ini. */
                foreach (self::DATA as $tabel => $kolom) {
                    if ($this->ada($tabel, $kolom)) {
                        DB::table($tabel)->where($kolom, $id)->delete();
                    }
                }
                if ($this->ada('chat_sessions', 'user_id')) {
                    DB::table('chat_sessions')->where('user_id', $id)->delete();
                }
                if ($this->ada('laporan_harian', 'user_id')) {
                    DB::table('laporan_harian')->where('user_id', $id)->delete();
                }

                /* 5. Jejak sistem. */
                foreach (self::JEJAK as $tabel => $kolom) {
                    if ($this->ada($tabel, $kolom)) {
                        DB::table($tabel)->where($kolom, $id)->delete();
                    }
                }

                /* 6. Token API Sanctum (morph) & penguncian login per surel. */
                if ($this->ada('personal_access_tokens', 'tokenable_id')) {
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', User::class)
                        ->where('tokenable_id', $id)
                        ->delete();
                }
                if ($this->ada('login_locks', 'kunci')) {
                    DB::table('login_locks')->where('kunci', (string) $user->email)->delete();
                }

                /* 7. Baris pengguna itu sendiri. */
                DB::table('users')->where('id', $id)->delete();

                /* 8. Jejak penghapusan itu sendiri. */
                if ($this->ada('audit_log', 'aksi')) {
                    DB::table('audit_log')->insert([
                        'user_id'    => null,
                        'aksi'       => 'hapus_pengguna',
                        'tabel_ref'  => 'users',
                        'baris_id'   => $id,
                        'ringkasan'  => sprintf(
                            'Menghapus pengguna #%d %s <%s> (%s/%s); %d data bisnis, %d jejak sistem',
                            $id,
                            (string) $user->nama,
                            (string) $user->email,
                            (string) $user->role,
                            (string) $user->status,
                            array_sum($data),
                            array_sum($jejak)
                        ),
                        'ip_address' => '127.0.0.1',
                        'dibuat_pada'=> now(),
                    ]);
                }
            });
        } catch (\Throwable $t) {
            $this->newLine();
            $this->error('Penghapusan GAGAL, tidak ada perubahan yang tersimpan: ' . $t->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Pengguna #%d <%s> beserta %d jejak sistem telah dihapus.',
            $id,
            (string) $user->email,
            array_sum($jejak)
        ));

        return self::SUCCESS;
    }

    /* -----------------------------------------------------------------
     * Pembantu
     * --------------------------------------------------------------- */

    /** Apakah tabel & kolom tersedia pada lingkungan ini? */
    private function ada(string $tabel, string $kolom): bool
    {
        return Schema::hasTable($tabel) && Schema::hasColumn($tabel, $kolom);
    }

    /** Ambil daftar id dari tabel yang mungkin tidak ada pada lingkungan ini. */
    private function idKolom(string $tabel, string $kolom, int $id): Collection
    {
        if (!$this->ada($tabel, $kolom) || !Schema::hasColumn($tabel, 'id')) {
            return collect();
        }

        return DB::table($tabel)->where($kolom, $id)->pluck('id');
    }

    /** Id kasus yang dibuat akun ini. */
    private function idKasus(int $id, string $kolom): Collection
    {
        return $this->idKolom('kasus', $kolom, $id);
    }

    /** Jumlah kasus di mana akun ini sekadar PIC (bukan pembuat). */
    private function kasusHanyaPic(int $id): int
    {
        if (!$this->ada('kasus', 'penanggung_jawab_id') || !$this->ada('kasus', 'dibuat_oleh')) {
            return 0;
        }

        return (int) DB::table('kasus')
            ->where('penanggung_jawab_id', $id)
            ->where(fn ($q) => $q->whereNull('dibuat_oleh')->orWhere('dibuat_oleh', '!=', $id))
            ->count();
    }

    /** Data bisnis milik akun: label => jumlah baris. */
    private function dataMilik(int $id, string $email): array
    {
        $out = [];

        foreach (self::DATA as $tabel => $kolom) {
            if ($this->ada($tabel, $kolom)) {
                $out[$tabel . '.' . $kolom] = (int) DB::table($tabel)->where($kolom, $id)->count();
            }
        }

        if ($this->ada('kasus', 'dibuat_oleh')) {
            $out['kasus.dibuat_oleh'] = (int) DB::table('kasus')->where('dibuat_oleh', $id)->count();
        }
        if ($this->ada('laporan_harian', 'user_id')) {
            $out['laporan_harian.user_id'] = (int) DB::table('laporan_harian')->where('user_id', $id)->count();
        }
        if ($this->ada('chat_sessions', 'user_id')) {
            $out['chat_sessions.user_id'] = (int) DB::table('chat_sessions')->where('user_id', $id)->count();
        }

        return $out;
    }

    /** Jejak sistem milik akun: label => jumlah baris. */
    private function jejakMilik(int $id): array
    {
        $out = [];

        foreach (self::JEJAK as $tabel => $kolom) {
            if ($this->ada($tabel, $kolom)) {
                $out[$tabel . '.' . $kolom] = (int) DB::table($tabel)->where($kolom, $id)->count();
            }
        }

        return $out;
    }

    /** Jumlah token API Sanctum milik akun. */
    private function jumlahTokenApi(int $id): int
    {
        if (!$this->ada('personal_access_tokens', 'tokenable_id')) {
            return 0;
        }

        return (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $id)
            ->count();
    }
}
