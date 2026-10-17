<?php
/**
 * includes/bootstrap.php
 * Satu-satunya titik masuk yang perlu dipanggil setiap halaman:
 *
 *   require_once __DIR__ . '/includes/bootstrap.php';
 *
 * Urutan penting: konfigurasi -> sesi -> koneksi -> helpers -> csrf -> auth.
 * (Sesi harus hidup sebelum output apa pun keluar.)
 */

declare(strict_types=1);

if (!defined('APP_NAMA')) {
    require_once __DIR__ . '/config.php';
}

/* Pengerasan produksi: jangan tampilkan galat PHP ke pengguna, tetapi
 * tetap catat SEMUA galat ke log server. Set APP_DEBUG=true saat pengembangan. */
if (defined('APP_DEBUG') && !APP_DEBUG) {
    error_reporting(E_ALL);          // semua galat dicatat...
    @ini_set('display_errors', '0');  // ...tetapi tidak ditampilkan
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

// Koneksi database (root/koneksi.php memakai config yang sama)
require_once dirname(__DIR__) . '/koneksi.php';

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifikasi.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/akun.php';
require_once __DIR__ . '/komentar.php';
require_once __DIR__ . '/pemberitahuan.php';
require_once __DIR__ . '/keputusan_laporan.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/badge_helper.php';
require_once __DIR__ . '/impor_excel.php';
require_once __DIR__ . '/jadwal.php';
require_once __DIR__ . '/srm_cid.php';
require_once __DIR__ . '/wilayah.php';
require_once __DIR__ . '/kasus.php';

// Layout terakhir (membutuhkan seluruh helper di atas).
// require_once mencegah ulang-muat bila layout memanggil bootstrap.
require_once __DIR__ . '/layout.php';




