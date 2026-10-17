<?php
/**
 * Verifikasi koneksi database Laravel + kesesuaian skema.
 *
 *   php scripts/verifikasi_koneksi.php
 *
 * Tidak menampilkan rahasia — hanya nama DB, versi server, daftar tabel,
 * dan selisih terhadap daftar tabel yang diharapkan dari migrasi SQL lama.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$harap = [
    'absensi', 'ai_jobs', 'audit_log', 'backup_codes', 'backup_riwayat',
    'bukti_gambar', 'chat_messages', 'chat_sessions', 'cid_program',
    'geokode_cache', 'impor_profil', 'impor_sejarah', 'jadwal', 'kasus',
    'kasus_penugasan', 'kasus_pihak', 'kasus_status_log', 'komentar_laporan',
    'laporan_harian', 'laporan_terkirim', 'login_attempts', 'login_locks',
    'notifikasi', 'password_history', 'pengingat', 'pengiriman_laporan',
    'pihak_terlibat', 'riwayat_tindak_lanjut', 'sesi_aktif', 'srm_kegiatan',
    'user_otp', 'user_security', 'user_tokens', 'users', 'wilayah',
    'wilayah_alias',
];

printf("Koneksi   : %s\n", config('database.default'));
printf("Database  : %s @ %s (user %s)\n",
    config('database.connections.mysql.database'),
    config('database.connections.mysql.host'),
    config('database.connections.mysql.username'),
);

try {
    $pdo = DB::connection()->getPdo();
    printf("Server    : %s\n", $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    printf("PDO       : versi %s\n", $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION));
} catch (Throwable $e) {
    fwrite(STDERR, "GAGAL terhubung: " . $e->getMessage() . "\n");
    exit(1);
}

$tabel = array_map(
    static fn (object $r): string => (string) array_values((array) $r)[0],
    DB::select('SHOW TABLES')
);
sort($tabel);

printf("Tabel     : %d terdeteksi\n", count($tabel));

$hilang  = array_values(array_diff($harap, $tabel));
$tambahan = array_values(array_diff($tabel, $harap));

printf("Hilang    : %s\n", $hilang === [] ? 'tidak ada' : implode(', ', $hilang));
printf("Tambahan  : %s\n", $tambahan === [] ? 'tidak ada' : implode(', ', $tambahan));

/* Tabel khusus Laravel yang dibuat terpisah (bukan bagian skema lama). */
$laravel = ['cache', 'cache_locks', 'jobs', 'job_batches', 'migrations',
            'password_reset_tokens', 'sessions'];
$siap = array_values(array_intersect($laravel, $tabel));
$belum = array_values(array_diff($laravel, $tabel));
printf("Tabel Laravel siap : %s\n", $siap === [] ? 'tidak ada' : implode(', ', $siap));
printf("Tabel Laravel belum: %s\n", $belum === [] ? 'tidak ada' : implode(', ', $belum));

printf("\nRingkasan: skema lama %s, persiapan Laravel %s\n",
    $hilang === [] ? 'LENGKAP' : 'BELUM LENGKAP',
    $belum === [] ? 'LENGKAP' : 'BELUM LENGKAP (jalankan php artisan migrate)',
);

/* Baris penting: jumlah pengguna & laporan, untuk memastikan data nyata terbaca. */
try {
    printf("Data       : %d pengguna, %d laporan, %d absensi\n",
        (int) DB::table('users')->count(),
        (int) DB::table('laporan_harian')->count(),
        (int) DB::table('absensi')->count(),
    );
} catch (Throwable $e) {
    printf("Data       : gagal dibaca (%s)\n", $e->getMessage());
}

exit($hilang === [] ? 0 : 1);
