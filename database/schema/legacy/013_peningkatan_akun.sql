-- ============================================================
-- 013_peningkatan_akun.sql
-- Peningkatan akun & performa:
--   * kolom users: telepon, status, email_verified_at
--   * indeks yang hilang pada basis data lama
--   * tabel user_tokens (verifikasi surel + atur ulang kata sandi)
--
-- Idempoten: aman dijalankan berulang. Memakai IF NOT EXISTS
-- (didukung MariaDB 10.0.2+ untuk kolom, 10.1.4+ untuk indeks).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Kolom baru pada `users`
-- ------------------------------------------------------------

-- Nomor telepon (untuk notifikasi WhatsApp, opsional)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `telepon` VARCHAR(30) DEFAULT NULL AFTER `divisi`;

-- Status akun:
--   aktif    -> dapat masuk
--   menunggu -> menunggu verifikasi surel
--   nonaktif -> diblokir oleh admin
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `status`
    ENUM('aktif','menunggu','nonaktif') NOT NULL DEFAULT 'aktif' AFTER `role`;

-- Waktu surel diverifikasi (NULL = belum)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_verified_at` DATETIME DEFAULT NULL AFTER `status`;

-- Indeks untuk pencarian status (pemantauan admin)
CREATE INDEX IF NOT EXISTS `idx_users_status` ON `users` (`status`);

-- ------------------------------------------------------------
-- 2. Indeks yang hilang pada basis data lama
--    (didefinisikan di 001 tetapi belum terpasang di DB berjalan)
-- ------------------------------------------------------------
CREATE INDEX IF NOT EXISTS `idx_laporan_status`  ON `laporan_harian` (`status`);
CREATE INDEX IF NOT EXISTS `idx_laporan_tanggal` ON `laporan_harian` (`tanggal`);
CREATE INDEX IF NOT EXISTS `idx_kasus_status`    ON `kasus` (`status_kasus`);
CREATE INDEX IF NOT EXISTS `idx_kasus_urgensi`   ON `kasus` (`tingkat_urgensi`);

-- ------------------------------------------------------------
-- 3. Token satu pakai (verifikasi surel & atur ulang kata sandi)
--    Nilai token disimpan ter-hash (SHA-256), bukan teks mentah.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `jenis`       ENUM('verifikasi_email','reset_sandi') NOT NULL,
  `token_hash`  CHAR(64)     NOT NULL,
  `kedaluwarsa` DATETIME     NOT NULL,
  `dipakai`     TINYINT(1)   NOT NULL DEFAULT 0,
  `dipakai_pada` DATETIME    DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_token_user` (`user_id`,`jenis`),
  CONSTRAINT `fk_token_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan: kolom `status` memakai DEFAULT 'aktif', sehingga seluruh akun
-- yang sudah ada otomatis tetap dapat masuk. Hanya pendaftaran baru yang
-- secara eksplisit diberi status 'menunggu' sampai surel diverifikasi.
-- Tidak ada UPDATE di sini agar migrasi tetap idempoten dan tidak
-- mengaktifkan kembali akun yang sengaja dinonaktifkan.
