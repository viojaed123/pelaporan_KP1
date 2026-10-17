<?php
/**
 * scripts/resolusi_lokasi.php
 * Isi kolom `wilayah_id`, `garis_lintang`, `garis_bujur` pada tabel yang
 * memuat nama wilayah (srm_kegiatan, cid_program), berdasarkan master
 * `wilayah` hasil geokode.
 *
 * Penggunaan:
 *   php scripts/resolusi_lokasi.php
 *   php scripts/resolusi_lokasi.php --tabel=srm
 *   php scripts/resolusi_lokasi.php --laporan    # tampilkan yang belum ketemu
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah (CLI).\n");
}

set_time_limit(0);

require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/wilayah.php';

$opsi = ['tabel' => 'semua', 'laporan' => false];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--tabel=')) {
        $opsi['tabel'] = substr($arg, 8);
    } elseif ($arg === '--laporan') {
        $opsi['laporan'] = true;
    }
}

echo "== Resolusi lokasi SRM/CID ==\n\n";

$daftar = ['srm_kegiatan', 'cid_program'];
if ($opsi['tabel'] === 'srm') {
    $daftar = ['srm_kegiatan'];
} elseif ($opsi['tabel'] === 'cid') {
    $daftar = ['cid_program'];
}

$totalSemua = 0;
$petakanSemua = 0;

foreach ($daftar as $tabel) {
    $hasil = wilayah_resolusi_tabel($conn, $tabel);
    if (!$hasil['ok']) {
        echo "[$tabel] GAGAL: {$hasil['pesan']}\n";
        continue;
    }
    $persen = $hasil['total'] > 0 ? round(100 * $hasil['terpetakan'] / $hasil['total'], 1) : 0;
    printf(
        "[%s] %d baris, terpetakan %d (%.1f%%), tanpa koordinat %d\n",
        $tabel,
        $hasil['total'],
        $hasil['terpetakan'],
        $persen,
        $hasil['tanpa_koordinat']
    );
    $totalSemua += $hasil['total'];
    $petakanSemua += $hasil['terpetakan'];
}

echo "\nTotal: $petakanSemua / $totalSemua baris memiliki koordinat.\n";

/* ------------------- Laporan yang belum ketemu ------------------- */
if ($opsi['laporan']) {
    echo "\n== Wilayah yang belum punya koordinat ==\n";
    $res = $conn->query(
        'SELECT level, nama, kabupaten FROM wilayah
          WHERE garis_lintang IS NULL OR garis_bujur IS NULL
          ORDER BY level, nama'
    );
    $n = 0;
    while ($res && ($r = $res->fetch_assoc())) {
        $n++;
        echo sprintf(" - %-10s %s (%s)\n", $r['level'], $r['nama'], $r['kabupaten'] ?? '-');
    }
    echo "Jumlah: $n\n";
}
