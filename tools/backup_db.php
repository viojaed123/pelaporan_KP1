<?php
/**
 * tools/backup_db.php — cadangkan basis data (mysqldump) + rotasi berkas.
 *
 * Contoh:
 *   php tools\backup_db.php
 *   php tools\backup_db.php --simpan=30
 *   php tools\backup_db.php --mysqldump="C:\xampp\mysql\bin\mysqldump.exe"
 *   php tools\backup_db.php --sumber=env --log=backup.log
 *
 * Opsi:
 *   --simpan=N      jumlah berkas cadangan yang disimpan (bawaan 14)
 *   --dir=PATH      direktori tujuan (bawaan C:\pelaporan-cadangan)
 *   --mysqldump=P   path mysqldump.exe
 *   --sumber=K      sumber kredensial: config (bawaan) | env
 *                   `env` membaca .env Laravel (DB_HOST/DB_PORT/DB_DATABASE/
 *                   DB_USERNAME/DB_PASSWORD) — berguna saat dijadwalkan dari
 *                   sisi Laravel. `config` membaca includes/config.php
 *                   (+ config_lokal.php) seperti aplikasi legacy.
 *   --log=PATH      tambahkan satu baris ringkasan ke berkas log (opsional)
 *
 * Kata sandi dilewatkan lewat MYSQL_PWD agar tidak tampak di daftar proses.
 *
 * Catatan: skrip ini adalah SATU-SATUNYA pencadang basis data yang kanonik
 * (lihat AGENTS.md). Padanan PowerShell lama (scripts/backup_db.ps1) sudah
 * dihapus; gunakan opsi --sumber=env + --log untuk perilaku yang sama.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari baris perintah (CLI).');
}

$opsi = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/', $arg, $m) === 1) {
        $opsi[$m[1]] = $m[2];
    }
}

$simpan = isset($opsi['simpan']) ? max(1, (int) $opsi['simpan']) : 14;

/*
 * Direktori cadangan BAWAAN berada DI LUAR webroot: dump memuat data pribadi
 * karyawan, jadi tidak boleh berada di dalam pohon yang disajikan Apache.
 * Timpa dengan --dir=PATH bila perlu.
 */
$dirBawaan = getenv('PHR_BACKUP_DIR') ?: 'C:\\pelaporan-cadangan';
$dir    = $opsi['dir'] ?? $dirBawaan;
$sumber = $opsi['sumber'] ?? 'config';

if ($sumber === 'env') {
    /* Baca .env Laravel tanpa memuat seluruh kerangka. */
    $envPath = __DIR__ . '/../.env';
    if (!is_file($envPath)) {
        fwrite(STDERR, "Berkas .env tidak ditemukan di: {$envPath}\n");
        exit(1);
    }
    $env = [];
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $baris) {
        $baris = trim($baris);
        if ($baris === '' || $baris[0] === '#' || !str_contains($baris, '=')) { continue; }
        [$k, $v] = explode('=', $baris, 2);
        $v = trim($v);
        // Buang kutip pembungkus bila ada.
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    $dbHost = $env['DB_HOST'] ?? 'localhost';
    $dbPort = trim((string) ($env['DB_PORT'] ?? ''));
    $dbName = $env['DB_DATABASE'] ?? '';
    $dbUser = $env['DB_USERNAME'] ?? '';
    $dbPass = $env['DB_PASSWORD'] ?? '';
    if ($dbName === '' || $dbUser === '') {
        fwrite(STDERR, ".env tidak memuat DB_DATABASE/DB_USERNAME.\n");
        exit(1);
    }
} else {
    require_once __DIR__ . '/../includes/config.php';
    $dbHost = DB_HOST;
    $dbPort = '';
    $dbName = DB_NAME;
    $dbUser = DB_USER;
    $dbPass = DB_PASS;
}

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

$namaFile = $dir . DIRECTORY_SEPARATOR . sprintf('%s_%s.sql', $dbName, date('Ymd_His'));

/* Kredensial via lingkungan agar tidak tampak di daftar proses. */
putenv('MYSQL_PWD=' . $dbPass);

$cmd = escapeshellarg($mysqldump)
    . ' --host=' . escapeshellarg($dbHost)
    . ($dbPort !== '' ? ' --port=' . escapeshellarg($dbPort) : '')
    . ' --user=' . escapeshellarg($dbUser)
    . ' --single-transaction --quick --routines --events --default-character-set=utf8mb4'
    . ' ' . escapeshellarg($dbName)
    . ' > ' . escapeshellarg($namaFile);

$awal = microtime(true);
$keluar = 0;
$citra = [];
exec($cmd, $citra, $keluar);
putenv('MYSQL_PWD');

/** Tulis ringkasan ke log bila diminta (dipakai penjadwal). */
$tulisLog = static function (string $status, string $keterangan) use ($opsi, $dir): void {
    if (empty($opsi['log'])) {
        return;
    }
    $path = $opsi['log'];
    // Jalur relatif diselesaikan terhadap direktori cadangan, agar log
    // ikut berpindah bersama dump (bukan tertinggal di webroot).
    if (!str_starts_with($path, '/') && !preg_match('~^[A-Za-z]:~', $path)) {
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
    $dirLog = dirname($path);
    if (!is_dir($dirLog)) { @mkdir($dirLog, 0775, true); }
    @file_put_contents(
        $path,
        sprintf("%s  %-6s %s\n", date('Y-m-d H.i.s'), $status, $keterangan),
        FILE_APPEND
    );
};

if ($keluar !== 0 || !is_file($namaFile) || filesize($namaFile) === 0) {
    @unlink($namaFile);
    $tulisLog('GAGAL', "Pencadangan gagal (kode {$keluar}) untuk {$dbName}.");
    fwrite(STDERR, "Pencadangan GAGAL (kode {$keluar}). Periksa kredensial & mysqldump.\n");
    exit(1);
}

$ukuran = round(filesize($namaFile) / 1024, 1);
$detik  = round(microtime(true) - $awal, 2);
echo sprintf("Cadangan dibuat: %s (%.1f KB, %.2f s)\n", $namaFile, $ukuran, $detik);
$tulisLog('OK', sprintf('%s  (%.2f MB)', $namaFile, $ukuran / 1024));

/* --- Rotasi: simpan hanya N berkas terbaru --- */
$berkas = glob($dir . DIRECTORY_SEPARATOR . $dbName . '_*.sql') ?: [];
rsort($berkas, SORT_STRING);
$dihapus = 0;
foreach (array_slice($berkas, $simpan) as $lama) {
    if (@unlink($lama)) {
        $dihapus++;
        echo "Dihapus (rotasi): " . basename($lama) . "\n";
    }
}
echo sprintf("Total %d berkas cadangan disimpan (batas %d).\n", min(count($berkas), $simpan), $simpan);
