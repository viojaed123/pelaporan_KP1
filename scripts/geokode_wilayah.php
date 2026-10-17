<?php
/**
 * scripts/geokode_wilayah.php
 * Ambil koordinat wilayah (kabupaten / kecamatan / desa) dari OpenStreetMap
 * (Nominatim) dan simpan ke tabel `wilayah`.
 *
 * Penggunaan (CLI):
 *   php scripts/geokode_wilayah.php                # kab + kec + desa
 *   php scripts/geokode_wilayah.php --level=kecamatan
 *   php scripts/geokode_wilayah.php --paksa        # timpa koordinat lama
 *   php scripts/geokode_wilayah.php --maks=20      # batasi jumlah permintaan
 *
 * Catatan: Nominatim membatasi 1 permintaan/detik -> skrip memberi jeda.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah (CLI).\n");
}

set_time_limit(0);

require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/wilayah.php';

/* ------------------------- Argumen ------------------------- */
$opsi = ['level' => 'semua', 'paksa' => false, 'maks' => 0];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--level=')) {
        $opsi['level'] = substr($arg, 8);
    } elseif ($arg === '--paksa') {
        $opsi['paksa'] = true;
    } elseif (str_starts_with($arg, '--maks=')) {
        $opsi['maks'] = max(0, (int) substr($arg, 7));
    }
}

echo "== Geokode wilayah (Nominatim) ==\n";
echo "Level   : {$opsi['level']}\n";
echo "Paksa   : " . ($opsi['paksa'] ? 'ya' : 'tidak') . "\n\n";

/* ------------------------- Kumpulkan target ------------------------- */
/** @var array<string, array{level:string,nama:string,kab:string,kec:string,norm:string}> $target */
$target = [];

$tambah = static function (string $level, ?string $nama, ?string $kab, ?string $kec) use (&$target): void {
    $nama = trim((string) $nama);
    if ($nama === '' || strcasecmp($nama, '(tanpa)') === 0) {
        return;
    }
    $norm = wilayah_norm($nama);
    if ($norm === '') {
        return;
    }
    $kunci = $level . '|' . $norm . '|' . wilayah_norm((string) $kab);
    if (isset($target[$kunci])) {
        return;
    }
    $target[$kunci] = [
        'level' => $level,
        'nama'  => wilayah_bersih_nama($nama),
        'kab'   => wilayah_bersih_nama((string) $kab),
        'kec'   => wilayah_bersih_nama((string) $kec),
        'norm'  => $norm,
    ];
};

// SRM
$res = $conn->query('SELECT DISTINCT kabupaten_kota, kecamatan, kelurahan FROM srm_kegiatan');
while ($res && ($r = $res->fetch_assoc())) {
    $tambah('kabupaten', $r['kabupaten_kota'], $r['kabupaten_kota'], null);
    $tambah('kecamatan', $r['kecamatan'], $r['kabupaten_kota'], null);
    $tambah('desa', $r['kelurahan'], $r['kabupaten_kota'], $r['kecamatan']);
}
// CID
$res = $conn->query('SELECT DISTINCT kabupaten_kota, kecamatan, desa FROM cid_program');
while ($res && ($r = $res->fetch_assoc())) {
    $tambah('kabupaten', $r['kabupaten_kota'], $r['kabupaten_kota'], null);
    $tambah('kecamatan', $r['kecamatan'], $r['kabupaten_kota'], null);
    $tambah('desa', $r['desa'], $r['kabupaten_kota'], $r['kecamatan']);
}

/* ------------------------- Saring ------------------------- */
if ($opsi['level'] !== 'semua') {
    $target = array_filter($target, static fn ($t) => $t['level'] === $opsi['level']);
}

// Buang yang sudah punya koordinat (kecuali --paksa)
if (!$opsi['paksa']) {
    $target = array_filter($target, static function ($t) use ($conn): bool {
        $row = wilayah_baris($conn, $t['level'], $t['norm'], $t['kab'] !== '' ? $t['kab'] : null);
        return $row === null || !wilayah_koordinat_valid($row['garis_lintang'], $row['garis_bujur']);
    });
}

$target = array_values($target);
if ($opsi['maks'] > 0) {
    $target = array_slice($target, 0, $opsi['maks']);
}

echo 'Jumlah target: ' . count($target) . " (perkiraan " . ceil(count($target) * 1.15) . " detik)\n\n";

/* ------------------------- Proses ------------------------- */
$masuk = 0;
$gagal = 0;
$nomor = 0;

foreach ($target as $t) {
    $nomor++;
    // Susun kueri pencarian
    $bagian = [$t['nama']];
    if ($t['level'] === 'desa' && $t['kec'] !== '') {
        $bagian[] = $t['kec'];
    }
    if ($t['level'] !== 'kabupaten' && $t['kab'] !== '') {
        $bagian[] = $t['kab'];
    }
    $bagian[] = 'Riau';
    $bagian[] = 'Indonesia';
    $q = implode(', ', array_values(array_unique(array_filter($bagian))));

    $hasil = wilayah_geokode($q);

    if ($hasil === null) {
        $gagal++;
        echo sprintf("[%d/%d] GAGAL  %-10s %s\n", $nomor, count($target), $t['level'], $q);
        // Simpan baris kosong supaya terdaftar (koordinat null)
        wilayah_simpan(
            $conn,
            $t['level'],
            $t['nama'],
            $t['kab'],
            $t['kec'],
            null,
            null,
            'nominatim',
            'rendah'
        );
        sleep(1);
        continue;
    }

    $akurasi = in_array($hasil['tipe'], ['administrative', 'city', 'town', 'village', 'county', 'municipality'], true)
        ? 'tinggi' : 'sedang';

    wilayah_simpan(
        $conn,
        $t['level'],
        $t['nama'],
        $t['kab'],
        $t['kec'],
        $hasil['lat'],
        $hasil['lon'],
        'nominatim',
        $akurasi
    );
    $masuk++;
    echo sprintf(
        "[%d/%d] OK     %-10s %-45s -> %.5f, %.5f\n",
        $nomor,
        count($target),
        $t['level'],
        $q,
        $hasil['lat'],
        $hasil['lon']
    );
    sleep(1); // Hormati batas Nominatim (1 permintaan/detik)
}

/* ------------------------- Terapkan alias bawaan ------------------------- */
$alias = wilayah_alias_terapkan_bawaan($conn);

echo "\n== Selesai ==\n";
echo "Berhasil simpan koordinat : $masuk\n";
echo "Gagal geokode             : $gagal\n";
echo "Alias bawaan diterapkan   : $alias\n";
