# Visualisasi Power BI — Sistem Pelaporan Kegiatan Harian

Lapisan analitik untuk pimpinan/HR: seluruh data laporan, absensi, dan kasus
dibaca **hanya-baca** lewat sejumlah *view* yang stabil. Power BI tidak pernah
menyentuh tabel operasional secara langsung.

---

## 1. Arsitektur

```
Power BI Desktop  ──(Import)──►  MySQL/MariaDB : pelaporan
                                        │
                                 view analitik (vw_*)
                                        │
                              akun  bi_reader  (SELECT saja)
```

| Aspek | Keputusan |
|---|---|
| Mode | **Import** (bukan DirectQuery) — data kecil, dashboard cepat |
| Akun | `bi_reader` — `SELECT` pada view (dan tabel inti yang dibaca layanan AI) |
| Kredensial | sandi ada di `database/migrations/010_user_db.sql` |
| Keamanan | view memakai `SQL SECURITY DEFINER`; `bi_reader` tetap **tanpa** hak tulis |

> Catatan: selain `SELECT` pada view, `bi_reader` juga diberi `SELECT`
> pada sejumlah **tabel dasar** yang dibaca layanan AI pada jalur baca-saja
> (`users`, `laporan_harian`, `absensi`, `kasus`, `kasus_pihak`, `jadwal` —
> lihat `database/migrations/019_akses_baca_ai.sql`). Power BI sendiri tetap
> disarankan membaca **view**, bukan tabel dasar.

---

## 2. Menyiapkan basis data

```powershell
# dari akar proyek
C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 pelaporan < powerbi\sql\02_views.sql
```

Perintah di atas membuat 23 view dan memberikan `SELECT` ke `bi_reader`
per nama view (MariaDB menolak pola `vw_*` pada `GRANT`).

Uji cepat:

```powershell
C:\xampp\mysql\bin\mysql.exe -ubi_reader "-pSANDI_BI_ANDA" pelaporan `
  -e "SELECT COUNT(*) FROM vw_laporan_harian;"
```

---

## 3. Menyambungkan Power BI Desktop

Ada **dua jalur**. Yang **disarankan** adalah **ODBC** karena hanya
memerlukan *MySQL ODBC Driver* (sudah terpasang bersama XAMPP), tanpa
memasang MySQL Connector/NET.

### 3a. Jalur ODBC (disarankan)

1. Daftarkan **User DSN** bernama `PelaporanBI` (sekali saja):

   ```powershell
   # dari akar proyek, jalankan PowerShell
   powershell -ExecutionPolicy Bypass -File scripts\buat_dsn_powerbi.ps1
   ```

   Skrip mendaftarkan DSN dengan *connection string*:

   ```
   Driver={MySQL ODBC 26.7 Unicode Driver};Server=localhost;Port=3306;Database=pelaporan;User=bi_reader;Password=<sandi>;Option=3;charset=utf8mb4;
   ```

2. Buka **`powerbi/pelaporan-odbc.pbids`** (klik dua kali),
   atau manual: **Get Data → Database → ODBC** lalu pilih DSN `PelaporanBI`.

   | Kolom | Nilai |
   |---|---|
   | Mode koneksi data | **Import** |
   | Kredensial | Database → `bi_reader` / *sandi Anda* (lihat `AI_DB_READONLY_PASSWORD` di `ai-service/.env`) |

### 3b. Jalur konektor MySQL (alternatif)

1. Pasang **MySQL Connector/NET** (prasyarat konektor *MySQL database*).
2. Buka **`powerbi/pelaporan.pbids`** atau manual:
   **Get Data → Database → MySQL database**

   | Kolom | Nilai |
   |---|---|
   | Server | `localhost` |
   | Database | `pelaporan` |
   | Mode koneksi data | **Import** |
   | Kredensial | Database → `bi_reader` / *sandi Anda* |

3. Centang view berikut, lalu **Load**:

   - `vw_dim_pengguna` — dimensi pengguna
   - `vw_dim_tanggal` — dimensi kalender (2024‑01‑01 s.d. 2030‑12‑31)
   - `vw_laporan_harian` — fakta laporan
   - `vw_absensi` — fakta absensi (termasuk `kegiatan_hari_ini`, `kondisi_kesehatan`)
   - `vw_kasus` — fakta kasus
   - `vw_cost_avoidance` — fakta cost avoidance (estimasi, realisasi, nilai dihindari, status verifikasi)
   - `vw_jadwal` — fakta agenda/jadwal (status, durasi, pengingat)
   - `vw_komentar` — fakta diskusi/komentar per laporan
   - `vw_pengingat` — fakta pengingat (status kirim)
   - `vw_ringkasan_harian`, `vw_rekap_bulanan`, `vw_produktivitas_mingguan`
   - `vw_kehadiran_bulanan` (termasuk hitungan kondisi kesehatan), `vw_kepatuhan_harian`
   - `vw_kasus_per_urgensi`, `vw_status_laporan`
   - `vw_wilayah` — dimensi master wilayah (lokasi → koordinat, untuk peta)
   - `vw_srm`, `vw_cid` — fakta data sosial (SRM & CID/TJSL)
   - `vw_srm_per_area`, `vw_srm_per_stakeholder`, `vw_cid_per_pilar`, `vw_cid_per_wilayah`

4. **Model view:** buat relasi

   | Dari | Ke | Kardinalitas |
   |---|---|---|
   | `vw_laporan_harian[pengguna_id]` | `vw_dim_pengguna[pengguna_id]` | banyak ke satu |
   | `vw_laporan_harian[tanggal]` | `vw_dim_tanggal[tanggal]` | banyak ke satu |
   | `vw_absensi[pengguna_id]` | `vw_dim_pengguna[pengguna_id]` | banyak ke satu |
   | `vw_absensi[tanggal]` | `vw_dim_tanggal[tanggal]` | banyak ke satu |
   | `vw_kasus[tanggal_dibuat]` | `vw_dim_tanggal[tanggal]` | banyak ke satu |
   | `vw_cost_avoidance[periode]` | `vw_dim_tanggal[tanggal]` | banyak ke satu |
   | `vw_cost_avoidance[dibuat_oleh]` | `vw_dim_pengguna[pengguna_id]` | banyak ke satu |
   | `vw_srm[tanggal]` | `vw_dim_tanggal[tanggal]` | banyak ke satu |

   Tandai `vw_dim_tanggal` sebagai **Date table** (kolom `tanggal`).

---

## 4. Ukuran DAX yang disarankan

Letakkan di tabel `vw_laporan_harian` kecuali disebut lain.

```dax
Jumlah Laporan = COUNTROWS ( vw_laporan_harian )

Total Jam Kerja = DIVIDE ( SUM ( vw_laporan_harian[durasi_menit] ), 60 )

Rata Durasi (menit) = AVERAGE ( vw_laporan_harian[durasi_menit] )

% Diperiksa =
DIVIDE (
    CALCULATE ( [Jumlah Laporan], vw_laporan_harian[label_status] = "Diperiksa" ),
    [Jumlah Laporan]
)

% Perlu Revisi =
DIVIDE (
    CALCULATE ( [Jumlah Laporan], vw_laporan_harian[label_status] = "Perlu Revisi" ),
    [Jumlah Laporan]
)

Laporan dengan Kendala =
CALCULATE ( [Jumlah Laporan], vw_laporan_harian[ada_kendala] = 1 )

Hari Aktif = DISTINCTCOUNT ( vw_laporan_harian[tanggal] )

-- tabel vw_absensi
% Kehadiran = AVERAGE ( vw_absensi[flag_hadir] )

Jumlah Alpa = CALCULATE ( COUNTROWS ( vw_absensi ), vw_absensi[status] = "alpa" )

-- tabel vw_kasus
Kasus Aktif =
CALCULATE ( COUNTROWS ( vw_kasus ), vw_kasus[flag_selesai] = 0 )

Kasus Perlu Perhatian = SUM ( vw_kasus[flag_perlu_perhatian] )

Rata Progres Kasus = AVERAGE ( vw_kasus[progres] )

-- tabel vw_cost_avoidance
Total Nilai Avoidance =
CALCULATE ( SUM ( vw_cost_avoidance[nilai_avoidance] ), vw_cost_avoidance[status] = "disetujui" )

Jumlah Entri Disetujui =
CALCULATE ( COUNTROWS ( vw_cost_avoidance ), vw_cost_avoidance[status] = "disetujui" )

Rata Nilai per Entri = DIVIDE ( [Total Nilai Avoidance], [Jumlah Entri Disetujui] )

Rasio Realisasi =
DIVIDE ( SUM ( vw_cost_avoidance[realisasi] ), SUM ( vw_cost_avoidance[estimasi] ) )

-- perbandingan periode (butuh tabel tanggal bertanda)
Jumlah Laporan LM =
CALCULATE ( [Jumlah Laporan], DATEADD ( vw_dim_tanggal[tanggal], -1, MONTH ) )

Pertumbuhan MoM % =
VAR kini = [Jumlah Laporan]
VAR lalu = [Jumlah Laporan LM]
RETURN DIVIDE ( kini - lalu, lalu )

-- tabel vw_srm
Jumlah Kegiatan SRM = COUNTROWS ( vw_srm )

Kegiatan SRM High =
CALCULATE ( COUNTROWS ( vw_srm ), vw_srm[status] = "High" )

Rata Penyelesaian SRM = AVERAGE ( vw_srm[persen_selesai] )

-- tabel vw_cid
Jumlah Program CID = COUNTROWS ( vw_cid )

Total Anggaran CID = SUM ( vw_cid[anggaran] )

Total Penerima Manfaat = SUM ( vw_cid[total_penerima_manfaat] )

Anggaran per Penerima =
DIVIDE ( [Total Anggaran CID], [Total Penerima Manfaat] )

Total Pohon = SUM ( vw_cid[penanaman_pohon] )
```

---

## 5. Saran halaman dashboard

| Halaman | Visual utama |
|---|---|
| **Ringkasan Eksekutif** | Kartu: Jumlah Laporan, Hari Aktif, Total Jam Kerja, % Diperiksa; garis tren harian; diagram donat status laporan |
| **Kegiatan** | Batang jumlah laporan per pengguna & per divisi; *matrix* pengguna × bulan; *heatmap* hari × minggu |
| **Absensi** | Kartu % Kehadiran; batang bertumpuk hadir/izin/sakit/alpa per bulan; tabel detail |
| **Kasus** | Kartu Kasus Aktif & Perlu Perhatian; batang per urgensi; garis rata progres; peta (`garis_lintang`/`garis_bujur`) |
| **Kualitas Laporan** | % Perlu Revisi; laporan dengan kendala; *top* pengguna dengan revisi terbanyak |
| **Disiplin Pengisian** | Hari tanpa laporan (bandingkan `vw_dim_tanggal` kerja vs `vw_ringkasan_harian`) |
| **Data Sosial** | Kartu jumlah kegiatan SRM & program CID; batang SRM per area & per stakeholder; donat SRM per status; batang anggaran CID per pilar; tabel CID per wilayah; indikator lingkungan (pohon, luas tanam) |
| **Peta Lokasi** | Peta (*Map* / *Azure Maps*) dari `vw_srm[garis_lintang]`/`[garis_bujur]`, `vw_cid[…]`, `vw_kasus[…]`, `vw_laporan_harian[…]`, `vw_absensi[…]`, `vw_jadwal[…]`; ukuran gelembung = jumlah; gunakan kolom `peta_url` untuk membuka lokasi di Google Maps |

> Warna korporat PHR: biru tua `#003D7D`, biru `#005EB8`, merah `#EB1C24`,
> hijau `#159447`, latar `#F5F5F5`. Gunakan untuk tema Power BI
> (*View → Themes → Customize current theme*).

---

## 6. Menyegarkan data

Mode **Import** → data di-cache. Perbarui dengan:

- **Home → Refresh** (manual), atau
- Terbitkan ke Power BI Service lalu jadwalkan *Scheduled refresh*
  (perlu **On-premises data gateway** karena MariaDB berjalan di lokal).

Alternatif tanpa konektor: halaman **`powerbi_ekspor.php`** (khusus admin)
mengunduh lapisan view sebagai berkas, sehingga bisa dimuat ke Power BI
secara manual:

| Format | Isi |
|---|---|
| CSV per view | satu view, pemisah `;` |
| `.xlsx` multi-lembar | beberapa view sekaligus dalam satu buku kerja |
| `.zip` | kumpulan CSV (satu berkas per view) |
| `.pbids` | pintasan koneksi Power BI Desktop (kredensial diisi saat membuka) |

---

## 7. Berkas di folder ini

| Berkas | Isi |
|---|---|
| `sql/02_views.sql` | Pembuatan 23 view + hak akses `bi_reader` |
| `pelaporan-odbc.pbids` | Pintasan koneksi Power BI Desktop lewat **ODBC** (disarankan) |
| `pelaporan.pbids` | Pintasan koneksi Power BI Desktop lewat konektor MySQL |
| `README.md` | Dokumen ini |

---

## 8. Catatan keamanan

- `bi_reader` **tanpa hak tulis** apa pun (tidak ada `INSERT`/`UPDATE`/`DELETE`).
- `bi_reader` diberi `SELECT` pada view, dan `SELECT` pada beberapa tabel inti
  khusus untuk jalur baca-saja layanan AI (`019_akses_baca_ai.sql`). Untuk
  dashboard, bacalah **view** — jangan tabel dasar — agar skema mentah tidak
  ikut terekspos.
- Sandi **tidak** ditulis di dalam `.pbids`; isikan saat Power BI meminta.
- Direktori `powerbi/` diblokir dari akses web oleh `.htaccess` akar.
- Ganti sandi bawaan `bi_reader` sebelum dipakai di produksi.
