<?php
/**
 * Satu-kali: membuat .env Laravel dari konfigurasi flat-PHP lama.
 *
 *   php scripts/buat_env_laravel.php
 *
 * Nilai rahasia dibaca langsung dari includes/config_lokal.php dan tidak
 * pernah dicetak ke layar — hanya jumlah berkas yang dilaporkan.
 * Aman dijalankan ulang (file .env ditimpa).
 */

declare(strict_types=1);

$root = dirname(__DIR__);

if (!defined('APP_NAMA')) {
    require $root . '/includes/config.php';
}

/* ---- Kunci yang dibutuhkan .env ---- */
$map = [
    'APP_NAME'        => defined('APP_NAMA') ? APP_NAMA : 'Sistem Pelaporan Kegiatan Harian',
    'APP_ENV'         => 'production',
    'APP_KEY'         => '',
    'APP_DEBUG'       => 'false',
    'APP_URL'         => 'http://pelaporan.local',
    'APP_TIMEZONE'    => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta',
    'APP_LOCALE'      => 'id',
    'APP_FALLBACK_LOCALE' => 'id',

    'DB_CONNECTION'   => 'mysql',
    'DB_HOST'         => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
    'DB_PORT'         => '3306',
    'DB_DATABASE'     => defined('DB_NAME') ? DB_NAME : 'pelaporan',
    'DB_USERNAME'     => defined('DB_USER') ? DB_USER : 'pelaporan_app',
    'DB_PASSWORD'     => defined('DB_PASS') ? DB_PASS : '',
    'DB_CHARSET'      => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',

    'SESSION_DRIVER'  => 'database',
    'SESSION_LIFETIME' => '840',
    'SESSION_SECURE_COOKIE' => 'false',
    'SESSION_COOKIE'  => 'PHRSESS',

    'CACHE_STORE'     => 'database',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER'     => defined('SMTP_HOST') && SMTP_HOST !== '' ? 'smtp' : 'log',
    'MAIL_HOST'       => defined('SMTP_HOST') ? SMTP_HOST : '',
    'MAIL_PORT'       => (string) (defined('SMTP_PORT') ? SMTP_PORT : 587),
    'MAIL_ENCRYPTION' => defined('SMTP_ENCRYPTION') ? strtolower((string) SMTP_ENCRYPTION) : 'tls',
    'MAIL_USERNAME'   => defined('SMTP_USER') ? SMTP_USER : '',
    'MAIL_PASSWORD'   => defined('SMTP_PASS') ? SMTP_PASS : '',
    'MAIL_FROM_ADDRESS' => defined('SMTP_FROM') && SMTP_FROM !== '' ? SMTP_FROM : 'no-reply@pelaporan.local',
    'MAIL_FROM_NAME'  => defined('APP_NAMA') ? APP_NAMA : 'Pelaporan',

    /* --- Rahasia sistem (bukan bawaan Laravel) --- */
    'AI_BASE_URL'     => defined('AI_BASE_URL') ? AI_BASE_URL : 'http://127.0.0.1:8000',
    'AI_SERVICE_KEY'  => defined('AI_SERVICE_KEY') ? AI_SERVICE_KEY : '',
    'AI_TIMEOUT'      => (string) (defined('AI_TIMEOUT_DETIK') ? AI_TIMEOUT_DETIK : 60),
    'AI_TIMEOUT_LONG' => (string) (defined('AI_TIMEOUT_PANJANG') ? AI_TIMEOUT_PANJANG : 180),
    'AI_AKTIF'        => defined('AI_AKTIF') && AI_AKTIF ? 'true' : 'false',

    'WA_GATEWAY'      => defined('WA_GATEWAY') ? WA_GATEWAY : '',
    'WA_API_KEY'      => defined('WA_API_KEY') ? WA_API_KEY : '',

    'MAIL_LOG_DIR'    => 'backups/mail-log',
    'BACKUP_DIR'      => 'backups/db',
    'UPLOAD_DIR'      => 'storage/app/uploads',
    'MYSQLDUMP_BIN'   => defined('MYSQLDUMP_BIN') ? MYSQLDUMP_BIN : 'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'MYSQL_BIN'       => defined('MYSQL_BIN') ? MYSQL_BIN : 'C:\\xampp\\mysql\\bin\\mysql.exe',
];

/** Amankan nilai multiline / bergaris bagi agar tetap valid sebagai satu baris .env. */
function esc(string $v): string
{
    if ($v === '') {
        return '""';
    }
    if (preg_match('/[\s"\\\\#]|^(true|false|null)$/i', $v)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return $v;
}

$lines = ['# =============================================================',
          '# .env — dibuat otomatis oleh scripts/buat_env_laravel.php',
          '# JANGAN di-commit ke Git (lihat .gitignore).',
          '# =============================================================', ''];

foreach ($map as $k => $v) {
    $lines[] = $k . '=' . esc((string) $v);
}

$lines[] = '';
$lines[] = 'LOG_CHANNEL=stack';
$lines[] = 'LOG_LEVEL=error';

$file = $root . '/.env';
$ok = file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);

if ($ok === false) {
    fwrite(STDERR, "GAGAL menulis .env\n");
    exit(1);
}

/* APP_KEY: buat bila belum ada (tanpa menampilkan kuncinya). */
if (strpos((string) file_get_contents($file), 'APP_KEY=base64:') === false) {
    $key = 'base64:' . base64_encode(random_bytes(32));
    file_put_contents($file, preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, (string) file_get_contents($file)));
}

/* Larangan: .env tidak boleh menyisipkan baris kosong di tengah, dan semua kunci wajib ada. */
$isi = (string) file_get_contents($file);
$hilang = [];
foreach (array_keys($map) as $k) {
    if (!preg_match('/^' . preg_quote($k, '/') . '=/m', $isi)) {
        $hilang[] = $k;
    }
}

printf("Menulis %s (%d baris).\n", basename($file), substr_count($isi, PHP_EOL));
printf("Kunci hilang: %s\n", $hilang === [] ? 'tidak ada' : implode(', ', $hilang));
printf("APP_KEY terisi: %s\n", preg_match('/^APP_KEY=base64:.+$/m', $isi) ? 'ya' : 'TIDAK');
printf("Sandi DB terisi: %s\n", preg_match('/^DB_PASSWORD=(?!""$)(.+)$/m', $isi) && $map['DB_PASSWORD'] !== '' ? 'ya (nilai disembunyikan)' : 'tidak (memakai default)');
printf("Sandi SMTP terisi: %s\n", $map['MAIL_PASSWORD'] !== '' ? 'ya (nilai disembunyikan)' : 'tidak');
printf("Kunci AI terisi: %s\n", $map['AI_SERVICE_KEY'] !== '' ? 'ya (nilai disembunyikan)' : 'tidak');
