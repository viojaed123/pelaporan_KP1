<?php
/**
 * koneksi.php — koneksi database tunggal (seluruh halaman memakai file ini).
 *
 * Kredensial diambil dari includes/config.php sehingga dapat ditimpa lewat
 * includes/config_lokal.php tanpa menyentuh file versi.
 */

declare(strict_types=1);

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/includes/config.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log('Koneksi database gagal: ' . $conn->connect_error);
        if (!headers_sent()) {
            http_response_code(500);
        }
        exit('Koneksi database gagal. Periksa konfigurasi server.');
    }

    $conn->set_charset(DB_CHARSET);
}
