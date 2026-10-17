-- ============================================================
-- 004_ai_service.sql
-- Tabel untuk layanan AI (Python/FastAPI + Hermes Agent + Ollama):
--   chatbot asisten, antrean job AI, riwayat backup.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_sessions` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`       INT(11)   NOT NULL,
  `judul`         VARCHAR(120) DEFAULT 'Percakapan baru',
  `dibuat_pada`   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cs_user` (`user_id`,`diperbarui_pada`),
  CONSTRAINT `fk_cs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id`         BIGINT(20) NOT NULL AUTO_INCREMENT,
  `session_id` INT(11)    NOT NULL,
  `peran`      ENUM('user','assistant') NOT NULL,
  `isi`        MEDIUMTEXT NOT NULL,
  `tool_calls` JSON       DEFAULT NULL,
  `dibuat_pada` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cm_session` (`session_id`,`id`),
  CONSTRAINT `fk_cm_session` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_jobs` (
  `id`          CHAR(36)   NOT NULL,
  `user_id`     INT(11)    NOT NULL,
  `jenis`       VARCHAR(40) NOT NULL,
  `status`      ENUM('antre','proses','selesai','gagal') NOT NULL DEFAULT 'antre',
  `parameter`   JSON       DEFAULT NULL,
  `hasil`       JSON       DEFAULT NULL,
  `pesan_error` TEXT       DEFAULT NULL,
  `dibuat_pada` TIMESTAMP  NOT NULL DEFAULT current_timestamp(),
  `mulai_pada`  DATETIME   DEFAULT NULL,
  `selesai_pada` DATETIME  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job_user` (`user_id`,`dibuat_pada`),
  KEY `idx_job_status` (`status`,`dibuat_pada`),
  CONSTRAINT `fk_job_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_riwayat` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `nama_file`    VARCHAR(255) NOT NULL,
  `ukuran_bytes` BIGINT(20)   NOT NULL DEFAULT 0,
  `checksum`     VARCHAR(64)  DEFAULT NULL,
  `tipe`         ENUM('otomatis','manual') NOT NULL DEFAULT 'manual',
  `dibuat_oleh`  INT(11)      DEFAULT NULL,
  `dibuat_pada`  TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bk_user` (`dibuat_oleh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
