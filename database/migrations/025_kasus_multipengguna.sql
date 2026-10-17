-- ============================================================
-- 025_kasus_multipengguna.sql
-- (a) Indeks bantu pencarian baris "menunggu geokode" per tabel,
--     agar lanjutan geokode otomatis di peta.php tetap cepat.
-- (b) impor_sejarah: simpan daftar ID baris yang tersentuh supaya
--     sorotan data unggahan bisa dipanggil ulang dari riwayat.
-- (c) Modul Pelacakan Kasus menjadi multi-pengguna:
--       - kolom pembuat, penanggung jawab, kategori, sumber, target;
--       - kasus_status_log  : linimasa perubahan status/progres;
--       - kasus_penugasan    : anggota tim yang ditugaskan ke kasus.
-- Idempoten (IF NOT EXISTS). Aman dijalankan berulang.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- (a) Indeks "menunggu geokode" (garis_lintang IS NULL).
-- ------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_lap_geo_pending ON laporan_harian (garis_lintang, garis_bujur, id);
CREATE INDEX IF NOT EXISTS idx_abs_geo_pending ON absensi        (garis_lintang, garis_bujur, id);
CREATE INDEX IF NOT EXISTS idx_jdw_geo_pending ON jadwal         (garis_lintang, garis_bujur, id);
CREATE INDEX IF NOT EXISTS idx_kss_geo_pending ON kasus          (garis_lintang, garis_bujur, id);
CREATE INDEX IF NOT EXISTS idx_srm_geo_pending ON srm_kegiatan   (garis_lintang, garis_bujur, id);
CREATE INDEX IF NOT EXISTS idx_cid_geo_pending ON cid_program    (garis_lintang, garis_bujur, id);

-- ------------------------------------------------------------
-- (b) impor_sejarah: daftar ID baris tersentuh + sumber sorotan.
-- ------------------------------------------------------------
ALTER TABLE impor_sejarah
  ADD COLUMN IF NOT EXISTS `id_semua` TEXT         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sumber`   VARCHAR(40)  DEFAULT NULL;

-- ------------------------------------------------------------
-- (c) Modul kasus multi-pengguna.
-- ------------------------------------------------------------
ALTER TABLE kasus
  ADD COLUMN IF NOT EXISTS `dibuat_oleh`         INT(11)     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `penanggung_jawab_id` INT(11)     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `kategori`            VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sumber`              VARCHAR(20) NOT NULL DEFAULT 'Manual',
  ADD COLUMN IF NOT EXISTS `target_selesai`      DATE        DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `lampiran`            VARCHAR(255) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_kasus_dibuat_oleh ON kasus (dibuat_oleh);
CREATE INDEX IF NOT EXISTS idx_kasus_penanggung  ON kasus (penanggung_jawab_id);

CREATE TABLE IF NOT EXISTS `kasus_status_log` (
  `id`          INT(11)     NOT NULL AUTO_INCREMENT,
  `kasus_id`    INT(11)     NOT NULL,
  `user_id`     INT(11)     DEFAULT NULL,
  `status_lama` VARCHAR(30) DEFAULT NULL,
  `status_baru` VARCHAR(30) NOT NULL,
  `progres`     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `catatan`     VARCHAR(255) DEFAULT NULL,
  `dibuat_pada` TIMESTAMP   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kslog_kasus` (`kasus_id`, `dibuat_pada`),
  KEY `idx_kslog_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kasus_penugasan` (
  `id`             INT(11)     NOT NULL AUTO_INCREMENT,
  `kasus_id`       INT(11)     NOT NULL,
  `user_id`        INT(11)     NOT NULL,
  `peran`          VARCHAR(60) NOT NULL DEFAULT 'Anggota Tim',
  `ditugaskan_oleh` INT(11)    DEFAULT NULL,
  `dibuat_pada`    TIMESTAMP   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kasus_penugasan` (`kasus_id`, `user_id`),
  KEY `idx_kp_user`  (`user_id`),
  KEY `idx_kp_kasus` (`kasus_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
