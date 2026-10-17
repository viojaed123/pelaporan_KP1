-- ============================================================
-- 003_audit_log.sql
-- Riwayat perubahan data (audit trail) — mencatat siapa mengubah apa,
-- kapan, dari nilai apa menjadi nilai apa.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      DEFAULT NULL,
  `aksi`        VARCHAR(60)  NOT NULL,
  `tabel_ref`   VARCHAR(64)  DEFAULT NULL,
  `baris_id`    INT(11)      DEFAULT NULL,
  `ringkasan`   VARCHAR(255) DEFAULT NULL,
  `data_lama`   JSON         DEFAULT NULL,
  `data_baru`   JSON         DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_aksi` (`aksi`),
  KEY `idx_audit_tabel` (`tabel_ref`,`baris_id`),
  KEY `idx_audit_waktu` (`dibuat_pada`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel pendukung pengiriman laporan (email / WhatsApp)
CREATE TABLE IF NOT EXISTS `laporan_terkirim` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `laporan_id`   INT(11)      DEFAULT NULL,
  `kanal`        ENUM('email','whatsapp') NOT NULL,
  `penerima`     VARCHAR(190) NOT NULL,
  `subjek`       VARCHAR(255) DEFAULT NULL,
  `lampiran`     VARCHAR(255) DEFAULT NULL,
  `status`       ENUM('antre','terkirim','gagal') NOT NULL DEFAULT 'antre',
  `pesan_error`  TEXT         DEFAULT NULL,
  `disetujui_oleh` INT(11)    DEFAULT NULL,
  `dibuat_pada`  TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `terkirim_pada` DATETIME    DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lt_laporan` (`laporan_id`),
  KEY `idx_lt_status` (`status`,`dibuat_pada`),
  CONSTRAINT `fk_lt_laporan` FOREIGN KEY (`laporan_id`) REFERENCES `laporan_harian` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengingat catatan harian
CREATE TABLE IF NOT EXISTS `pengingat` (
  `id`           INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)   NOT NULL,
  `jenis`        VARCHAR(40) NOT NULL DEFAULT 'laporan_harian',
  `tanggal_ref`  DATE      DEFAULT NULL,
  `waktu_kirim`  DATETIME  NOT NULL,
  `status`       ENUM('antre','terkirim','dilewati') NOT NULL DEFAULT 'antre',
  `dibuat_pada`  TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pengingat` (`user_id`,`jenis`,`tanggal_ref`),
  KEY idx_pengingat_waktu (`waktu_kirim`,`status`),
  CONSTRAINT `fk_pengingat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
