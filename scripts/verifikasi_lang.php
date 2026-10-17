<?php
/**
 * Verifikasi kelengkapan terjemahan lang/id terhadap lang/en bawaan framework.
 *
 *   php scripts/verifikasi_lang.php
 *
 * Memeriksa dua hal yang sering luput:
 *   1. Setiap kunci pada berkas en harus ada di berkas id. Kunci yang hilang
 *      membuat Laravel diam-diam jatuh ke bahasa fallback (en), sehingga
 *      pengguna melihat pesan campur bahasa tanpa sebab yang jelas.
 *   2. Placeholder (:attribute, :min, :seconds, ...) harus identik antara
 *      en dan id. Placeholder yang terhapus atau salah eja membuat pesan
 *      tampil tanpa nilai penggantinya.
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

/* Bootstrap kerangka Laravel penuh: diperlukan agar app('translator'),
 * config(), dan fasad lain tersedia pada bagian "pemakaian nyata". */
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$enDir = $root . '/vendor/laravel/framework/src/Illuminate/Translation/lang/en';
$idDir = $root . '/lang/id';

$berkas = ['auth.php', 'pagination.php', 'passwords.php', 'validation.php'];

/**
 * Ambil semua kunci bersarang dari array terjemahan, dalam bentuk
 * "atasan" dan "atasan.bawah" (mis. "between.array").
 */
function kunciBersarang(array $arr, string $prefix = ''): array
{
    $hasil = [];
    foreach ($arr as $k => $v) {
        $kunci = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $hasil += kunciBersarang($v, $kunci);
        } else {
            $hasil[$kunci] = (string) $v;
        }
    }
    return $hasil;
}

/**
 * Kumpulkan placeholder :nama dari sebuah pesan.
 */
function placeholder(string $pesan): array
{
    preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $pesan, $m);
    $hasil = $m[1];
    sort($hasil);
    return $hasil;
}

$totalKunci = 0;
$totalMasalah = 0;

foreach ($berkas as $b) {
    $pathEn = $enDir . '/' . $b;
    $pathId = $idDir . '/' . $b;

    printf("\n=== %s ===\n", $b);

    if (! is_file($pathId)) {
        echo "  *** GAGAL: berkas id tidak ada di $pathId\n";
        $totalMasalah++;
        continue;
    }

    /** @var array $arrEn */
    $arrEn = include $pathEn;
    /** @var array $arrId */
    $arrId = include $pathId;

    $en = kunciBersarang($arrEn);
    $id = kunciBersarang($arrId);

    /* Kunci "custom.*" pada berkas en hanyalah CONTOH dokumentasi
     * ('attribute-name' => ['rule-name' => 'custom-message']), bukan
     * kunci terjemahan sungguhan. Membandingkannya akan selalu melaporkan
     * "kunci hilang" palsu, sehingga disaring di sini. */
    $en = array_filter(
        $en,
        fn (string $k): bool => ! str_starts_with($k, 'custom.'),
        ARRAY_FILTER_USE_KEY
    );
    $id = array_filter(
        $id,
        fn (string $k): bool => ! str_starts_with($k, 'custom.'),
        ARRAY_FILTER_USE_KEY
    );

    $hilang = array_diff_key($en, $id);
    $lebih  = array_diff_key($id, $en);

    printf("  kunci en: %d   kunci id: %d\n", count($en), count($id));

    if ($hilang !== []) {
        echo "  *** KUNCI HILANG DI id (Laravel akan memakai en):\n";
        foreach (array_keys($hilang) as $k) {
            echo "        - $k\n";
        }
        $totalMasalah += count($hilang);
    }

    if ($lebih !== []) {
        echo "  Catatan: kunci tambahan di id (tidak berbahaya):\n";
        foreach (array_keys($lebih) as $k) {
            echo "        + $k\n";
        }
    }

    /* Periksa kesesuaian placeholder pada kunci yang sama di kedua bahasa. */
    $phMasalah = 0;
    foreach (array_intersect_key($en, $id) as $kunci => $pesanEn) {
        $pesanId = $id[$kunci];
        $a = placeholder($pesanEn);
        $b2 = placeholder($pesanId);
        if ($a !== $b2) {
            printf(
                "  *** PLACEHOLDER BEDA pada '%s':\n        en: [%s]\n        id: [%s]\n",
                $kunci,
                implode(', ', $a),
                implode(', ', $b2)
            );
            $phMasalah++;
        }
    }

    if ($phMasalah === 0 && $hilang === []) {
        echo "  LULUS: semua kunci ada dan placeholder cocok\n";
    }
    $totalMasalah += $phMasalah;
    $totalKunci += count($id);
}

/* ------------------------------------------------------------------ */
/* 2. Buktikan framework benar-benar memakai bahasa Indonesia          */
/* ------------------------------------------------------------------ */
echo "\n=== Pemakaian nyata oleh framework ===\n";

try {
    $translator = app('translator');
    printf("  config('app.locale')      : %s\n", config('app.locale'));
    printf("  config('app.faker_locale') : %s\n", config('app.faker_locale'));
    printf("  translator locale aktif    : %s\n", $translator->getLocale());

    $uji = [
        'validation.required'  => $translator->get('validation.required', ['attribute' => 'Surel']),
        'validation.unique'     => $translator->get('validation.unique', ['attribute' => 'Surel']),
        'auth.failed'           => $translator->get('auth.failed'),
        'pagination.next'       => $translator->get('pagination.next'),
        'passwords.reset'       => $translator->get('passwords.reset'),
    ];

    foreach ($uji as $kunci => $nilai) {
        $berbahasaInggris = (bool) preg_match(
            '/\b(The|must|field|required|invalid|These|Next|Your|has been)\b/',
            $nilai
        );
        printf(
            "  %-22s %-58s %s\n",
            $kunci,
            '"' . $nilai . '"',
            $berbahasaInggris ? '*** MASIH EN ***' : 'Indonesia OK'
        );
        if ($berbahasaInggris) {
            $totalMasalah++;
        }
    }
} catch (Throwable $e) {
    echo "  *** GAGAL menguji translator: " . $e->getMessage() . "\n";
    $totalMasalah++;
}

/* ------------------------------------------------------------------ */
echo "\n========================================\n";
if ($totalMasalah === 0) {
    printf("SEMUA LULUS: %d kunci terjemahan, 0 masalah\n", $totalKunci);
    exit(0);
}
printf("GAGAL: %d masalah\n", $totalMasalah);
exit(1);
