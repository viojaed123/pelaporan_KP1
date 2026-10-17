<?php
/**
 * includes/csrf.php
 * Token CSRF per-aksi — mencegah rotasi token mematikan form di tab lain
 * (masalah lama: detail_laporan.php merotasi $_SESSION['csrf_token'] global).
 *
 * Pakai:
 *   <?= csrf_field('kirim_laporan') ?>          // di form
 *   if (!csrf_cek('kirim_laporan')) { ... }     // saat proses POST
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('_csrf_simpan')) {
    function _csrf_simpan(string $aksi): string
    {
        if (empty($_SESSION['_csrf']) || !is_array($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = [];
        }
        if (empty($_SESSION['_csrf'][$aksi])) {
            $_SESSION['_csrf'][$aksi] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'][$aksi];
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(string $aksi = 'default'): string
    {
        return _csrf_simpan($aksi);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(string $aksi = 'default'): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . e(csrf_token($aksi)) . '">';
    }
}

if (!function_exists('csrf_cek')) {
    /**
     * Verifikasi token POST.
     * - $aksi tertentu  : bandingkan dengan token aksi itu saja.
     * - $aksi null      : izinkan token aksi mana pun (aman — tetap harus
     *                     token valid yang diterbitkan sesi ini).
     */
    function csrf_cek(?string $aksi = null, ?string $token = null): bool
    {
        $token = $token ?? ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');

        if (!is_string($token) || $token === '') {
            return false;
        }

        $kandidat = [];

        if ($aksi !== null) {
            $kandidat[] = (string) ($_SESSION['_csrf'][$aksi] ?? '');
        } elseif (!empty($_SESSION['_csrf']) && is_array($_SESSION['_csrf'])) {
            foreach ($_SESSION['_csrf'] as $t) {
                $kandidat[] = (string) $t;
            }
        }

        // kompatibilitas dengan token lama
        if (isset($_SESSION['csrf_token'])) {
            $kandidat[] = (string) $_SESSION['csrf_token'];
        }

        foreach ($kandidat as $t) {
            if ($t !== '' && hash_equals($t, $token)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('csrf_cek_atau_matikan')) {
    /** Verifikasi; bila gagal kirim 403 dan hentikan. */
    function csrf_cek_atau_matikan(?string $aksi = null): void
    {
        if (!csrf_cek($aksi)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            exit('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
        }
    }
}
