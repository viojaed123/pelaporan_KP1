<?php
/**
 * Bootstrap bersama untuk skrip CLI di scripts/.
 *
 *   require __DIR__ . '/lib_bootstrap.php';   // di baris pertama skrip
 *
 * Menyediakan:
 *   - $root : path absolut root proyek
 *   - $env  : array isi .env (dimuat dengan phpdotenv, parser resmi Laravel)
 *   - $pdo  : koneksi PDO ke database aplikasi (siap dipakai)
 *
 * Mengapa phpdotenv, bukan parse_ini_file():
 *   format .env Laravel bukan INI murni (nilai tanpa tanda kutip, tanda #
 *   sebagai komentar, karakter multi-byte). parse_ini_file menolaknya.
 *
 * Kerahasiaan: berkas ini TIDAK PERNAH mencetak nilai env ke output.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/* Pastikan penanganan kesalahan PHP tampil (bukan halaman kosong). */
ini_set('display_errors', '1');
error_reporting(E_ALL);

/* Autoloader Composer: menyediakan Dotenv, PDO extension, dsb. */
$autoload = $root . '/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "GAGAL: vendor/autoload.php tidak ada. Jalankan composer install.\n");
    exit(1);
}
require $autoload;

$envFile = $root . '/.env';
if (! is_file($envFile)) {
    fwrite(STDERR, "GAGAL: berkas .env tidak ditemukan di $envFile\n");
    fwrite(STDERR, "       Jalankan dulu: php scripts/buat_env_laravel.php\n");
    exit(1);
}

try {
    $dotenv = Dotenv\Dotenv::createImmutable($root);
    $dotenv->load();
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL memuat .env: ' . $e->getMessage() . "\n");
    exit(1);
}

/* Array nilai env, untuk skrip yang ingin menampilkan nama host/db saja. */
$env = [
    'DB_HOST'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'DB_PORT'     => $_ENV['DB_PORT'] ?? '3306',
    'DB_NAME'     => $_ENV['DB_DATABASE'] ?? '',
    'DB_USERNAME' => $_ENV['DB_USERNAME'] ?? '',
    'DB_PASSWORD' => $_ENV['DB_PASSWORD'] ?? '',
];

if ($env['DB_NAME'] === '') {
    fwrite(STDERR, "GAGAL: DB_DATABASE kosong di .env\n");
    exit(1);
}

/* Koneksi PDO siap pakai. */
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'],
            $env['DB_PORT'],
            $env['DB_NAME']
        ),
        $env['DB_USERNAME'],
        $env['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'GAGAL koneksi database: ' . $e->getMessage() . "\n");
    exit(1);
}
