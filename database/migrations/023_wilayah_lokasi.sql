-- ============================================================
-- 023_wilayah_lokasi.sql
-- Pemetaan lokasi (peta) untuk SEMUA data:
--   * tabel master `wilayah` + `wilayah_alias` (nama -> koordinat)
--   * kolom lokasi pada absensi, laporan_harian, jadwal
--   * kolom hasil resolusi (garis_lintang/garis_bujur/wilayah_id)
--     pada srm_kegiatan & cid_program
--
-- Jalankan:  mysql -uroot pelaporan < database/migrations/023_wilayah_lokasi.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Master wilayah (kabupaten / kecamatan / desa) + koordinat
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wilayah` (
    `id`              INT(11)      NOT NULL AUTO_INCREMENT,
    `level`           ENUM('kabupaten','kecamatan','desa') NOT NULL,
    `nama`            VARCHAR(150) NOT NULL,
    `nama_norm`       VARCHAR(150) NOT NULL,
    `kabupaten`       VARCHAR(150) DEFAULT NULL,
    `kecamatan`       VARCHAR(150) DEFAULT NULL,
    `garis_lintang`   DECIMAL(10,7) DEFAULT NULL,
    `garis_bujur`     DECIMAL(10,7) DEFAULT NULL,
    `sumber`          VARCHAR(30)  DEFAULT NULL,
    `akurasi`         VARCHAR(20)  DEFAULT NULL,
    `diperbarui_pada` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wilayah` (`level`, `nama_norm`, `kabupaten`),
    KEY `idx_wilayah_norm` (`nama_norm`),
    KEY `idx_wilayah_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. Alias nama (memperbaiki ejaan yang berantakan)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wilayah_alias` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `varian_norm` VARCHAR(190) NOT NULL,
    `wilayah_id`  INT(11)      NOT NULL,
    `catatan`     VARCHAR(150) DEFAULT NULL,
    `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_alias` (`varian_norm`),
    KEY `idx_alias_wilayah` (`wilayah_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. Lokasi pada absensi (diisi karyawan, opsional)
-- ------------------------------------------------------------
ALTER TABLE `absensi`
    ADD COLUMN IF NOT EXISTS `lokasi_teks`    VARCHAR(191)  NULL AFTER `kegiatan_hari_ini`,
    ADD COLUMN IF NOT EXISTS `garis_lintang`  DECIMAL(10,7) NULL AFTER `lokasi_teks`,
    ADD COLUMN IF NOT EXISTS `garis_bujur`    DECIMAL(10,7) NULL AFTER `garis_lintang`,
    ADD COLUMN IF NOT EXISTS `wilayah_id`     INT(11)       NULL AFTER `garis_bujur`;

-- ------------------------------------------------------------
-- 4. Lokasi pada laporan harian (diisi karyawan, opsional)
-- ------------------------------------------------------------
ALTER TABLE `laporan_harian`
    ADD COLUMN IF NOT EXISTS `lokasi_teks`    VARCHAR(191)  NULL AFTER `kegiatan`,
    ADD COLUMN IF NOT EXISTS `garis_lintang`  DECIMAL(10,7) NULL AFTER `lokasi_teks`,
    ADD COLUMN IF NOT EXISTS `garis_bujur`    DECIMAL(10,7) NULL AFTER `garis_lintang`,
    ADD COLUMN IF NOT EXISTS `wilayah_id`     INT(11)       NULL AFTER `garis_bujur`;

-- ------------------------------------------------------------
-- 5. Koordinat pada jadwal (lokasi sudah ada sebagai teks)
-- ------------------------------------------------------------
ALTER TABLE `jadwal`
    ADD COLUMN IF NOT EXISTS `garis_lintang`  DECIMAL(10,7) NULL AFTER `lokasi`,
    ADD COLUMN IF NOT EXISTS `garis_bujur`    DECIMAL(10,7) NULL AFTER `garis_lintang`,
    ADD COLUMN IF NOT EXISTS `wilayah_id`     INT(11)       NULL AFTER `garis_bujur`;

-- ------------------------------------------------------------
-- 6. Koordinat hasil resolusi pada SRM (dari kolom kelurahan/kecamatan/kabupaten)
-- ------------------------------------------------------------
ALTER TABLE `srm_kegiatan`
    ADD COLUMN IF NOT EXISTS `wilayah_id`     INT(11)       NULL AFTER `dokumentasi`,
    ADD COLUMN IF NOT EXISTS `garis_lintang`  DECIMAL(10,7) NULL AFTER `wilayah_id`,
    ADD COLUMN IF NOT EXISTS `garis_bujur`    DECIMAL(10,7) NULL AFTER `garis_lintang`;

-- ------------------------------------------------------------
-- 7. Koordinat hasil resolusi pada CID (dari kolom desa/kecamatan/kabupaten)
-- ------------------------------------------------------------
ALTER TABLE `cid_program`
    ADD COLUMN IF NOT EXISTS `wilayah_id`     INT(11)       NULL AFTER `anggaran`,
    ADD COLUMN IF NOT EXISTS `garis_lintang`  DECIMAL(10,7) NULL AFTER `wilayah_id`,
    ADD COLUMN IF NOT EXISTS `garis_bujur`    DECIMAL(10,7) NULL AFTER `garis_lintang`;
