-- ============================================================
-- 014_komentar_laporan.sql
-- Diskusi/komentar pada laporan harian antara karyawan dan mentor.
-- Berguna sebagai jejak komunikasi saat laporan perlu revisi.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `komentar_laporan` (
  `id`          BIGINT(20)  NOT NULL AUTO_INCREMENT,
  `laporan_id`  INT(11)     NOT NULL,
  `user_id`     INT(11)     NOT NULL,
  `isi`         TEXT        NOT NULL,
  `dibuat_pada` TIMESTAMP   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_komentar_laporan` (`laporan_id`,`dibuat_pada`),
  KEY `idx_komentar_user` (`user_id`),
  CONSTRAINT `fk_komentar_laporan`
    FOREIGN KEY (`laporan_id`) REFERENCES `laporan_harian` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_komentar_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
