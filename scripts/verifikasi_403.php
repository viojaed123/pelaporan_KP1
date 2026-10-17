<?php
/**
 * Verifikasi cepat halaman error 403.
 *
 *   php scripts/verifikasi_403.php
 *
 * Memastikan halaman yang dipakai middleware CekPeran benar-benar dapat
 * dirender dan memuat warna korporat PHR (bukan warna bawaan Laravel).
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$html = view('errors.403', ['pesan' => 'Anda tidak memiliki akses ke halaman ini.'])->render();

$cek = [
    'kode 403'          => str_contains($html, '403'),
    'judul Akses Ditolak' => str_contains($html, 'Akses Ditolak'),
    'pesan 403'         => str_contains($html, 'Anda tidak memiliki akses ke halaman ini.'),
    'warna biru tua'    => str_contains($html, '#003D7D'),
    'warna biru'        => str_contains($html, '#005EB8'),
    'warna merah'       => str_contains($html, '#EB1C24'),
    'warna bg'          => str_contains($html, '#F5F5F5'),
];

$gagal = 0;
foreach ($cek as $nama => $lolos) {
    printf("  %-24s %s\n", $nama, $lolos ? 'ADA' : '*** TIDAK ADA ***');
    if (! $lolos) {
        $gagal++;
    }
}

printf("\n  panjang halaman: %d byte\n", strlen($html));

if ($gagal === 0) {
    echo "\nLULUS: halaman 403 lengkap dengan warna korporat PHR.\n";
    exit(0);
}
printf("\nGAGAL: %d pemeriksaan tidak lolos.\n", $gagal);
exit(1);
