<?php
/**
 * tools/backup_db.php — cadangkan basis data (mysqldump) + rotasi berkas.
 *
 * Contoh:
 *   php tools\backup_db.php
 *   php tools\backup_db.php --simpan=30
 *   php tools\backup_db.php --mysqldump="C:\xampp\mysql\bin\mysqldump.exe"
 *
 * Opsi:
 *   --simpan=N      jumlah berkas cadangan yang disimpan (bawaan 14)
 *   --dir=PATH      direktori tujuan (bawaan <proyek>/backups)
 *   --mysqldump=P   path mysqldump.exe
 *
 * Kredensial diambil dari includes/config.php (+ config_lokal.php) sehingga
 * tidak perlu ditulis ulang. Kata sandi dilewatkan lewat MYSQL_PWD agar tidak
 * tampak di daftar proses.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari baris perintah (CLI).');
}

require_once __DIR__ . '/../includes/config.php';

$opsi = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/', $arg, $m) === 1) {
        $opsi[$m[1]] = $m[2];
    }
}

$simpan = isset($opsi['simpan']) ? max(1, (int) $opsi['simpan']) : 14;
$dir    = $opsi['dir'] ?? (__DIR__ . '/../backups');

$mysqldump = $opsi['mysqldump']
    ?? getenv('MYSQLDUMP')
    ?: 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

if (!is_file($mysqldump)) {
    fwrite(STDERR, "mysqldump tidak ditemukan di: {$mysqldump}\n");
    fwrite(STDERR, "Tentukan dengan --mysqldump=PATH.\n");
    exit(1);
}

if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Tidak dapat membuat direktori: {$dir}\n");
    exit(1);
}

$namaFile = $dir . DIRECTORY_SEPARATOR . sprintf('%s_%s.sql', DB_NAME, date('Ymd_His'));

/* Kredensial via lingkungan agar tidak tampak di daftar proses. */
putenv('MYSQL_PWD=' . DB_PASS);

$cmd = escapeshellarg($mysqldump)
    . ' --host=' . escapeshellarg(DB_HOST)
    . ' --user=' . escapeshellarg(DB_USER)
    . ' --single-transaction --quick --routines --events --default-character-set=utf8mb4'
    . ' ' . escapeshellarg(DB_NAME)
    . ' > ' . escapeshellarg($namaFile);

$awal = microtime(true);
$keluar = 0;
$citra = [];
exec($cmd, $citra, $keluar);
putenv('MYSQL_PWD');

if ($keluar !== 0 || !is_file($namaFile) || filesize($namaFile) === 0) {
    @unlink($namaFile);
    fwrite(STDERR, "Pencadangan GAGAL (kode {$keluar}). Periksa kredensial & mysqldump.\n");
    exit(1);
}

$ukuran = round(filesize($namaFile) / 1024, 1);
$detik  = round(microtime(true) - $awal, 2);
echo sprintf("Cadangan dibuat: %s (%.1f KB, %.2f s)\n", $namaFile, $ukuran, $detik);

/* --- Rotasi: simpan hanya N berkas terbaru --- */
$berkas = glob($dir . DIRECTORY_SEPARATOR . DB_NAME . '_*.sql') ?: [];
rsort($berkas, SORT_STRING);
$dihapus = 0;
foreach (array_slice($berkas, $simpan) as $lama) {
    if (@unlink($lama)) {
        $dihapus++;
        echo "Dihapus (rotasi): " . basename($lama) . "\n";
    }
}
echo sprintf("Total %d berkas cadangan disimpan (batas %d).\n", min(count($berkas), $simpan), $simpan);
