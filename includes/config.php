<?php
/**
 * includes/config.php
 * Konstanta aplikasi. Seluruh halaman memakai file ini sebagai satu-satunya
 * sumber konfigurasi.
 *
 * RAHASIA (kata sandi basis data, kata sandi SMTP, token WhatsApp, kunci AI)
 * TIDAK ditaruh di sini. Isi berkas `includes/config_lokal.php` yang dibuat
 * dari salinan `includes/config_lokal.example.php`. Berkas itu dimuat LEBIH
 * DULU sehingga nilainya menang, dan tidak ikut versi kontrol (lihat
 * .gitignore). Bila berkas itu tidak ada, aplikasi memakai nilai contoh yang
 * aman (mode pengembangan).
 */

declare(strict_types=1);

/* ---------------------------------------------------------------
 * Penetap konstanta: hanya mendefinisikan bila belum ada, sehingga
 * config_lokal.php dapat menimpanya tanpa peringatan "already defined".
 * ------------------------------------------------------------- */
if (!function_exists('cfg')) {
    function cfg(string $nama, mixed $nilai): void
    {
        if (!defined($nama)) {
            define($nama, $nilai);
        }
    }
}

/* ---------------------------------------------------------------
 * 1. Timpaan lokal (rahasia) dimuat lebih dulu.
 * ------------------------------------------------------------- */
$__cfg_lokal = __DIR__ . '/config_lokal.php';
if (is_file($__cfg_lokal)) {
    require $__cfg_lokal;
}
unset($__cfg_lokal);

/* ---------------------------------------------------------------
 * 2. Nilai bawaan (placeholder yang aman untuk repositori).
 * ------------------------------------------------------------- */

/* --- Aplikasi --- */
cfg('APP_NAMA', 'Sistem Pelaporan Kegiatan Harian');
cfg('APP_SINGKAT', 'Pelaporan Harian');
cfg('APP_ORGANISASI', 'Pertamina Hulu Rokan');
cfg('APP_VERSI', '2.1.0');

/* Kontak bantuan (ditampilkan pada halaman pembuka). */
cfg('APP_KONTAK_EMAIL', 'viojaed123@gmail.com');
cfg('APP_KONTAK_TELP', '085349351900');

/* Mode produksi: bila false, galat PHP tidak ditampilkan ke pengguna
 * (hanya dicatat ke log). Set true saat pengembangan. */
cfg('APP_DEBUG', false);

/* --- Waktu --- */
cfg('APP_TIMEZONE', 'Asia/Jakarta');
date_default_timezone_set((string) APP_TIMEZONE);

/* --- Database (nilai asli di config_lokal.php) ---
 * Akun `pelaporan_app` ber-privilege minimum: ALL PRIVILEGES HANYA pada
 * basis data `pelaporan` (lihat database/migrations/010_user_db.sql dan
 * 011_hak_akses_app.sql). Tidak memiliki hak global, FILE, SUPER, maupun
 * akses basis data lain. Ganti kata sandi sebelum dipakai di produksi. */
cfg('DB_HOST', 'localhost');
cfg('DB_USER', 'pelaporan_app');
cfg('DB_PASS', 'GANTI_SANDI_DB');          // <-- isi di config_lokal.php
cfg('DB_NAME', 'pelaporan');
cfg('DB_CHARSET', 'utf8mb4');

/* --- Batas input laporan --- */
cfg('LAPORAN_KEGIATAN_MAKS', 255);   // varchar(255)
cfg('LAPORAN_TEKS_MAKS', 60000);     // byte per kolom teks
cfg('LAPORAN_CATATAN_MAKS', 5000);   // catatan mentor

/* --- Upload bukti gambar --- */
cfg('UPLOAD_DIR', __DIR__ . '/../uploads');
cfg('UPLOAD_MIME', ['image/jpeg', 'image/png']);
cfg('UPLOAD_MAKS_BYTES', 5 * 1024 * 1024); // 5 MB / file
cfg('UPLOAD_MAKS_JUMLAH', 5);

/* --- Sesi & keamanan --- */
cfg('SESI_IDLE_DETIK', 30 * 60);        // keluar bila diam 30 menit
cfg('SESI_ABSOLUT_DETIK', 8 * 3600);    // keluar paling lama 8 jam
cfg('SESI_MAKS_AKTIF', 3);              // maksimal sesi bersamaan / akun

cfg('LOGIN_MAKS_GAGAL', 5);             // gagal sebelum dikunci
cfg('LOGIN_JENDELA_DETIK', 15 * 60);    // jendela hitungan kegagalan
cfg('LOGIN_KUNCI_DETIK', 15 * 60);      // durasi penguncian
cfg('LOGIN_BACKOFF_DETIK', [300, 900, 3600]); // perpanjangan kunci berulang

cfg('PASSWORD_MIN', 8);                 // minimal 8 karakter (tanpa aturan campuran)
cfg('PASSWORD_RIWAYAT', 3);             // 3 sandi terakhir tidak boleh diulang

cfg('OTP_DETIK', 300);                  // masa berlaku kode 2FA (5 menit)
cfg('OTP_MAKS_COBA', 5);                // percobaan isi kode
cfg('OTP_PANJANG', 6);
cfg('INGAT_HARI', 30);                  // "ingat saya" 30 hari

/* Masa berlaku tautan akun (detik). */
cfg('VERIFIKASI_EMAIL_DETIK', 48 * 3600); // verifikasi surel: 48 jam
cfg('RESET_SANDI_DETIK', 60 * 60);        // atur ulang kata sandi: 1 jam

/* --- Jam kerja absensi (peringatan keterlambatan, bukan penghalang) --- */
cfg('ABSENI_JAM_MASUK', '07:30');
cfg('ABSENI_JAM_PULANG', '17:00');

/* --- Layanan AI (Python / FastAPI + Gemini & Ollama) --- */
cfg('AI_BASE_URL', 'http://127.0.0.1:8000');
cfg('AI_SERVICE_KEY', 'GANTI_KUNCI_AI');   // <-- isi di config_lokal.php + ai-service/.env
cfg('AI_TIMEOUT_DETIK', 60);            // panggilan sinkron (chat/validation)
cfg('AI_TIMEOUT_PANJANG', 180);         // narasi / ekspor
cfg('AI_AKTIF', true);                  // matikan untuk menyembunyikan tombol AI

/* --- Surat elektronik (SMTP) — untuk OTP 2FA, verifikasi, notifikasi ---
 * Kosongkan SMTP_HOST untuk mode pengembangan: surel ditulis ke
 * backups/mail-log/ (kode OTP tetap terbaca). */
cfg('SMTP_HOST', '');                    // contoh: 'smtp.gmail.com' (isi di config_lokal.php)
cfg('SMTP_PORT', 587);
cfg('SMTP_ENCRYPTION', 'tls');           // 'tls' | 'ssl' | ''
cfg('SMTP_USER', '');                    // isi di config_lokal.php
cfg('SMTP_PASS', '');                    // isi di config_lokal.php
cfg('SMTP_FROM', '');                    // isi di config_lokal.php
cfg('SMTP_FROM_NAME', APP_NAMA);
cfg('MAIL_LOG_DIR', __DIR__ . '/../backups/mail-log');

/* --- Pencadangan basis data (backup.php) --- */
cfg('BACKUP_DIR', __DIR__ . '/../backups/db');
cfg('BACKUP_SIMPAN_MAKS', 10);          // simpan N berkas cadangan terakhir
cfg('MYSQLDUMP_BIN', 'C:\\xampp\\mysql\\bin\\mysqldump.exe');
cfg('MYSQL_BIN', 'C:\\xampp\\mysql\\bin\\mysql.exe');

/* --- Pengiriman laporan (email / WhatsApp) --- */
cfg('WA_GATEWAY', '');                   // kosong = nonaktif; isi: 'fonnte'|'wablas'
cfg('WA_API_KEY', '');                   // isi di config_lokal.php

/* ---------------------------------------------------------------
 * 3. Identitas visual (warna korporat PHR — JANGAN diubah)
 * ---------------------------------------------------------------
 * Dipakai oleh template surel (mailer.php) dan pesan OTP (security.php).
 */
cfg('WARNA_BIRU', '#005EB8');
cfg('WARNA_BIRU_TUA', '#003D7D');
cfg('WARNA_MERAH', '#EB1C24');
cfg('WARNA_HIJAU', '#159447');
cfg('WARNA_BG', '#F5F5F5');
