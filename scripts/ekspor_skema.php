<?php
/**
 * Ekspor metadata skema ke JSON, untuk dipakai generator model Eloquent.
 *
 *   php scripts/survei_skema.php --json > database/schema/skema.json
 *
 * Keluaran: daftar tabel dasar (BUKAN view) lengkap dengan kolom, tipe,
 * kunci utama, kunci asing, dan indeks. View sengaja dikecualikan karena
 * dipakai Power BI dan tidak boleh dimodelkan sebagai tabel tulis.
 */

declare(strict_types=1);

require __DIR__ . '/lib_bootstrap.php';

/* ------------------------------------------------------------------ */
/* 1. Tabel dasar saja                                                 */
/* ------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT TABLE_NAME, TABLE_COMMENT
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
      ORDER BY TABLE_NAME"
);
$stmt->execute([':db' => $env['DB_NAME']]);
$tabelDasar = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

/* ------------------------------------------------------------------ */
/* 2. Kolom                                                            */
/* ------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, DATA_TYPE,
            IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, CHARACTER_MAXIMUM_LENGTH
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = :db
      ORDER BY TABLE_NAME, ORDINAL_POSITION"
);
$stmt->execute([':db' => $env['DB_NAME']]);
$kolomPerTabel = [];
foreach ($stmt->fetchAll() as $k) {
    $kolomPerTabel[$k['TABLE_NAME']][] = $k;
}

/* ------------------------------------------------------------------ */
/* 3. Kunci utama                                                      */
/* ------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = :db AND CONSTRAINT_NAME = 'PRIMARY'
      ORDER BY TABLE_NAME, ORDINAL_POSITION"
);
$stmt->execute([':db' => $env['DB_NAME']]);
$pkPerTabel = [];
foreach ($stmt->fetchAll() as $k) {
    $pkPerTabel[$k['TABLE_NAME']][] = $k['COLUMN_NAME'];
}

/* ------------------------------------------------------------------ */
/* 4. Kunci asing                                                      */
/* ------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
       FROM information_schema.KEY_COLUMN_USAGE kcu
       JOIN information_schema.TABLE_CONSTRAINTS tc
         ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
        AND tc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
        AND tc.TABLE_NAME        = kcu.TABLE_NAME
      WHERE kcu.TABLE_SCHEMA = :db
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
      ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME"
);
$stmt->execute([':db' => $env['DB_NAME']]);
$fkPerTabel = [];
foreach ($stmt->fetchAll() as $k) {
    $fkPerTabel[$k['TABLE_NAME']][] = [
        'kolom'    => $k['COLUMN_NAME'],
        'rujukan'  => $k['REFERENCED_TABLE_NAME'],
        'kolom_rujukan' => $k['REFERENCED_COLUMN_NAME'],
    ];
}

/* ------------------------------------------------------------------ */
/* Susun                                                                */
/* ------------------------------------------------------------------ */
$skema = [
    'database'  => $env['DB_NAME'],
    'dibuat'    => date('c'),
    'catatan'   => 'View Power BI (vw_*) sengaja TIDAK disertakan. '
                 . 'Jangan pernah mengubahnya: 22 view dipakai laporan eksternal.',
    'tabel'     => [],
];

foreach ($tabelDasar as $t) {
    $ks = $kolomPerTabel[$t] ?? [];
    $skema['tabel'][$t] = [
        'kunci_utama' => $pkPerTabel[$t] ?? [],
        'kunci_asing' => $fkPerTabel[$t] ?? [],
        'kolom'       => array_map(fn ($k) => [
            'nama'        => $k['COLUMN_NAME'],
            'tipe'        => $k['COLUMN_TYPE'],
            'tipe_dasar'  => $k['DATA_TYPE'],
            'nullable'    => $k['IS_NULLABLE'] === 'YES',
            'default'     => $k['COLUMN_DEFAULT'],
            'extra'       => $k['EXTRA'],
            'kunci'       => $k['COLUMN_KEY'],
            'panjang_maks'=> $k['CHARACTER_MAXIMUM_LENGTH'],
        ], $ks),
    ];
}

$json = json_encode($skema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tujuan = dirname(__DIR__) . '/database/schema/skema.json';

if (! is_dir(dirname($tujuan))) {
    mkdir(dirname($tujuan), 0775, true);
}
file_put_contents($tujuan, $json . "\n");

printf("Tabel dasar : %d\n", count($tabelDasar));
printf("Tulis ke    : %s (%d bytes)\n", $tujuan, strlen((string) $json));

/* Ringkasan singkat: tabel + PK + jumlah kolom */
echo "\n%-34s %-22s %s\n", 'TABEL', 'KUNCI UTAMA', 'JML KOLOM';
foreach ($skema['tabel'] as $t => $i) {
    printf("%-34s %-22s %d\n", $t, implode(',', $i['kunci_utama']) ?: '(komposit)', count($i['kolom']));
}
