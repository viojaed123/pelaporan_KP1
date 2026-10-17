-- ============================================================
-- 001_skema_awal.sql
-- Rekonstruksi skema asli database `pelaporan`
-- (diasumsikan sudah ada; file ini untuk instalasi baru / disaster recovery)
-- Diverifikasi terhadap MariaDB 10.4.32 pada XAMPP.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `nama`       VARCHAR(100)  NOT NULL,
  `email`      VARCHAR(150)  NOT NULL,
  `password`   VARCHAR(255)  NOT NULL,
  `role`       ENUM('admin','karyawan','mentor') NOT NULL DEFAULT 'karyawan',
  `divisi`     VARCHAR(100)  DEFAULT NULL,
  `created_at` TIMESTAMP     NOT NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `laporan_harian` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`            INT(11)      NOT NULL,
  `tanggal`            DATE         NOT NULL,
  `jam_mulai`          TIME         DEFAULT NULL,
  `jam_selesai`        TIME         DEFAULT NULL,
  `kegiatan`           VARCHAR(255) NOT NULL,
  `deskripsi`          TEXT         NOT NULL,
  `hasil`              TEXT         DEFAULT NULL,
  `kendala`            TEXT         DEFAULT NULL,
  `rencana_berikutnya` TEXT         DEFAULT NULL,
  `status`             ENUM('draft','dikirim','diperiksa','perlu_revisi') NOT NULL DEFAULT 'draft',
  `catatan_mentor`     TEXT         DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `updated_at`         TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_laporan_user` (`user_id`),
  KEY `idx_laporan_status` (`status`),
  KEY `idx_laporan_tanggal` (`tanggal`),
  CONSTRAINT `fk_laporan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bukti_gambar` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `laporan_id`    INT(11)          NOT NULL,
  `nama_file`     VARCHAR(255)     NOT NULL,
  `nama_asli`     VARCHAR(255)     NOT NULL,
  `tipe_mime`     VARCHAR(100)     NOT NULL,
  `ukuran_bytes`  INT(10) UNSIGNED NOT NULL,
  `uploaded_at`   TIMESTAMP        NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_bukti_laporan` (`laporan_id`),
  CONSTRAINT `fk_bukti_laporan` FOREIGN KEY (`laporan_id`) REFERENCES `laporan_harian` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `absensi` (
  `id`         INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)   NOT NULL,
  `tanggal`    DATE      NOT NULL,
  `jam_masuk`  TIME      DEFAULT NULL,
  `jam_pulang` TIME      DEFAULT NULL,
  `status`     ENUM('hadir','izin','sakit','alpa') NOT NULL DEFAULT 'hadir',
  `keterangan` TEXT      DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unik_absensi_harian` (`user_id`,`tanggal`),
  KEY `idx_absensi_tanggal` (`tanggal`),
  KEY `idx_absensi_user` (`user_id`),
  CONSTRAINT `fk_absensi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifikasi` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `laporan_id`  INT(11)      DEFAULT NULL,
  `pesan`       VARCHAR(500) NOT NULL,
  `link`        VARCHAR(255) DEFAULT 'riwayat.php',
  `sudah_dibaca` TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifikasi_user` (`user_id`),
  KEY `idx_notifikasi_laporan` (`laporan_id`),
  CONSTRAINT `fk_notifikasi_laporan` FOREIGN KEY (`laporan_id`) REFERENCES `laporan_harian` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notifikasi_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Modul Kasus (terpisah dari laporan harian) ----------

CREATE TABLE IF NOT EXISTS `kasus` (
  `id`               INT(11)  NOT NULL AUTO_INCREMENT,
  `kode_kasus`       VARCHAR(30)  NOT NULL,
  `judul`            VARCHAR(200) NOT NULL,
  `deskripsi`        TEXT         DEFAULT NULL,
  `nama_lokasi`      VARCHAR(200) DEFAULT NULL,
  `garis_lintang`    DECIMAL(10,7) DEFAULT NULL,
  `garis_bujur`      DECIMAL(10,7) DEFAULT NULL,
  `tingkat_urgensi`  ENUM('Rendah','Sedang','Tinggi','Kritis') NOT NULL DEFAULT 'Sedang',
  `status_kasus`     ENUM('Terbuka','Dalam Proses','Selesai')  NOT NULL DEFAULT 'Terbuka',
  `progres`          TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `dibuat_pada`      TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada`  TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `case_code` (`kode_kasus`),
  KEY `idx_kasus_status` (`status_kasus`),
  KEY `idx_kasus_urgensi` (`tingkat_urgensi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pihak_terlibat` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `nama_lengkap` VARCHAR(150) NOT NULL,
  `organisasi`   VARCHAR(200) DEFAULT NULL,
  `email`        VARCHAR(150) DEFAULT NULL,
  `telepon`      VARCHAR(50)  DEFAULT NULL,
  `jenis_pihak`  ENUM('Internal','Eksternal') NOT NULL,
  `dibuat_pada`  TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kasus_pihak` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `kasus_id`            INT(11)      NOT NULL,
  `pihak_id`            INT(11)      NOT NULL,
  `peran_partisipasi`   VARCHAR(100) DEFAULT 'Peserta',
  `status_partisipasi`  VARCHAR(50)  DEFAULT 'Aktif',
  `ditugaskan_pada`     TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kp_case_person` (`kasus_id`,`pihak_id`),
  KEY `fk_kasus_pihak_orang` (`pihak_id`),
  CONSTRAINT `fk_kasus_pihak_kasus` FOREIGN KEY (`kasus_id`) REFERENCES `kasus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kasus_pihak_orang` FOREIGN KEY (`pihak_id`) REFERENCES `pihak_terlibat` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `riwayat_tindak_lanjut` (
  `id`                    INT(11)   NOT NULL AUTO_INCREMENT,
  `kasus_id`              INT(11)   NOT NULL,
  `user_id`               INT(11)   DEFAULT NULL,
  `tindakan`              TEXT      NOT NULL,
  `hasil`                 TEXT      DEFAULT NULL,
  `kendala`               TEXT      DEFAULT NULL,
  `rencana_selanjutnya`   TEXT      DEFAULT NULL,
  `tanggal_tindak_lanjut` DATETIME  NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_riwayat_kasus` (`kasus_id`),
  KEY `fk_riwayat_user` (`user_id`),
  CONSTRAINT `fk_riwayat_kasus` FOREIGN KEY (`kasus_id`) REFERENCES `kasus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_riwayat_user`   FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
