-- ============================================================
-- 024_geokode_cache.sql
-- Cache hasil geokode alamat bebas (OpenStreetMap Nominatim).
--
-- Tujuan:
--   * Menghindari permintaan Nominatim berulang untuk kueri yang sama
--     (Nominatim membatasi 1 permintaan/detik).
--   * Menyimpan hasil "kosong" (lat/lng NULL) sebagai cache negatif agar
--     kueri yang gagal tidak dicoba terus-menerus.
--
-- Jalankan:  mysql -uroot pelaporan < database/migrations/024_geokode_cache.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `geokode_cache` (
    `id`              INT(11)       NOT NULL AUTO_INCREMENT,
    `kueri`           VARCHAR(191)  NOT NULL,
    `kueri_norm`      VARCHAR(191)  NOT NULL,
    `garis_lintang`   DECIMAL(10,7) DEFAULT NULL,
    `garis_bujur`     DECIMAL(10,7) DEFAULT NULL,
    `tampilan`        VARCHAR(255)  DEFAULT NULL,
    `sumber`          VARCHAR(20)   DEFAULT NULL,
    `dibuat_pada`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_geokode_norm` (`kueri_norm`),
    KEY `idx_geokode_koord` (`garis_lintang`, `garis_bujur`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
