<?php
/**
 * Memperbaiki nilai warna di .env agar dikutip.
 *
 *   php scripts/perbaiki_env_warna.php
 *
 * MASALAH YANG DIPERBAIKI:
 *   Pada berkas .env, karakter '#' memulai KOMENTAR. Baris
 *       WARNA_BIRU_TUA=#003D7D
 *   karenanya terbaca sebagai WARNA_BIRU_TUA= (kosong) diikuti komentar
 *   "#003D7D". Akibatnya semua warna korporat hilang dan Laravel memakai
 *   warna bawaan -- antarmuka kehilangan identitas visual PHR.
 *
 *   Nilai yang mengandung '#' harus DIKUTIP:
 *       WARNA_BIRU_TUA="#003D7D"
 *
 * IDEMPOTEN: baris yang sudah dikutip tidak diubah lagi.
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

$envPath = $root . '/.env';
$isi = (string) file_get_contents($envPath);

/* Warna korporat PHR yang benar. */
$warna = [
    'WARNA_BIRU'     => '#005EB8',
    'WARNA_BIRU_TUA' => '#003D7D',
    'WARNA_MERAH'    => '#EB1C24',
    'WARNA_HIJAU'    => '#159447',
    'WARNA_BG'       => '#F5F5F5',
];

$diubah = [];

foreach ($warna as $kunci => $nilai) {
    /*
     * Ganti baris "KUNCI=<apa saja>" (termasuk yang bernilai kosong karena
     * '#' dianggap komentar) menjadi "KUNCI=\"<nilai>\"". Pola [^\r\n]* hanya
     * menangkap sampai akhir baris, sehingga tidak menyentuh baris lain.
     */
    $pola = '/^\s*' . preg_quote($kunci, '/') . '\s*=.*$/m';
    $ganti = $kunci . '="' . $nilai . '"';

    if (preg_match($pola, $isi)) {
        $baru = preg_replace($pola, $ganti, $isi, 1);
        if ($baru !== null && $baru !== $isi) {
            $isi = $baru;
            $diubah[] = $kunci;
        }
    } else {
        /* Baris belum ada sama sekali: tambahkan. */
        $isi .= (str_ends_with($isi, "\n") ? '' : "\n") . $ganti . "\n";
        $diubah[] = $kunci . ' (ditambahkan)';
    }
}

file_put_contents($envPath, $isi);

/* ------------------------------------------------------------------ */
/* Verifikasi: baca ULANG BERKAS MENTAH (bukan $_ENV).                 */
/* ------------------------------------------------------------------ */
/*
 * CATATAN PENTING -- mengapa tidak memakai phpdotenv di sini:
 *   lib_bootstrap.php di atas SUDAH memuat .env sebelum berkas ini
 *   menulisnya. Dotenv::createImmutable() sengaja TIDAK menimpa variabel
 *   lingkungan yang sudah ada, sehingga memuat ulang akan tetap
 *   mengembalikan nilai LAMA (yang rusak). Karena itu verifikasi di sini
 *   membaca berkas mentah -- satu-satunya sumber kebenaran saat ini.
 */

$isiBaru = (string) file_get_contents($envPath);

echo "=== nilai warna di berkas .env (mentah) ===\n";
$gagal = 0;
foreach ($warna as $kunci => $harap) {
    $pola = '/^\s*' . preg_quote($kunci, '/') . '\s*=\s*"?' . preg_quote($harap, '/') . '"?\s*$/m';
    $cocok = (bool) preg_match($pola, $isiBaru);
    printf(
        "  %-16s harap=%-10s %s\n",
        $kunci,
        $harap,
        $cocok ? 'OK (dikutip)' : '*** BEDA ***'
    );
    if (! $cocok) {
        $gagal++;
    }
}

$nonAscii = 0;
foreach (str_split($isiBaru) as $ch) {
    if (ord($ch) > 127) {
        $nonAscii++;
    }
}
printf("\n  Byte non-ASCII di .env : %d (wajib 0)\n", $nonAscii);
printf("  Baris diubah           : %d\n", count($diubah));
foreach ($diubah as $d) {
    echo "    - $d\n";
}

if ($gagal === 0 && $nonAscii === 0) {
    echo "\nLULUS: semua warna korporat terbaca dengan benar.\n";
    exit(0);
}
printf("\nGAGAL: %d masalah.\n", $gagal + ($nonAscii > 0 ? 1 : 0));
exit(1);
