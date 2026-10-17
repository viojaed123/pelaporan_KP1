-- ============================================================
-- 016_foto_profil.sql
-- Tambahkan kolom `foto_profil` pada `users` (opsional).
--   · Menyimpan NAMA BERKAS saja (bukan path), mis. u7_a1b2c3d4.jpg.
--     Berkas fisik berada di uploads/avatar/ dan diakses lewat helper.
--   · NULL = belum ada foto; tampilan memakai avatar inisial.
--   · Folder uploads/avatar diberi .htaccess agar tidak mengeksekusi PHP.
-- Idempoten (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `foto_profil` VARCHAR(255) NULL
      AFTER `telepon`;
