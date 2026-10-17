-- ============================================================
-- 010_user_db.sql
-- Pembuatan akun database ber-privilege minimum.
--
-- SEBELUM: aplikasi memakai `root` tanpa password (koneksi.php).
-- SESUDAH : `pelaporan_app` (DML pada tabel operasional) + `bi_reader`
--           (hanya SELECT pada VIEW untuk Power BI).
--
-- Jalankan file ini SEBELUM memperbarui kredensial di koneksi.php,
-- lalu uji aplikasi, baru hapus/ batasi akun root dari remote.
--
-- PENTING: nilai 'UBAH_SANDI_APP_INI' / 'UBAH_SANDI_BI_INI' di bawah
-- hanyalah penampung. Samakan dengan:
--   · DB_PASS                  (includes/config.php)
--   · AI_DB_APP_PASSWORD       (ai-service/.env)
--   · AI_DB_READONLY_PASSWORD  (ai-service/.env)
-- Jangan menaruh sandi asli di berkas migrasi/dokumentasi.
-- ============================================================

-- 1) Akun aplikasi ----------------------------------------------------
CREATE USER IF NOT EXISTS 'pelaporan_app'@'localhost'
  IDENTIFIED BY 'UBAH_SANDI_APP_INI';
CREATE USER IF NOT EXISTS 'pelaporan_app'@'127.0.0.1'
  IDENTIFIED BY 'UBAH_SANDI_APP_INI';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON `pelaporan`.* TO 'pelaporan_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE
  ON `pelaporan`.* TO 'pelaporan_app'@'127.0.0.1';

-- 2) Akun Power BI (read-only, khusus VIEW) ---------------------------
CREATE USER IF NOT EXISTS 'bi_reader'@'%' IDENTIFIED BY 'UBAH_SANDI_BI_INI';
CREATE USER IF NOT EXISTS 'bi_reader'@'localhost' IDENTIFIED BY 'UBAH_SANDI_BI_INI';

-- GRANT per-VIEW dicantumkan eksplisit di:
--   powerbi/sql/02_views.sql  (MariaDB tidak menerima pola `db.vw_*`
--                              pada perintah GRANT, jadi diberikan per nama).

FLUSH PRIVILEGES;
