-- ============================================================
-- Dimensi Tanggal (star schema Power BI)
-- ============================================================
--
-- PERBAIKAN COLLATION (2026-10-19)
--
-- Definisi lama membandingkan literali string tanggal langsung:
--     WHERE '2024-01-01' + INTERVAL angka.n day <= '2030-12-31'
-- Literali string itu "membekukan" collation utf8mb4_general_ci (hasil
-- sesi koneksi legacy mysqli `SET NAMES utf8mb4` saat view dibuat),
-- sedangkan server kini memakai utf8mb4_unicode_ci. Akibatnya SEMUA query
-- terhadap view ini (dan dependennya: vw_kepatuhan_harian) gagal dengan:
--     Illegal mix of collations ... for operation '<='
-- sehingga dimensi tanggal tidak terpakai di Power BI / ODBC / ekspor xlsx.
--
-- Definisi baru memakai literal DATE eksplisit (CAST ... AS DATE):
-- perbandingan murni DATE vs DATE -- tidak melibatkan collation sama sekali.
-- Nama kolom, urutan, dan tipe dipertahankan 1:1 (dependen aman).
-- Struktur derived table juga dirapikan agar sah di MariaDB (tanpa
-- alias pada perspektif JOIN berkurung).
--
-- Rentang tidak berubah: 2024-01-01 s/d 2030-12-31 (2.557 baris).
-- Aman dijalankan berulang (CREATE OR REPLACE); tidak menyentuh tabel data.

CREATE OR REPLACE VIEW vw_dim_tanggal AS
SELECT
    d.tanggal                                                        AS tanggal,
    YEAR(d.tanggal)                                                  AS tahun,
    MONTH(d.tanggal)                                                 AS bulan,
    ELT(MONTH(d.tanggal),
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember') AS nama_bulan,
    QUARTER(d.tanggal)                                               AS kuartal,
    WEEK(d.tanggal, 3)                                               AS minggu_iso,
    ELT(DAYOFWEEK(d.tanggal),
        'Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu')   AS nama_hari,
    DAYOFWEEK(d.tanggal)                                             AS hari_ke,
    CASE WHEN DAYOFWEEK(d.tanggal) IN (1, 7) THEN 1 ELSE 0 END       AS flag_akhir_pekan,
    DATE_FORMAT(d.tanggal, '%Y-%m')                                  AS periode_bulan
FROM (
    SELECT CAST('2024-01-01' AS DATE) + INTERVAL angka.n DAY          AS tanggal
    FROM (
        SELECT a.n + b.n * 10 + c.n * 100 + e.n * 1000                AS n
        FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
              UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
              UNION ALL SELECT 8 UNION ALL SELECT 9) a
        JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
              UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
              UNION ALL SELECT 8 UNION ALL SELECT 9) b
        JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
              UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
              UNION ALL SELECT 8 UNION ALL SELECT 9) c
        JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2) e
    ) angka
    WHERE CAST('2024-01-01' AS DATE) + INTERVAL angka.n DAY <= CAST('2030-12-31' AS DATE)
) d;
