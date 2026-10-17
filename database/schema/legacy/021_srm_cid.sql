-- ============================================================
-- 021_srm_cid.sql
-- Tabel dataset Social Relations Management (SRM) & Community
-- Involvement/Development (CID) — diimpor dari berkas Excel, lalu
-- diekspos ke Power BI lewat view (lihat powerbi/sql/02_views.sql).
--
-- Idempoten: aman dijalankan berulang (IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. SRM: catatan kegiatan relations / intervensi sosial
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `srm_kegiatan` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `tahun_intervensi`   SMALLINT(5)  DEFAULT NULL,
  `area_field`         VARCHAR(100) DEFAULT NULL,
  `tanggal`            DATE         DEFAULT NULL,
  `waktu`              VARCHAR(20)  DEFAULT NULL,
  `pic`                VARCHAR(150) DEFAULT NULL,
  `kategori_kegiatan`  VARCHAR(100) DEFAULT NULL,
  `klasifikasi_issue`  VARCHAR(200) DEFAULT NULL,
  `judul_issue`        VARCHAR(300) DEFAULT NULL,
  `detail_issue`       TEXT         DEFAULT NULL,
  `kelurahan`          VARCHAR(150) DEFAULT NULL,
  `kecamatan`          VARCHAR(150) DEFAULT NULL,
  `kabupaten_kota`     VARCHAR(150) DEFAULT NULL,
  `jenis_stakeholder`  VARCHAR(150) DEFAULT NULL,
  `stakeholder_hadir`  TEXT         DEFAULT NULL,
  `action_plan`        TEXT         DEFAULT NULL,
  `tim_phr`            VARCHAR(200) DEFAULT NULL,
  `value_creation`     VARCHAR(255) DEFAULT NULL,
  `status`             VARCHAR(20)  DEFAULT NULL,
  `persen_status`      DECIMAL(6,2) DEFAULT NULL,
  `dokumentasi`        VARCHAR(255) DEFAULT NULL,
  `baris_sumber`       INT(11)      DEFAULT NULL,
  `dibuat_pada`        TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_srm_tahun` (`tahun_intervensi`),
  KEY `idx_srm_area` (`area_field`),
  KEY `idx_srm_tanggal` (`tanggal`),
  KEY `idx_srm_status` (`status`),
  KEY `idx_srm_kabupaten` (`kabupaten_kota`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. CID: program Community Involvement/Development (TJSL)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cid_program` (
  `id`                              INT(11)       NOT NULL AUTO_INCREMENT,
  `tahun_intervensi`                SMALLINT(5)   DEFAULT NULL,
  `judul_program`                   VARCHAR(200)  DEFAULT NULL,
  `pilar_program`                   VARCHAR(100)  DEFAULT NULL,
  `desa`                            VARCHAR(150)  DEFAULT NULL,
  `desa_aset_operasi`               VARCHAR(150)  DEFAULT NULL,
  `desa_rk_bor`                     VARCHAR(150)  DEFAULT NULL,
  `desa_konflik_sosial`             VARCHAR(150)  DEFAULT NULL,
  `kode_desa`                       VARCHAR(30)   DEFAULT NULL,
  `kecamatan`                       VARCHAR(150)  DEFAULT NULL,
  `kode_kecamatan`                  VARCHAR(30)   DEFAULT NULL,
  `kabupaten_kota`                  VARCHAR(150)  DEFAULT NULL,
  `kode_kabupaten_kota`             VARCHAR(30)   DEFAULT NULL,
  `kelompok_diberdayakan`           VARCHAR(200)  DEFAULT NULL,
  `total_penerima_manfaat`          BIGINT(20)    DEFAULT NULL,
  `perempuan`                       BIGINT(20)    DEFAULT NULL,
  `laki_laki`                       BIGINT(20)    DEFAULT NULL,
  `ibu_hamil`                       BIGINT(20)    DEFAULT NULL,
  `balita`                          BIGINT(20)    DEFAULT NULL,
  `disabilitas`                     BIGINT(20)    DEFAULT NULL,
  `potensi_peningkatan_ekonomi`     DECIMAL(18,2) DEFAULT NULL,
  `potensi_penghematan_operasional` DECIMAL(18,2) DEFAULT NULL,
  `penanaman_pohon`                 BIGINT(20)    DEFAULT NULL,
  `luas_penanaman_ha`               DECIMAL(12,2) DEFAULT NULL,
  `sampah_terkelola_kg`             DECIMAL(12,2) DEFAULT NULL,
  `peningkatan_kualitas_kesehatan`  TINYINT(1)    DEFAULT NULL,
  `penyerapan_lapangan_kerja`       TINYINT(1)    DEFAULT NULL,
  `peningkatan_kapasitas`           TINYINT(1)    DEFAULT NULL,
  `pembentukan_kelompok_baru`       TINYINT(1)    DEFAULT NULL,
  `kerjasama_stakeholder`           VARCHAR(200)  DEFAULT NULL,
  `bulan`                           VARCHAR(15)   DEFAULT NULL,
  `bulan_angka`                     TINYINT(2)    DEFAULT NULL,
  `program`                         VARCHAR(150)  DEFAULT NULL,
  `anggaran`                        DECIMAL(18,2) DEFAULT NULL,
  `baris_sumber`                    INT(11)       DEFAULT NULL,
  `dibuat_pada`                     TIMESTAMP     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cid_tahun` (`tahun_intervensi`),
  KEY `idx_cid_pilar` (`pilar_program`),
  KEY `idx_cid_kabupaten` (`kabupaten_kota`),
  KEY `idx_cid_kecamatan` (`kecamatan`),
  KEY `idx_cid_bulan` (`bulan_angka`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
