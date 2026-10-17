<?php
/**
 * includes/config_lokal.example.php
 * -------------------------------------------------------------
 * SALIN berkas ini menjadi `includes/config_lokal.php`, lalu isi nilai
 * aslinya. Berkas config_lokal.php TIDAK ikut versi kontrol (lihat
 * .gitignore) sehingga rahasia tidak pernah tersebar.
 *
 * Berkas ini dimuat LEBIH DULU daripada nilai bawaan di config.php,
 * jadi cukup mendefinisikan yang perlu ditimpa saja. Gunakan define()
 * seperti contoh di bawah.
 *
 * Jangan menaruh rahasia asli di config.php, dokumentasi, atau berkas
 * migrasi.
 * -------------------------------------------------------------
 */

declare(strict_types=1);

/* --- Basis data --- */
define('DB_PASS', 'SANDI_DATABASE_ANDA');

/* --- Layanan AI (harus sama dengan ai-service/.env) --- */
define('AI_SERVICE_KEY', 'KUNCI_ACAK_PANJANG_ANDA');

/* --- SMTP (biarkan SMTP_HOST kosong untuk mode pengembangan) --- */
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');          // 'tls' | 'ssl' | ''
define('SMTP_USER', 'alamat@pengirim.co.id');
define('SMTP_PASS', 'APP_PASSWORD_ANDA');
define('SMTP_FROM', 'alamat@pengirim.co.id');
// SMTP_FROM_NAME tidak perlu diisi (bawaan: nama aplikasi)

/* --- WhatsApp (kosongkan WA_GATEWAY untuk menonaktifkan) --- */
define('WA_GATEWAY', 'fonnte');            // 'fonnte' | 'wablas' | ''
define('WA_API_KEY', 'TOKEN_GATEWAY_ANDA');

/* --- Opsional: mode pengembangan --- */
// define('APP_DEBUG', true);
