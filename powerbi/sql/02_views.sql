-- ============================================================
-- 02_views.sql
-- Lapisan VIEW khusus Power BI (dan pelaporan analitik lainnya).
--
-- Tujuan:
--   * Power BI (mode Import) hanya membaca VIEW yang stabil & sudah
--     diagregasi — bukan tabel operasional mentah.
--   * Akun `bi_reader` HANYA diberi SELECT pada VIEW (read-only).
--     Karena VIEW di bawah memakai SQL SECURITY DEFINER, `bi_reader`
--     tidak memerlukan hak akses apa pun ke tabel dasar.
--
-- PENTING (MariaDB 10.4):
--   Perintah `GRANT SELECT ON pelaporan.vw_*` TIDAK didukung
--   (error 1064). Hak akses diberikan PER NAMA VIEW secara eksplisit
--   di bagian akhir berkas ini.
--
-- Jalankan:  mysql -uroot pelaporan < powerbi/sql/02_views.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. DIMENSI: Pengguna
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_dim_pengguna` AS
SELECT
    u.`id`                       AS `pengguna_id`,
    u.`nama`,
    u.`email`,
    u.`role`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    u.`created_at`               AS `terdaftar_pada`
FROM `users` u;

-- ------------------------------------------------------------
-- 2. FAKTA: Laporan kegiatan harian
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_laporan_harian` AS
SELECT
    l.`id`                       AS `laporan_id`,
    l.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    l.`tanggal`,
    YEAR(l.`tanggal`)            AS `tahun`,
    MONTH(l.`tanggal`)           AS `bulan`,
    ELT(MONTH(l.`tanggal`), 'Januari','Februari','Maret','April','Mei','Juni',
        'Juli','Agustus','September','Oktober','November','Desember') AS `nama_bulan`,
    QUARTER(l.`tanggal`)         AS `kuartal`,
    WEEK(l.`tanggal`, 3)         AS `minggu_iso`,
    ELT(DAYOFWEEK(l.`tanggal`), 'Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu') AS `nama_hari`,
    DAYOFWEEK(l.`tanggal`)       AS `hari_ke`,
    l.`jam_mulai`,
    l.`jam_selesai`,
    CASE
        WHEN l.`jam_mulai` IS NOT NULL AND l.`jam_selesai` IS NOT NULL
        THEN TIMESTAMPDIFF(MINUTE, l.`jam_mulai`, l.`jam_selesai`)
        ELSE NULL
    END                          AS `durasi_menit`,
    l.`kegiatan`,
    l.`status`,
    CASE l.`status`
        WHEN 'draft'        THEN 'Draf'
        WHEN 'dikirim'      THEN 'Dikirim'
        WHEN 'diperiksa'    THEN 'Diperiksa'
        WHEN 'perlu_revisi' THEN 'Perlu Revisi'
        ELSE l.`status`
    END                          AS `label_status`,
    CASE WHEN l.`kendala` IS NOT NULL AND TRIM(l.`kendala`) <> '' THEN 1 ELSE 0 END
                                 AS `ada_kendala`,
    CASE WHEN l.`catatan_mentor` IS NOT NULL AND TRIM(l.`catatan_mentor`) <> '' THEN 1 ELSE 0 END
                                 AS `ada_catatan_mentor`,
    CHAR_LENGTH(COALESCE(l.`deskripsi`, '')) AS `panjang_deskripsi`,
    l.`lokasi_teks`,
    l.`garis_lintang`,
    l.`garis_bujur`,
    l.`wilayah_id`,
    CASE
        WHEN l.`garis_lintang` IS NOT NULL AND l.`garis_bujur` IS NOT NULL
        THEN CONCAT('https://www.google.com/maps/search/?api=1&query=',
                    REPLACE(l.`garis_lintang`, ',', '.'), ',',
                    REPLACE(l.`garis_bujur`, ',', '.'))
        ELSE NULL
    END                          AS `peta_url`,
    l.`created_at`               AS `dibuat_pada`,
    l.`updated_at`               AS `diperbarui_pada`
FROM `laporan_harian` l
LEFT JOIN `users` u ON u.`id` = l.`user_id`;

-- ------------------------------------------------------------
-- 3. FAKTA: Absensi
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_absensi` AS
SELECT
    a.`id`                       AS `absensi_id`,
    a.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    a.`tanggal`,
    YEAR(a.`tanggal`)            AS `tahun`,
    MONTH(a.`tanggal`)           AS `bulan`,
    WEEK(a.`tanggal`, 3)         AS `minggu_iso`,
    ELT(DAYOFWEEK(a.`tanggal`), 'Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu') AS `nama_hari`,
    a.`jam_masuk`,
    a.`jam_pulang`,
    CASE
        WHEN a.`jam_masuk` IS NOT NULL AND a.`jam_pulang` IS NOT NULL
        THEN TIMESTAMPDIFF(MINUTE, a.`jam_masuk`, a.`jam_pulang`)
        ELSE NULL
    END                          AS `durasi_kerja_menit`,
    a.`status`,
    CASE a.`status`
        WHEN 'hadir' THEN 'Hadir'
        WHEN 'izin'  THEN 'Izin'
        WHEN 'sakit' THEN 'Sakit'
        WHEN 'alpa'  THEN 'Alpa'
        ELSE a.`status`
    END                          AS `label_status`,
    CASE WHEN a.`status` = 'hadir' THEN 1 ELSE 0 END AS `flag_hadir`,
    a.`keterangan`,
    a.`kegiatan_hari_ini`,
    a.`kondisi_kesehatan`,
    CASE a.`kondisi_kesehatan`
        WHEN 'sehat'        THEN 'Sehat'
        WHEN 'kurang_sehat' THEN 'Kurang Sehat'
        WHEN 'sakit'        THEN 'Sakit'
        WHEN 'pemulihan'    THEN 'Pemulihan'
        ELSE a.`kondisi_kesehatan`
    END                          AS `label_kondisi_kesehatan`,
    CASE WHEN a.`kondisi_kesehatan` = 'sehat' THEN 1 ELSE 0 END AS `flag_sehat`,
    a.`lokasi_teks`,
    a.`garis_lintang`,
    a.`garis_bujur`,
    a.`wilayah_id`,
    CASE
        WHEN a.`garis_lintang` IS NOT NULL AND a.`garis_bujur` IS NOT NULL
        THEN CONCAT('https://www.google.com/maps/search/?api=1&query=',
                    REPLACE(a.`garis_lintang`, ',', '.'), ',',
                    REPLACE(a.`garis_bujur`, ',', '.'))
        ELSE NULL
    END                          AS `peta_url`,
    a.`created_at`               AS `dibuat_pada`
FROM `absensi` a
LEFT JOIN `users` u ON u.`id` = a.`user_id`;

-- ------------------------------------------------------------
-- 4. FAKTA: Kasus / pelacakan
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_kasus` AS
SELECT
    k.`id`                       AS `kasus_id`,
    k.`kode_kasus`,
    k.`judul`,
    k.`nama_lokasi`,
    k.`tingkat_urgensi`,
    k.`status_kasus`,
    k.`progres`,
    CASE
        WHEN k.`status_kasus` = 'Selesai' THEN 1 ELSE 0
    END                          AS `flag_selesai`,
    CASE
        WHEN k.`tingkat_urgensi` IN ('Tinggi', 'Kritis')
         AND k.`status_kasus` <> 'Selesai'
        THEN 1 ELSE 0
    END                          AS `flag_perlu_perhatian`,
    k.`garis_lintang`,
    k.`garis_bujur`,
    DATE(k.`dibuat_pada`)        AS `tanggal_dibuat`,
    YEAR(k.`dibuat_pada`)        AS `tahun`,
    MONTH(k.`dibuat_pada`)       AS `bulan`,
    k.`dibuat_pada`,
    k.`diperbarui_pada`,
    TIMESTAMPDIFF(DAY, k.`dibuat_pada`, IFNULL(k.`diperbarui_pada`, NOW())) AS `umur_hari`,
    (SELECT COUNT(*) FROM `kasus_pihak` kp WHERE kp.`kasus_id` = k.`id`) AS `jumlah_pihak`
FROM `kasus` k;

-- ------------------------------------------------------------
-- 5. AGREGAT: Ringkasan kegiatan per karyawan per hari
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_ringkasan_harian` AS
SELECT
    l.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    l.`tanggal`,
    YEAR(l.`tanggal`)            AS `tahun`,
    MONTH(l.`tanggal`)           AS `bulan`,
    WEEK(l.`tanggal`, 3)         AS `minggu_iso`,
    COUNT(*)                     AS `jumlah_laporan`,
    SUM(CASE WHEN l.`status` = 'diperiksa' THEN 1 ELSE 0 END)    AS `jumlah_diperiksa`,
    SUM(CASE WHEN l.`status` = 'perlu_revisi' THEN 1 ELSE 0 END) AS `jumlah_perlu_revisi`,
    SUM(CASE WHEN l.`status` = 'dikirim' THEN 1 ELSE 0 END)      AS `jumlah_dikirim`,
    SUM(CASE WHEN l.`status` = 'draft' THEN 1 ELSE 0 END)        AS `jumlah_draft`,
    SUM(CASE
            WHEN l.`jam_mulai` IS NOT NULL AND l.`jam_selesai` IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, l.`jam_mulai`, l.`jam_selesai`)
            ELSE 0
        END)                     AS `total_durasi_menit`,
    SUM(CASE WHEN l.`kendala` IS NOT NULL AND TRIM(l.`kendala`) <> '' THEN 1 ELSE 0 END)
                                 AS `jumlah_ada_kendala`
FROM `laporan_harian` l
LEFT JOIN `users` u ON u.`id` = l.`user_id`
GROUP BY l.`user_id`, u.`nama`, u.`divisi`,
         l.`tanggal`, YEAR(l.`tanggal`), MONTH(l.`tanggal`), WEEK(l.`tanggal`, 3);

-- ------------------------------------------------------------
-- 6. AGREGAT: Rekap bulanan per karyawan
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_rekap_bulanan` AS
SELECT
    l.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    YEAR(l.`tanggal`)            AS `tahun`,
    MONTH(l.`tanggal`)           AS `bulan`,
    DATE_FORMAT(l.`tanggal`, '%Y-%m') AS `periode`,
    COUNT(*)                     AS `jumlah_laporan`,
    COUNT(DISTINCT l.`tanggal`)  AS `jumlah_hari_aktif`,
    SUM(CASE
            WHEN l.`jam_mulai` IS NOT NULL AND l.`jam_selesai` IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, l.`jam_mulai`, l.`jam_selesai`)
            ELSE 0
        END)                     AS `total_durasi_menit`,
    ROUND(AVG(CASE
            WHEN l.`jam_mulai` IS NOT NULL AND l.`jam_selesai` IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, l.`jam_mulai`, l.`jam_selesai`)
            ELSE NULL
        END), 1)                 AS `rata_durasi_menit`,
    SUM(CASE WHEN l.`status` = 'diperiksa' THEN 1 ELSE 0 END)    AS `jumlah_diperiksa`,
    SUM(CASE WHEN l.`status` = 'perlu_revisi' THEN 1 ELSE 0 END) AS `jumlah_perlu_revisi`
FROM `laporan_harian` l
LEFT JOIN `users` u ON u.`id` = l.`user_id`
GROUP BY l.`user_id`, u.`nama`, u.`divisi`,
         YEAR(l.`tanggal`), MONTH(l.`tanggal`), DATE_FORMAT(l.`tanggal`, '%Y-%m');

-- ------------------------------------------------------------
-- 7. AGREGAT: Kehadiran bulanan per karyawan
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_kehadiran_bulanan` AS
SELECT
    a.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    YEAR(a.`tanggal`)            AS `tahun`,
    MONTH(a.`tanggal`)           AS `bulan`,
    DATE_FORMAT(a.`tanggal`, '%Y-%m') AS `periode`,
    COUNT(*)                     AS `jumlah_catatan`,
    SUM(CASE WHEN a.`status` = 'hadir' THEN 1 ELSE 0 END) AS `jumlah_hadir`,
    SUM(CASE WHEN a.`status` = 'izin'  THEN 1 ELSE 0 END) AS `jumlah_izin`,
    SUM(CASE WHEN a.`status` = 'sakit' THEN 1 ELSE 0 END) AS `jumlah_sakit`,
    SUM(CASE WHEN a.`status` = 'alpa'  THEN 1 ELSE 0 END) AS `jumlah_alpa`,
    ROUND(100 * SUM(CASE WHEN a.`status` = 'hadir' THEN 1 ELSE 0 END) / COUNT(*), 1)
                                 AS `persen_hadir`,
    SUM(CASE WHEN a.`kondisi_kesehatan` = 'sehat'        THEN 1 ELSE 0 END) AS `jumlah_sehat`,
    SUM(CASE WHEN a.`kondisi_kesehatan` = 'kurang_sehat' THEN 1 ELSE 0 END) AS `jumlah_kurang_sehat`,
    SUM(CASE WHEN a.`kondisi_kesehatan` = 'sakit'        THEN 1 ELSE 0 END) AS `jumlah_sakit_kesehatan`,
    SUM(CASE WHEN a.`kondisi_kesehatan` = 'pemulihan'    THEN 1 ELSE 0 END) AS `jumlah_pemulihan`
FROM `absensi` a
LEFT JOIN `users` u ON u.`id` = a.`user_id`
GROUP BY a.`user_id`, u.`nama`, u.`divisi`,
         YEAR(a.`tanggal`), MONTH(a.`tanggal`), DATE_FORMAT(a.`tanggal`, '%Y-%m');

-- ------------------------------------------------------------
-- 8. AGREGAT: Produktivitas mingguan (ISO week)
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_produktivitas_mingguan` AS
SELECT
    l.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    YEAR(l.`tanggal`)            AS `tahun`,
    WEEK(l.`tanggal`, 3)         AS `minggu_iso`,
    CONCAT(YEAR(l.`tanggal`), '-W', LPAD(WEEK(l.`tanggal`, 3), 2, '0')) AS `periode_minggu`,
    MIN(l.`tanggal`)             AS `tanggal_awal`,
    MAX(l.`tanggal`)             AS `tanggal_akhir`,
    COUNT(*)                     AS `jumlah_laporan`,
    COUNT(DISTINCT l.`tanggal`)  AS `hari_aktif`,
    SUM(CASE
            WHEN l.`jam_mulai` IS NOT NULL AND l.`jam_selesai` IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, l.`jam_mulai`, l.`jam_selesai`)
            ELSE 0
        END)                     AS `total_durasi_menit`
FROM `laporan_harian` l
LEFT JOIN `users` u ON u.`id` = l.`user_id`
GROUP BY l.`user_id`, u.`nama`, u.`divisi`,
         YEAR(l.`tanggal`), WEEK(l.`tanggal`, 3);

-- ------------------------------------------------------------
-- 9. AGREGAT: Distribusi kasus per urgensi & status
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_kasus_per_urgensi` AS
SELECT
    k.`tingkat_urgensi`,
    k.`status_kasus`,
    COUNT(*)                         AS `jumlah_kasus`,
    ROUND(AVG(k.`progres`), 1)       AS `rata_progres`,
    SUM(CASE WHEN k.`status_kasus` = 'Selesai' THEN 1 ELSE 0 END) AS `jumlah_selesai`
FROM `kasus` k
GROUP BY k.`tingkat_urgensi`, k.`status_kasus`;

-- ------------------------------------------------------------
-- 10. AGREGAT: Distribusi status laporan
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_status_laporan` AS
SELECT
    l.`status`,
    COUNT(*)                     AS `jumlah`
FROM `laporan_harian` l
GROUP BY l.`status`;

-- ------------------------------------------------------------
-- 11. DIMENSI: Kalender (untuk time intelligence di Power BI)
--     Rentang: 2024-01-01 s.d. 2030-12-31
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_dim_tanggal` AS
SELECT
    d.`tanggal`,
    YEAR(d.`tanggal`)            AS `tahun`,
    MONTH(d.`tanggal`)           AS `bulan`,
    ELT(MONTH(d.`tanggal`), 'Januari','Februari','Maret','April','Mei','Juni',
        'Juli','Agustus','September','Oktober','November','Desember') AS `nama_bulan`,
    QUARTER(d.`tanggal`)         AS `kuartal`,
    WEEK(d.`tanggal`, 3)         AS `minggu_iso`,
    ELT(DAYOFWEEK(d.`tanggal`), 'Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu') AS `nama_hari`,
    DAYOFWEEK(d.`tanggal`)       AS `hari_ke`,
    CASE WHEN DAYOFWEEK(d.`tanggal`) IN (1, 7) THEN 1 ELSE 0 END AS `flag_akhir_pekan`,
    DATE_FORMAT(d.`tanggal`, '%Y-%m') AS `periode_bulan`
FROM (
    SELECT DATE_ADD('2024-01-01', INTERVAL n DAY) AS `tanggal`
    FROM (
        SELECT a.n + b.n * 10 + c.n * 100 + d.n * 1000 AS n
        FROM
            (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
             UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
        CROSS JOIN
            (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
             UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
        CROSS JOIN
            (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
             UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c
        CROSS JOIN
            (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2) d
    ) angka
    WHERE DATE_ADD('2024-01-01', INTERVAL n DAY) <= '2030-12-31'
) d;

-- ------------------------------------------------------------
-- 12. FAKTA: Jadwal & agenda per pengguna
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_jadwal` AS
SELECT
    j.`id`                       AS `jadwal_id`,
    j.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    j.`judul`,
    j.`lokasi`,
    j.`mulai`,
    j.`selesai`,
    DATE(j.`mulai`)              AS `tanggal`,
    YEAR(j.`mulai`)              AS `tahun`,
    MONTH(j.`mulai`)             AS `bulan`,
    WEEK(j.`mulai`, 3)           AS `minggu_iso`,
    ELT(DAYOFWEEK(j.`mulai`), 'Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu') AS `nama_hari`,
    j.`pengingat_menit`,
    j.`status`,
    CASE j.`status`
        WHEN 'rencana' THEN 'Direncanakan'
        WHEN 'selesai' THEN 'Selesai'
        WHEN 'batal'   THEN 'Dibatalkan'
        ELSE j.`status`
    END                          AS `label_status`,
    j.`garis_lintang`,
    j.`garis_bujur`,
    j.`wilayah_id`,
    j.`pengingat_terkirim`,
    j.`dibuat_pada`,
    j.`diperbarui_pada`
FROM `jadwal` j
LEFT JOIN `users` u ON u.`id` = j.`user_id`;

-- ------------------------------------------------------------
-- 13. FAKTA: Komentar pada laporan
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_komentar` AS
SELECT
    k.`id`                       AS `komentar_id`,
    k.`laporan_id`,
    k.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_pengguna`,
    u.`role`                     AS `peran_pengguna`,
    CHAR_LENGTH(k.`isi`)         AS `panjang_isi`,
    DATE(k.`dibuat_pada`)        AS `tanggal`,
    k.`dibuat_pada`
FROM `komentar_laporan` k
LEFT JOIN `users` u ON u.`id` = k.`user_id`;

-- ------------------------------------------------------------
-- 14. FAKTA: Pengingat harian (riwayat pengiriman)
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_pengingat` AS
SELECT
    p.`id`                       AS `pengingat_id`,
    p.`user_id`                  AS `pengguna_id`,
    u.`nama`                     AS `nama_karyawan`,
    p.`jenis`,
    p.`tanggal_ref`,
    p.`waktu_kirim`,
    p.`status`,
    CASE p.`status`
        WHEN 'antre'    THEN 'Antre'
        WHEN 'terkirim' THEN 'Terkirim'
        WHEN 'dilewati' THEN 'Dilewati'
        ELSE p.`status`
    END                          AS `label_status`,
    p.`dibuat_pada`
FROM `pengingat` p
LEFT JOIN `users` u ON u.`id` = p.`user_id`;

-- ------------------------------------------------------------
-- 15. AGREGAT: Kepatuhan pengisian per hari (laporan vs absensi)
--     Berguna untuk kartu KPI "kelengkapan pengisian".
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_kepatuhan_harian` AS
SELECT
    t.`tanggal`,
    t.`tahun`,
    t.`bulan`,
    t.`nama_bulan`,
    t.`minggu_iso`,
    t.`nama_hari`,
    t.`flag_akhir_pekan`,
    (SELECT COUNT(*) FROM `laporan_harian` l WHERE l.`tanggal` = t.`tanggal`)             AS `jumlah_laporan`,
    (SELECT COUNT(DISTINCT l2.`user_id`) FROM `laporan_harian` l2 WHERE l2.`tanggal` = t.`tanggal`) AS `karyawan_lapor`,
    (SELECT COUNT(*) FROM `absensi` a WHERE a.`tanggal` = t.`tanggal`)                     AS `jumlah_absensi`,
    (SELECT COUNT(*) FROM `absensi` a2 WHERE a2.`tanggal` = t.`tanggal` AND a2.`status` = 'hadir') AS `jumlah_hadir`
FROM `vw_dim_tanggal` t;

-- ============================================================
-- 16. SRM — data kegiatan Social Relations Management (detail)
--     `persen_selesai` menormalkan nilai 0..1 menjadi persen.
-- ============================================================
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_srm` AS
SELECT
    `id`,
    `tahun_intervensi`,
    `area_field`,
    `tanggal`,
    YEAR(`tanggal`)                AS `tahun`,
    MONTH(`tanggal`)               AS `bulan`,
    `waktu`,
    `pic`,
    `kategori_kegiatan`,
    `klasifikasi_issue`,
    `judul_issue`,
    `detail_issue`,
    `kelurahan`,
    `kecamatan`,
    `kabupaten_kota`,
    `jenis_stakeholder`,
    `stakeholder_hadir`,
    `action_plan`,
    `tim_phr`,
    `value_creation`,
    `status`,
    CASE `status`
        WHEN 'High' THEN 'Tinggi'
        WHEN 'Med'  THEN 'Sedang'
        WHEN 'Low'  THEN 'Rendah'
        ELSE `status`
    END                           AS `label_status`,
    `persen_status`               AS `persen_mentah`,
    CASE
        WHEN `persen_status` IS NULL THEN NULL
        WHEN `persen_status` <= 1    THEN `persen_status` * 100
        ELSE `persen_status`
    END                           AS `persen_selesai`,
    `dokumentasi`,
    `garis_lintang`,
    `garis_bujur`,
    `wilayah_id`,
    `dibuat_pada`
FROM `srm_kegiatan`;

-- ------------------------------------------------------------
-- 17. CID — program pemberdayaan masyarakat / TJSL (detail)
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_cid` AS
SELECT
    `id`,
    `tahun_intervensi`,
    `judul_program`,
    `pilar_program`,
    `desa`,
    `desa_aset_operasi`,
    `desa_rk_bor`,
    `desa_konflik_sosial`,
    `kode_desa`,
    `kecamatan`,
    `kode_kecamatan`,
    `kabupaten_kota`,
    `kode_kabupaten_kota`,
    `kelompok_diberdayakan`,
    `total_penerima_manfaat`,
    `perempuan`,
    `laki_laki`,
    `ibu_hamil`,
    `balita`,
    `disabilitas`,
    `potensi_peningkatan_ekonomi`,
    `potensi_penghematan_operasional`,
    `penanaman_pohon`,
    `luas_penanaman_ha`,
    `sampah_terkelola_kg`,
    `peningkatan_kualitas_kesehatan`,
    `penyerapan_lapangan_kerja`,
    `peningkatan_kapasitas`,
    `pembentukan_kelompok_baru`,
    `kerjasama_stakeholder`,
    `bulan`,
    `bulan_angka`,
    `program`,
    `anggaran`,
    `garis_lintang`,
    `garis_bujur`,
    `wilayah_id`,
    `dibuat_pada`
FROM `cid_program`;

-- ------------------------------------------------------------
-- 18. AGREGAT: SRM per area/field
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_srm_per_area` AS
SELECT
    `area_field`,
    COUNT(*)                                                        AS `jumlah_kegiatan`,
    SUM(`status` = 'High')                                         AS `jumlah_high`,
    SUM(`status` = 'Med')                                          AS `jumlah_med`,
    SUM(`status` = 'Low')                                          AS `jumlah_low`,
    COUNT(DISTINCT `kabupaten_kota`)                               AS `jumlah_kabupaten`,
    AVG(CASE WHEN `persen_status` <= 1 THEN `persen_status` * 100 ELSE `persen_status` END) AS `rata_persen`
FROM `srm_kegiatan`
GROUP BY `area_field`;

-- ------------------------------------------------------------
-- 19. AGREGAT: SRM per jenis stakeholder
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_srm_per_stakeholder` AS
SELECT
    `jenis_stakeholder`,
    COUNT(*)                        AS `jumlah_kegiatan`,
    SUM(`status` = 'High')         AS `jumlah_high`,
    SUM(`status` = 'Med')          AS `jumlah_med`,
    SUM(`status` = 'Low')          AS `jumlah_low`,
    AVG(CASE WHEN `persen_status` <= 1 THEN `persen_status` * 100 ELSE `persen_status` END) AS `rata_persen`
FROM `srm_kegiatan`
GROUP BY `jenis_stakeholder`;

-- ------------------------------------------------------------
-- 20. AGREGAT: CID per pilar program
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_cid_per_pilar` AS
SELECT
    `pilar_program`,
    COUNT(*)                                AS `jumlah_program`,
    COALESCE(SUM(`anggaran`), 0)            AS `total_anggaran`,
    COALESCE(SUM(`total_penerima_manfaat`), 0) AS `total_penerima`,
    COUNT(DISTINCT `desa`)                  AS `jumlah_desa`,
    COALESCE(SUM(`penanaman_pohon`), 0)     AS `total_pohon`,
    COALESCE(SUM(`luas_penanaman_ha`), 0)   AS `total_luas_ha`
FROM `cid_program`
GROUP BY `pilar_program`;

-- ------------------------------------------------------------
-- 21. AGREGAT: CID per wilayah (kabupaten/kota & kecamatan)
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_cid_per_wilayah` AS
SELECT
    `kabupaten_kota`,
    `kecamatan`,
    COUNT(*)                                AS `jumlah_program`,
    COALESCE(SUM(`anggaran`), 0)            AS `total_anggaran`,
    COALESCE(SUM(`total_penerima_manfaat`), 0) AS `total_penerima`,
    COUNT(DISTINCT `desa`)                  AS `jumlah_desa`
FROM `cid_program`
GROUP BY `kabupaten_kota`, `kecamatan`;

-- ------------------------------------------------------------
-- 23. DIMENSI: Master wilayah terpetakan (lokasi -> koordinat)
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_wilayah` AS
SELECT
    `id`              AS `wilayah_id`,
    `level`,
    `nama`,
    `nama_norm`,
    `kabupaten`,
    `kecamatan`,
    `garis_lintang`,
    `garis_bujur`,
    `sumber`,
    `akurasi`,
    `diperbarui_pada`
FROM `wilayah`
WHERE `garis_lintang` IS NOT NULL AND `garis_bujur` IS NOT NULL;

-- ------------------------------------------------------------
-- 24. FAKTA: Cost avoidance (nilai biaya yang berhasil dihindari)
-- ------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `vw_cost_avoidance` AS
SELECT
    c.`id`                          AS `cost_avoidance_id`,
    c.`kode`,
    c.`judul`,
    c.`kategori`,
    c.`unit`,
    u.`nama`                        AS `nama_pembuat`,
    COALESCE(u.`divisi`, '(tanpa divisi)') AS `divisi`,
    c.`periode`,
    YEAR(c.`periode`)               AS `tahun`,
    MONTH(c.`periode`)              AS `bulan`,
    DATE_FORMAT(c.`periode`, '%Y-%m') AS `bulan_label`,
    c.`estimasi`,
    c.`realisasi`,
    c.`nilai_avoidance`,
    c.`status`,
    CASE c.`status`
        WHEN 'draft'     THEN 'Draft'
        WHEN 'diajukan'  THEN 'Menunggu Verifikasi'
        WHEN 'disetujui' THEN 'Disetujui'
        WHEN 'ditolak'   THEN 'Ditolak'
        ELSE c.`status`
    END                             AS `label_status`,
    c.`catatan_verifikasi`,
    c.`dibuat_oleh`,
    c.`diverifikasi_oleh`,
    v.`nama`                        AS `nama_verifikator`,
    c.`diajukan_pada`,
    c.`diverifikasi_pada`,
    c.`dibuat_pada`,
    c.`diperbarui_pada`
FROM `cost_avoidance` c
LEFT JOIN `users` u ON u.`id` = c.`dibuat_oleh`
LEFT JOIN `users` v ON v.`id` = c.`diverifikasi_oleh`;

-- ============================================================
-- HAK AKSES `bi_reader`
-- ------------------------------------------------------------
-- Read-only, HANYA pada VIEW di atas. Tidak ada akses ke tabel
-- dasar (VIEW memakai SQL SECURITY DEFINER).
-- Diberikan per nama view — MariaDB tidak menerima pola `vw_*`.
-- ============================================================

GRANT SELECT ON `pelaporan`.`vw_dim_pengguna`          TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_laporan_harian`        TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_absensi`               TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_kasus`                 TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_ringkasan_harian`      TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_rekap_bulanan`         TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_kehadiran_bulanan`     TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_produktivitas_mingguan` TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_kasus_per_urgensi`     TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_status_laporan`        TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_dim_tanggal`           TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_jadwal`                TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_komentar`              TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_pengingat`             TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_kepatuhan_harian`      TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_srm`                    TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_cid`                    TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_srm_per_area`           TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_srm_per_stakeholder`    TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_cid_per_pilar`          TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_cid_per_wilayah`        TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_wilayah`                TO 'bi_reader'@'localhost';
GRANT SELECT ON `pelaporan`.`vw_cost_avoidance`        TO 'bi_reader'@'localhost';

-- Akun wildcard (untuk koneksi Power BI dari host lain / TCP).
GRANT SELECT ON `pelaporan`.`vw_dim_pengguna`          TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_laporan_harian`        TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_absensi`               TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_kasus`                 TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_ringkasan_harian`      TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_rekap_bulanan`         TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_kehadiran_bulanan`     TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_produktivitas_mingguan` TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_kasus_per_urgensi`     TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_status_laporan`        TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_dim_tanggal`           TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_jadwal`                TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_komentar`              TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_pengingat`             TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_kepatuhan_harian`      TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_srm`                    TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_cid`                    TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_srm_per_area`           TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_srm_per_stakeholder`    TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_cid_per_pilar`          TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_cid_per_wilayah`        TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_wilayah`                TO 'bi_reader'@'%';
GRANT SELECT ON `pelaporan`.`vw_cost_avoidance`        TO 'bi_reader'@'%';

FLUSH PRIVILEGES;
