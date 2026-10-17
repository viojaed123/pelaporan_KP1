-- ============================================================
-- 017_impor_laporan.sql
-- Dukungan impor laporan dari berkas Excel/CSV (pemetaan kolom bebas).
--   · impor_profil  : menyimpan profil pemetaan kolom agar impor
--                     berikutnya tak perlu memetakan ulang.
--   · impor_sejarah : catatan setiap proses impor (hasil & ringkasan).
-- Idempoten (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `impor_profil` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `nama`        VARCHAR(100) NOT NULL,
  `jenis`       VARCHAR(40)  NOT NULL DEFAULT 'laporan_harian',
  `pemetaan`    TEXT         NOT NULL,
  `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_impor_profil` (`user_id`,`jenis`,`nama`),
  KEY `idx_impor_profil_user` (`user_id`),
  CONSTRAINT `fk_impor_profil_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `impor_sejarah` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`         INT(11)      NOT NULL,
  `jenis`           VARCHAR(40)  NOT NULL DEFAULT 'laporan_harian',
  `nama_berkas`     VARCHAR(255) NOT NULL,
  `jumlah_baris`    INT(11)      NOT NULL DEFAULT 0,
  `jumlah_masuk`    INT(11)      NOT NULL DEFAULT 0,
  `jumlah_dilewati` INT(11)      NOT NULL DEFAULT 0,
  `jumlah_gagal`    INT(11)      NOT NULL DEFAULT 0,
  `ringkasan`       TEXT         DEFAULT NULL,
  `dibuat_pada`     TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_impor_sejarah_user` (`user_id`,`dibuat_pada`),
  CONSTRAINT `fk_impor_sejarah_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
