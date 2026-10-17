-- ============================================================
-- 019_akses_baca_ai.sql
-- Hak baca akun `bi_reader` untuk jalur baca layanan AI.
--
-- LATAR BELAKANG
--   Layanan AI (ai-service) menjalankan kueri baca memakai akun
--   baca-saja `bi_reader` bila `AI_DB_PREFER_READONLY=true`. Alat-alat
--   agen membaca tabel operasional (bukan view): laporan_harian,
--   absensi, users, kasus, jadwal, dan lain-lain.
--
--   Tabel `jadwal` (migrasi 018) belum diberi hak, sehingga kueri baca
--   pertama gagal dan layanan jatuh ke akun aplikasi (dicatat sebagai
--   peringatan). Migrasi ini melengkapinya agar jalur baca-saja berfungsi
--   dan tetap konsisten dengan hak yang sudah ada pada tabel inti.
--
-- CATATAN KEAMANAN
--   Hanya SELECT. Tanpa INSERT/UPDATE/DELETE. Operasi tulis oleh layanan
--   AI tetap memakai akun aplikasi (`pelaporan_app`), bukan `bi_reader`.
--   View Power BI di powerbi/sql/02_views.sql tetap menjadi antarmuka
--   utama untuk pelaporan analitik eksternal.
--
-- Idempoten: GRANT boleh dijalankan berulang.
-- Jalankan:  mysql -uroot pelaporan < database/migrations/019_akses_baca_ai.sql
-- ============================================================

SET NAMES utf8mb4;

-- Tabel dasar yang dibaca layanan AI (jalur baca-saja).
GRANT SELECT ON `pelaporan`.`users`          TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`laporan_harian` TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`absensi`        TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`kasus`          TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`kasus_pihak`    TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`jadwal`         TO 'bi_reader'@'localhost';

GRANT SELECT ON `pelaporan`.`users`          TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`laporan_harian` TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`absensi`        TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`kasus`          TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`kasus_pihak`    TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`jadwal`         TO 'bi_reader'@'%';

FLUSH PRIVILEGES;
