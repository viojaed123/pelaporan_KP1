-- ============================================================
-- 022_cid_bigint.sql
-- Lebarkan kolom hitungan pada `cid_program` dari INT ke BIGINT.
-- Sebagian nilai penerima manfaat pada berkas sumber dapat melebihi
-- batas INT (2.147.483.647). Idempoten: aman dijalankan berulang.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `cid_program`
  MODIFY COLUMN `total_penerima_manfaat` BIGINT(20) DEFAULT NULL,
  MODIFY COLUMN `perempuan`              BIGINT(20) DEFAULT NULL,
  MODIFY COLUMN `laki_laki`              BIGINT(20) DEFAULT NULL,
  MODIFY COLUMN `ibu_hamil`              BIGINT(20) DEFAULT NULL,
  MODIFY COLUMN `balita`                 BIGINT(20) DEFAULT NULL,
  MODIFY COLUMN `disabilitas`            BIGINT(20) DEFAULT NULL,
  MODIFY COLUMN `penanaman_pohon`        BIGINT(20) DEFAULT NULL;
