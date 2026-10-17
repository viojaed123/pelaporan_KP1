<?php
/**
 * Survei skema database untuk memandu pembuatan model Eloquent.
 *
 *   php scripts/survei_skema.php            # ringkas: tabel + jumlah baris
 *   php scripts/survei_skema.php --detail   # + kolom, tipe, index tiap tabel
 *   php scripts/survei_skema.php --detail --tabel=pengguna
 *
 * Keluaran ini yang dipakai untuk memutuskan nama model, kunci asing,
 * dan konvensi timestamp (created_at vs dibuat_pada vs diperbarui_pada).
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

$detail  = in_array('--detail', $argv, true);
$tabelPilihan = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--tabel=')) {
        $tabelPilihan = substr($a, 8);
    }
}

/* ------------------------------------------------------------------ */
/* Ambil daftar tabel + view                                           */
/* ------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT TABLE_NAME, TABLE_TYPE, TABLE_COMMENT, TABLE_ROWS
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = :db
      ORDER BY TABLE_TYPE, TABLE_NAME"
);
$stmt->execute([':db' => $env['DB_NAME']]);
$tabel = $stmt->fetchAll();

$base  = array_filter($tabel, fn ($r) => $r['TABLE_TYPE'] === 'BASE TABLE');
$view  = array_filter($tabel, fn ($r) => $r['TABLE_TYPE'] === 'VIEW');

printf("Database : %s @ %s:%s\n", $env['DB_NAME'], $env['DB_HOST'], $env['DB_PORT'] ?? '3306');
printf("Tabel    : %d   View: %d\n\n", count($base), count($view));

/* ------------------------------------------------------------------ */
/* 1. Tabel: kolom timestamp mencurigakan                              */
/* ------------------------------------------------------------------ */
echo "=== A. KONVENSI TIMESTAMP (penting untuk \$fillable/\$dates) ===\n";

$stmt = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = :db
      ORDER BY TABLE_NAME, ORDINAL_POSITION"
);
$stmt->execute([':db' => $env['DB_NAME']]);
$kolom = $stmt->fetchAll();

$perTabel = [];
foreach ($kolom as $k) {
    $perTabel[$k['TABLE_NAME']][] = $k;
}

$konvensi = [];
foreach ($perTabel as $nama => $ks) {
    $namaKolom = array_map(fn ($x) => $x['COLUMN_NAME'], $ks);

    // Deteksi kolom waktu
    $waktu = array_values(array_filter($namaKolom, function ($c) {
        $c = strtolower($c);
        return str_contains($c, 'tanggal')
            || str_contains($c, 'waktu')
            || str_contains($c, '_pada')
            || str_contains($c, 'created')
            || str_contains($c, 'updated')
            || str_contains($c, 'deleted')
            || str_contains($c, 'date')
            || str_contains($c, 'time');
    }));

    // Deteksi kunci asing (kolom berakhiran _id atau id_* yang bukan PK)
    $pk = array_values(array_filter($namaKolom, fn ($c) => $c === 'id'));
    $fk = array_values(array_filter($namaKolom, function ($c) {
        return str_ends_with($c, '_id') && $c !== 'id';
    }));

    $konvensi[$nama] = [
        'waktu' => $waktu,
        'fk'    => $fk,
        'pk'    => $pk,
        'jumlah_kolom' => count($ks),
    ];
}

foreach ($konvensi as $nama => $info) {
    if ($tabelPilihan !== null && $nama !== $tabelPilihan) {
        continue;
    }
    printf(
        "\n%-38s (%d kolom)%s\n",
        $nama,
        $info['jumlah_kolom'],
        $info['pk'] === [] ? '  [TANPA kolom id]' : ''
    );
    if ($info['waktu'] !== []) {
        echo '    waktu   : ' . implode(', ', $info['waktu']) . "\n";
    }
    if ($info['fk'] !== []) {
        echo '    FK-asli : ' . implode(', ', $info['fk']) . "\n";
    }
}

/* ------------------------------------------------------------------ */
/* 2. Detail kolom (opsional)                                          */
/* ------------------------------------------------------------------ */
if ($detail) {
    echo "\n\n=== B. DETAIL KOLOM ===\n";
    foreach ($perTabel as $nama => $ks) {
        if ($tabelPilihan !== null && $nama !== $tabelPilihan) {
            continue;
        }
        printf("\n--- %s ---\n", $nama);
        foreach ($ks as $k) {
            $tag = [];
            if ($k['COLUMN_KEY'] !== '') {
                $tag[] = $k['COLUMN_KEY'];
            }
            if ($k['EXTRA'] !== '') {
                $tag[] = $k['EXTRA'];
            }
            if ($k['IS_NULLABLE'] === 'NO') {
                $tag[] = 'NOT NULL';
            } else {
                $tag[] = 'null';
            }
            if ($k['COLUMN_DEFAULT'] !== null) {
                $tag[] = 'def=' . var_export($k['COLUMN_DEFAULT'], true);
            }
            printf(
                "  %-30s %-20s %s\n",
                $k['COLUMN_NAME'],
                $k['DATA_TYPE'],
                implode(' | ', $tag)
            );
        }
    }
}

/* ------------------------------------------------------------------ */
/* 3. View (WAJIB tidak diubah)                                        */
/* ------------------------------------------------------------------ */
if ($view !== []) {
    echo "\n\n=== C. VIEW (dipakai Power BI -- JANGAN DIUBAH) ===\n";
    foreach ($view as $v) {
        printf("  %s\n", $v['TABLE_NAME']);
    }
}

echo "\nSelesai.\n";
