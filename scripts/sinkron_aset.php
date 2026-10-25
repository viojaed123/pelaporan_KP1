<?php
/**
 * scripts/sinkron_aset.php — jaga agar aset bersama (CSS/JS) tidak menyimpang.
 *
 * Repositori ini punya DUA pohon aset dengan DocumentRoot berbeda:
 *   - assets/        dipakai halaman legacy (root)
 *   - public/assets/ dipakai blade + SPA React (public/)
 *
 * Prinsip penyelarasan:
 *   1. Berkas JS/vendor/gambar bersama harus IDENTIK di kedua pohon —
 *      kanoniknya `assets/`, disalin ke `public/`.
 *   2. `app.css` BOLEH berbeda: `public/` adalah SUPERSET (menambah pemetaan
 *      kelas khusus SPA). Karena itu pemeriksaan CSS bukan byte-equality,
 *      melainkan memastikan setiap selektor bersama yang ada di `assets/`
 *      juga ada di `public/` — agar halaman legacy tidak pernah kehilangan
 *      gaya yang sudah ditambahkan, sementara tambahan SPA tetap aman.
 *
 * Pakai:
 *   php scripts/sinkron_aset.php            # periksa & perbaiki
 *   php scripts/sinkron_aset.php --periksa  # hanya laporkan (exit 1 bila ada selisih)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan lewat baris perintah (CLI).');
}

$akar = dirname(__DIR__);
$periksaSaja = in_array('--periksa', $argv, true);

/** Berkas yang wajib identik: kanonik => daftar tujuan. */
$petaIdentik = [
    'assets/js/grafik.js'               => ['public/assets/js/grafik.js'],
    'assets/js/grafik-render.js'        => ['public/assets/js/grafik-render.js'],
    'assets/js/toggle.js'               => ['public/assets/js/toggle.js'],
    'assets/js/vendor/chart.umd.min.js' => ['public/assets/vendor/chart.umd.min.js'],
    'assets/img/favicon.png'            => ['public/assets/img/favicon.png'],
];

echo "== Berkas yang wajib identik ==\n";
$beda = 0; $disalin = 0;
foreach ($petaIdentik as $kanonik => $tujuanDaftar) {
    $sumber = $akar . '/' . $kanonik;
    if (!is_file($sumber)) {
        fwrite(STDERR, "  ! kanonik hilang: $kanonik\n");
        $beda++;
        continue;
    }
    $hashSumber = md5_file($sumber);
    foreach ($tujuanDaftar as $tujuan) {
        $target = $akar . '/' . $tujuan;
        if (is_file($target) && md5_file($target) === $hashSumber) {
            echo "  = $tujuan\n";
            continue;
        }
        $beda++;
        if ($periksaSaja) {
            echo "  X $tujuan BEDA dari $kanonik\n";
            continue;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, "  ! gagal membuat direktori: $dir\n");
            continue;
        }
        if (@copy($sumber, $target)) {
            echo "  > $tujuan disalin dari $kanonik\n";
            $disalin++;
        } else {
            fwrite(STDERR, "  ! gagal menyalin ke $tujuan\n");
        }
    }
}

/* ------------------------------------------------------------------
 * CSS: public/ wajib memuat semua selektor milik assets/ (superset).
 * ------------------------------------------------------------------ */
echo "\n== app.css: selektor bersama ==\n";

/** Ambil semua nama selektor dari teks CSS (abaikan isi @media/@keyframes). */
function selektorCss(string $teks): array
{
    // Buang komentar agar tidak salah tangkap.
    $teks = preg_replace('~/\*.*?\*/~s', '', $teks) ?? $teks;
    $hasil = [];
    // Ambil teks sebelum setiap '{' yang bukan bagian @media/@keyframes/@supports.
    if (preg_match_all('/([^{}]+)\{/', $teks, $m)) {
        foreach ($m[1] as $blok) {
            $blok = trim($blok);
            if ($blok === '' || $blok[0] === '@') { continue; }
            // Pisah selektor gabungan dengan koma.
            foreach (explode(',', $blok) as $sel) {
                $sel = trim(preg_replace('~\s+~', ' ', $sel) ?? $sel);
                if ($sel !== '') { $hasil[$sel] = true; }
            }
        }
    }
    return $hasil;
}

$cssKanonik = (string) file_get_contents($akar . '/assets/css/app.css');
$cssPublic  = (string) file_get_contents($akar . '/public/assets/css/app.css');
$selKanonik = selektorCss($cssKanonik);
$selPublic  = selektorCss($cssPublic);

$hilang = array_keys(array_diff_key($selKanonik, $selPublic));
sort($hilang);

if ($hilang === []) {
    echo "  = semua " . count($selKanonik) . " selektor assets/ ada di public/\n";
} else {
    echo "  X " . count($hilang) . " selektor hilang di public/:\n";
    foreach (array_slice($hilang, 0, 40) as $s) { echo "      - $s\n"; }
    if (count($hilang) > 40) { echo "      ... dan " . (count($hilang) - 40) . " lagi\n"; }
    $beda += count($hilang);
}

/* ------------------------------------------------------------------ */
echo "\n";
if ($periksaSaja) {
    if ($beda > 0) {
        echo "Ditemukan $beda selisih. Jalankan tanpa --periksa untuk memperbaiki yang otomatis.\n";
        exit(1);
    }
    echo "Aset bersama sudah selaras.\n";
    exit(0);
}

echo "Selesai: $disalin berkas disalin, $beda selisih terdeteksi.\n";
if ($hilang !== []) {
    echo "Catatan: selektor CSS yang hilang harus ditambahkan manual ke public/assets/css/app.css.\n";
    exit(1);
}
exit(0);
