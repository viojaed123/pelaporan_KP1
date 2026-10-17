<?php
/**
 * scripts/pengingat_jadwal.php
 * Pengirim pengingat jadwal (agenda) + CLI opsional.
 *
 * Cara pakai:
 *   1) di-include dari halaman lain lalu memanggil
 *      pengingat_jadwal_jalankan($conn, $kering, $maks);
 *   2) dijalankan langsung (Task Scheduler), mis. tiap 5 menit:
 *        php C:\xampp\htdocs\pelaporan\scripts\pengingat_jadwal.php
 *        php ... --kering        (uji tanpa mengirim)
 *        php ... --maks=200      (batas jumlah pengingat per jalan)
 *
 * Idempoten: `jadwal.pengingat_terkirim` menandai jadwal yang sudah
 * diingatkan sehingga tidak dikirim dua kali.
 *
 * Aman di-include: blok CLI hanya aktif bila berkas ini skrip utama.
 */

declare(strict_types=1);

if (!function_exists('pengingat_jadwal_jalankan')) {
    /**
     * Jalankan seluruh pengingat jadwal yang jatuh tempo.
     *
     * @return array{total:int, terkirim:int, aplikasi:int, surel:int, whatsapp:int, rincian:list<array<string,mixed>>}
     */
    function pengingat_jadwal_jalankan(mysqli $conn, bool $kering = false, int $maks = 100): array
    {
        if (!function_exists('jadwal_jalankan_pengingat')) {
            require_once __DIR__ . '/../includes/jadwal.php';
        }
        return jadwal_jalankan_pengingat($conn, $kering, $maks);
    }
}

/* ====================================================================
 * Blok CLI — hanya jalan bila berkas ini adalah skrip utama.
 * ==================================================================== */
if (PHP_SAPI === 'cli'
    && isset($argv[0])
    && realpath($argv[0]) === realpath(__FILE__)
) {
    require_once __DIR__ . '/../includes/bootstrap.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        fwrite(STDERR, "pengingat_jadwal: koneksi database tidak tersedia.\n");
        exit(1);
    }

    $kering = in_array('--kering', $argv, true);
    $maks = 100;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--maks=')) {
            $maks = max(1, (int) substr($arg, 7));
        }
    }

    try {
        $hasil = pengingat_jadwal_jalankan($conn, $kering, $maks);
    } catch (Throwable $t) {
        error_log('pengingat_jadwal: ' . $t->getMessage());
        fwrite(STDERR, 'pengingat_jadwal: gagal - ' . $t->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDOUT, 'Pengingat jadwal' . ($kering ? '  (mode kering)' : '') . PHP_EOL);
    fwrite(STDOUT, '  jatuh tempo : ' . $hasil['total'] . "\n");
    fwrite(STDOUT, '  terkirim    : ' . $hasil['terkirim'] . "\n");
    fwrite(STDOUT, '  aplikasi    : ' . $hasil['aplikasi'] . "\n");
    fwrite(STDOUT, '  surel       : ' . $hasil['surel'] . "\n");
    fwrite(STDOUT, '  whatsapp    : ' . $hasil['whatsapp'] . "\n");
    foreach ($hasil['rincian'] as $r) {
        $judul = (string) ($r['judul'] ?? '');
        fwrite(STDOUT, '    [v] #' . (int) ($r['id'] ?? 0) . ' ' . $judul . "\n");
    }

    error_log('pengingat_jadwal: total=' . $hasil['total']
        . ' terkirim=' . $hasil['terkirim']
        . ($kering ? ' (kering)' : ''));

    exit(0);
}
