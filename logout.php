<?php
/**
 * logout.php — keluar sesi dengan aman:
 *   1. cabut sesi perangkat di database
 *   2. catat audit keluar
 *   3. bersihkan sesi + cookie
 *   4. regenerasi id sesi sebelum kembali ke halaman masuk
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$uid = sesi_user_id();
$nama = sesi_nama();

if ($uid !== null) {
    try {
        sesi_cabut_sesi_ini($conn);
        catat_audit($conn, 'logout', 'users', $uid, 'Keluar dari sistem: ' . $nama, null, null);
    } catch (Throwable $t) {
        error_log('logout: ' . $t->getMessage());
    }
}

// Hapus seluruh data sesi
$_SESSION = [];

// Hapus cookie sesi
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}

// Hancurkan sesi
session_destroy();

header('Location: login.php?alasan=keluar');
exit;
