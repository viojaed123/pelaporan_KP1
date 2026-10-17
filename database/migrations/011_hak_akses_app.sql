-- ============================================================
-- 011_hak_akses_app.sql
-- Penyesuaian hak akses akun aplikasi (`pelaporan_app`).
--
-- LATAR BELAKANG
--   Migrasi 010 memberi `pelaporan_app` hanya DML
--   (SELECT/INSERT/UPDATE/DELETE). Ternyata aplikasi juga perlu:
--     * DDL  -> fitur RESTORE cadangan mengeksekusi
--               DROP TABLE / CREATE TABLE dari berkas .sql
--     * SHOW VIEW, LOCK TABLES, TRIGGER, EVENT
--            -> `backup.php` memanggil mysqldump dengan
--               --single-transaction --routines --events
--
-- KEPUTUSAN
--   Berikan ALL PRIVILEGES TETAPI HANYA pada basis data
--   `pelaporan` (bukan global). Akun ini tetap TIDAK punya
--   FILE, SUPER, PROCESS, akses ke basis data lain, maupun
--   GRANT OPTION — sehingga jauh lebih terbatas daripada `root`.
--
-- Jalankan setelah 010_user_db.sql.
-- ============================================================

GRANT ALL PRIVILEGES ON `pelaporan`.* TO 'pelaporan_app'@'localhost';
GRANT ALL PRIVILEGES ON `pelaporan`.* TO 'pelaporan_app'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Catatan: akun `bi_reader` TIDAK diubah di sini. Ia tetap
-- hanya-baca dan hanya diberi SELECT pada view (lihat
-- powerbi/sql/02_views.sql).
