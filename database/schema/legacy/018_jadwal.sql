-- ============================================================
-- 018_jadwal.sql
-- Jadwal & pengingat kegiatan per pengguna (agenda pribadi).
--   · Dipakai halaman jadwal.php dan alat AI (buat/daftar/ubah/hapus).
--   · pengingat_menit = berapa menit sebelum `mulai` pengingat dikirim.
--   · pengingat_terkirim menandai pengingat sudah dikirim (idempoten).
-- Idempoten (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `jadwal` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`            INT(11)      NOT NULL,
  `judul`              VARCHAR(200) NOT NULL,
  `deskripsi`          TEXT         DEFAULT NULL,
  `mulai`              DATETIME     NOT NULL,
  `selesai`            DATETIME     DEFAULT NULL,
  `lokasi`             VARCHAR(200) DEFAULT NULL,
  `pengingat_menit`    INT(11)      NOT NULL DEFAULT 30,
  `status`             ENUM('rencana','selesai','batal') NOT NULL DEFAULT 'rencana',
  `pengingat_terkirim` TINYINT(1)   NOT NULL DEFAULT 0,
  `dibuat_pada`        TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada`    TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_jadwal_user` (`user_id`,`mulai`),
  KEY `idx_jadwal_pengingat` (`status`,`pengingat_terkirim`,`mulai`),
  CONSTRAINT `fk_jadwal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
