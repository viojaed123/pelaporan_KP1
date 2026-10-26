SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `cost_avoidance` (
  `id`                 INT(11)        NOT NULL AUTO_INCREMENT,
  `kode`               VARCHAR(20)    NOT NULL,
  `judul`              VARCHAR(200)   NOT NULL,
  `kategori`           VARCHAR(80)    NOT NULL,
  `deskripsi`          TEXT           DEFAULT NULL,
  `unit`               VARCHAR(120)   DEFAULT NULL,
  `periode`            DATE           NOT NULL,
  `estimasi`           DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `realisasi`          DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `nilai_avoidance`    DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `status`             ENUM('draft','diajukan','disetujui','ditolak') NOT NULL DEFAULT 'draft',
  `catatan_verifikasi` VARCHAR(255)   DEFAULT NULL,
  `dibuat_oleh`        INT(11)        NOT NULL,
  `diverifikasi_oleh`  INT(11)        DEFAULT NULL,
  `diajukan_pada`      DATETIME       DEFAULT NULL,
  `diverifikasi_pada`  DATETIME       DEFAULT NULL,
  `dibuat_pada`        TIMESTAMP      NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada`    TIMESTAMP      NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cost_avoidance_kode` (`kode`),
  KEY `idx_cost_avoidance_status` (`status`),
  KEY `idx_cost_avoidance_periode` (`periode`),
  KEY `idx_cost_avoidance_pembuat` (`dibuat_oleh`),
  KEY `idx_cost_avoidance_verifikator` (`diverifikasi_oleh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
