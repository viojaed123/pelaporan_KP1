-- ============================================================
-- 002_keamanan_login.sql
-- Tabel pendukung penguatan keamanan login:
--   * pencatatan & pembatasan percobaan login (rate limit / lockout)
--   * 2FA OTP via email + kode cadangan
--   * riwayat kata sandi
--   * sesi aktif (perangkat)
-- ============================================================

SET NAMES utf8mb4;

-- Percobaan login (audit + sumber hitungan rate limit)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(150) DEFAULT NULL,
  `user_id`     INT(11)      DEFAULT NULL,
  `ip_address`  VARCHAR(45)  NOT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `berhasil`    TINYINT(1)   NOT NULL DEFAULT 0,
  `alasan`      VARCHAR(60)  DEFAULT NULL,
  `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_la_email` (`email`,`dibuat_pada`),
  KEY `idx_la_ip` (`ip_address`,`dibuat_pada`),
  KEY `idx_la_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kunci sementara akibat terlalu banyak kegagalan
CREATE TABLE IF NOT EXISTS `login_locks` (
  `kunci`         VARCHAR(190) NOT NULL,
  `gagal`         INT(11)      NOT NULL DEFAULT 0,
  `terkunci_sampai` DATETIME   DEFAULT NULL,
  `percobaan_pertama` DATETIME NOT NULL DEFAULT current_timestamp(),
  `diperbarui`    TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status keamanan per pengguna
CREATE TABLE IF NOT EXISTS `user_security` (
  `user_id`                  INT(11) NOT NULL,
  `dua_faktor_aktif`         TINYINT(1) NOT NULL DEFAULT 0,
  `password_diubah_pada`     DATETIME DEFAULT NULL,
  `ingat_token_hash`         VARCHAR(64)  DEFAULT NULL,
  `ingat_kedaluwarsa`        DATETIME     DEFAULT NULL,
  `ingat_ip`                 VARCHAR(45)  DEFAULT NULL,
  `gagal_login_berturut`     INT(11)      NOT NULL DEFAULT 0,
  `terakhir_kunci`           DATETIME     DEFAULT NULL,
  `dibuat_pada`              TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada`          TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_sec_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OTP 2FA (kode disimpan ter-hash, sekali pakai, berumur pendek)
CREATE TABLE IF NOT EXISTS `user_otp` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `kode_hash`   VARCHAR(255) NOT NULL,
  `tujuan`      VARCHAR(150) NOT NULL,
  `percobaan`   TINYINT(4)   NOT NULL DEFAULT 0,
  `dipakai`     TINYINT(1)   NOT NULL DEFAULT 0,
  `kedaluwarsa` DATETIME     NOT NULL,
  `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`,`kedaluwarsa`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kode cadangan 2FA (10 kode, sekali pakai)
CREATE TABLE IF NOT EXISTS `backup_codes` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `kode_hash`   VARCHAR(255) NOT NULL,
  `dipakai`     TINYINT(1)   NOT NULL DEFAULT 0,
  `dipakai_pada` DATETIME    DEFAULT NULL,
  `dibuat_pada` TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bc_user` (`user_id`),
  CONSTRAINT `fk_bc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat kata sandi (mencegah pengulangan 3 terakhir)
CREATE TABLE IF NOT EXISTS `password_history` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`        INT(11)      NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `dibuat_pada`    TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ph_user` (`user_id`,`dibuat_pada`),
  CONSTRAINT `fk_ph_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesi aktif per perangkat (pendukung batas sesi & pencabutan)
CREATE TABLE IF NOT EXISTS `sesi_aktif` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`        INT(11)      NOT NULL,
  `sesi_hash`      VARCHAR(64)  NOT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `user_agent`     VARCHAR(255) DEFAULT NULL,
  `dicabut`        TINYINT(1)   NOT NULL DEFAULT 0,
  `dibuat_pada`    TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `terakhir_aktif` TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sesi_hash` (`sesi_hash`),
  KEY `idx_sesi_user` (`user_id`,`dicabut`),
  CONSTRAINT `fk_sesi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
