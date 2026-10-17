<?php
/**
 * includes/session.php
 * Bootstrap sesi yang dikeraskan: cookie HttpOnly + SameSite, mode strict,
 * batas diam (idle) dan batas usia mutlak, serta regenerasi id.
 *
 * Idempoten — boleh dipanggil berkali-kali dalam satu request.
 */

declare(strict_types=1);

if (!defined('SESI_IDLE_DETIK')) {
    require_once __DIR__ . '/config.php';
}

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.gc_maxlifetime', (string) SESI_ABSOLUT_DETIK);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_name('PHRSESS');
session_start();

/* ---------------------------------------------------------------
 * Batas waktu sesi
 * ------------------------------------------------------------- */
$sekarang = time();

if (!isset($_SESSION['_sesi_mulai'])) {
    $_SESSION['_sesi_mulai']   = $sekarang;
    $_SESSION['_sesi_terakhir'] = $sekarang;
}
if (!isset($_SESSION['_sesi_terakhir'])) {
    $_SESSION['_sesi_terakhir'] = $sekarang;
}

$idle  = $sekarang - (int) $_SESSION['_sesi_terakhir'];
$usia  = $sekarang - (int) $_SESSION['_sesi_mulai'];

$halaman_publik = [
    'index.php', 'login.php', 'register.php', 'dua_faktor.php',
    'lupa_sandi.php', 'reset_sandi.php', 'verifikasi_email.php',
];
$skrip = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

if (($idle > SESI_IDLE_DETIK || $usia > SESI_ABSOLUT_DETIK)
    && !in_array($skrip, $halaman_publik, true)) {
    $_SESSION = [];

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

    session_destroy();

    header('Location: login.php?alasan=sesi_habis');
    exit;
}

$_SESSION['_sesi_terakhir'] = $sekarang;
