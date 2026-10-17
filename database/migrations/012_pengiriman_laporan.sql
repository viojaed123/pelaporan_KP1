-- ============================================================
-- 012_pengiriman_laporan.sql
-- Antrean pengiriman laporan (email / WhatsApp) dengan alur
-- PERSETUJUAN sebelum benar-benar dikirim.
--
-- Alur status:
--   menunggu   -> diajukan oleh mentor/admin
--   disetujui  -> disetujui admin (siap dikirim)
--   terkirim   -> berhasil dikirim
--   gagal      -> pengiriman gagal (lihat kolom galat)
--   dibatalkan -> ditolak admin
-- ============================================================

CREATE TABLE IF NOT EXISTS `pengiriman_laporan` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `laporan_id`     INT(11) DEFAULT NULL,
  `penerima`       VARCHAR(190) NOT NULL,
  `nama_penerima`  VARCHAR(190) DEFAULT NULL,
  `kanal`          ENUM('email','whatsapp') NOT NULL,
  `subjek`         VARCHAR(190) DEFAULT NULL,
  `pesan`          TEXT DEFAULT NULL,
  `status`         ENUM('menunggu','disetujui','terkirim','gagal','dibatalkan')
                     NOT NULL DEFAULT 'menunggu',
  `diajukan_oleh`  INT(11) DEFAULT NULL,
  `disetujui_oleh` INT(11) DEFAULT NULL,
  `disetujui_pada` DATETIME DEFAULT NULL,
  `dikirim_pada`   DATETIME DEFAULT NULL,
  `galat`          VARCHAR(255) DEFAULT NULL,
  `dibuat_pada`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_diajukan` (`diajukan_oleh`),
  KEY `idx_laporan` (`laporan_id`),
  CONSTRAINT `fk_kirim_laporan`
    FOREIGN KEY (`laporan_id`) REFERENCES `laporan_harian` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pelacakan pengiriman dicatat pada audit_log, tidak memakai trigger
-- (menjaga prinsip: tidak ada logika bisnis tersembunyi di basis data).
