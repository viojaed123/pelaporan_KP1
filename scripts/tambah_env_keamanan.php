<?php
/**
 * Menambahkan blok konfigurasi keamanan ke .env bila belum ada.
 *
 *   php scripts/tambah_env_keamanan.php
 *
 * IDEMPOTEN: setiap kunci hanya ditambahkan bila benar-benar belum terdaftar,
 * sehingga boleh dijalankan berulang tanpa menduplikasi atau menimpa nilai.
 * Nilai rahasia yang sudah ada di .env TIDAK PERNAH dibaca maupun dicetak.
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

$envPath = $root . '/.env';
$isi = (string) file_get_contents($envPath);

/* Kunci yang akan ditambahkan, beserta nilai bawaan yang sama dengan
 * includes/config.php lama. */
$tambahan = [
    'SESI_IDLE_DETIK'    => '1800',
    'SESI_ABSOLUT_DETIK' => '28800',
    'SESI_MAKS_AKTIF'    => '3',

    'LOGIN_MAKS_GAGAL'    => '5',
    'LOGIN_JENDELA_DETIK' => '900',
    'LOGIN_KUNCI_DETIK'   => '900',
    'LOGIN_BACKOFF_DETIK' => '300,900,3600',

    'PASSWORD_MIN'     => '8',
    'PASSWORD_RIWAYAT' => '3',

    'OTP_DETIK'     => '300',
    'OTP_MAKS_COBA' => '5',
    'OTP_PANJANG'   => '6',
    'INGAT_HARI'    => '30',

    /* CATATAN: nilai warna WAJIB dikutip. Pada .env, '#' memulai komentar,
     * sehingga WARNA_BIRU_TUA=#003D7D akan terbaca KOSONG. */
    'WARNA_BIRU'     => '"#005EB8"',
    'WARNA_BIRU_TUA' => '"#003D7D"',
    'WARNA_MERAH'    => '"#EB1C24"',
    'WARNA_HIJAU'    => '"#159447"',
    'WARNA_BG'       => '"#F5F5F5"',
];

/**
 * Deteksi apakah sebuah kunci sudah ada (baik sebagai KEY= maupun KEY =).
 */
function sudahAda(string $isi, string $kunci): bool
{
    return (bool) preg_match('/^\s*' . preg_quote($kunci, '/') . '\s*=/m', $isi);
}

$ditambahkan = [];
$terlewat    = [];

foreach ($tambahan as $kunci => $nilai) {
    if (sudahAda($isi, $kunci)) {
        $terlewat[] = $kunci;
        continue;
    }
    $isi .= ($isi !== '' && ! str_ends_with($isi, "\n") ? "\n" : '')
        . $kunci . '=' . $nilai . "\n";
    $ditambahkan[] = $kunci;
}

file_put_contents($envPath, $isi);

/* Verifikasi: semua kunci kini harus terbaca. */
$isiBaru = (string) file_get_contents($envPath);
$gagal = [];
foreach ($tambahan as $kunci => $nilai) {
    if (! sudahAda($isiBaru, $kunci)) {
        $gagal[] = $kunci;
    }
}

printf("Ditambahkan : %d kunci\n", count($ditambahkan));
foreach ($ditambahkan as $k) {
    echo "  + $k\n";
}
if ($terlewat !== []) {
    printf("Sudah ada (dilewati) : %d kunci\n", count($terlewat));
    foreach ($terlewat as $k) {
        echo "  = $k\n";
    }
}

$b = (array) file_get_contents($envPath);
$nonAscii = 0;
foreach (str_split((string) file_get_contents($envPath)) as $ch) {
    if (ord($ch) > 127) {
        $nonAscii++;
    }
}
printf("Byte non-ASCII di .env : %d (wajib 0)\n", $nonAscii);

if ($gagal !== []) {
    printf("\nGAGAL: kunci berikut tidak terbaca setelah penulisan: %s\n", implode(', ', $gagal));
    exit(1);
}

echo "\nSemua kunci keamanan terdaftar di .env.\n";
