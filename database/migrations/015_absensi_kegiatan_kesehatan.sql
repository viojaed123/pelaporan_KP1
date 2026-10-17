-- ============================================================
-- 015_absensi_kegiatan_kesehatan.sql
-- Tambahkan "kegiatan hari ini" dan "kondisi kesehatan" pada absensi.
--   · kegiatan_hari_ini : ringkasan pekerjaan yang dikerjakan hari itu
--   · kondisi_kesehatan : swa-laporan kesehatan saat absen (enum)
-- Berguna untuk pemantauan mentor: memahami beban kerja & kesejahteraan
-- karyawan sekaligus menjadi dasar penugasan bila karyawan kurang sehat.
-- Idempoten (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `absensi`
  ADD COLUMN IF NOT EXISTS `kegiatan_hari_ini` TEXT NULL
      AFTER `keterangan`,
  ADD COLUMN IF NOT EXISTS `kondisi_kesehatan`
      ENUM('sehat','kurang_sehat','sakit','pemulihan') NOT NULL DEFAULT 'sehat'
      AFTER `kegiatan_hari_ini`;
