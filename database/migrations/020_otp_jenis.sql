-- ============================================================
-- 020_otp_jenis.sql
-- Memisahkan kode OTP berdasarkan keperluan:
--   · login        -> verifikasi dua faktor (2FA) saat masuk
--   · reset_sandi  -> kode sekali pakai untuk atur ulang kata sandi
--
-- Sebelumnya tabel `user_otp` hanya dipakai untuk 2FA. Kolom `jenis`
-- membuat OTP reset sandi tidak saling menghapus dengan OTP 2FA.
--
-- Idempoten: aman dijalankan berulang.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `user_otp`
  ADD COLUMN IF NOT EXISTS `jenis`
    ENUM('login','reset_sandi') NOT NULL DEFAULT 'login' AFTER `user_id`;

CREATE INDEX IF NOT EXISTS `idx_otp_jenis`
  ON `user_otp` (`user_id`, `jenis`, `dipakai`);
