# Power BI – Integrasi Sistem Pelaporan PHR

> **Prinsip utama:** ODBC langsung (disarankan) untuk koneksi stabil ke MariaDB/XAMPP. REST API + token statis tersedia sebagai alternatif bila akses langsung DB tidak diizinkan oleh jaringan.

## Tujuan

Dokumen ini memuat jalur koneksi Power BI ke basis data `pelaporan`, daftar view yang relevan untuk laporan BI, contoh query, panduan keamanan, dan catatan ekspor XLSX sebagai fallback.

## 1) ODBC Langsung (Rekomendasi)

### Kebutuhan
- MySQL Connector/ODBC (atau MariaDB Connector/ODBC) terpasang di PC yang menjalankan Power BI Desktop.
- Akses jaringan ke server DB (localhost/XAMPP selama pengembangan, atau host produksi).
- Kredensial user read-only terpisah (jangan pakai `root` untuk BI).

### Langkah-langkah Power BI Desktop
1. **Dapatkan data → ODBC**: `Home > Get Data > ODBC`.
2. **Pilih DSN**: Buat/tentukan DSN ke `pelaporan` (MariaDB).
3. **Otentikasi**: Masukkan user `pelaporan_bi` (read-only) dengan password.
4. **Navigator**: Pilih view yang dibutuhkan (lihat daftar di bawah).
5. **Transform Data**: Rapikan tipe kolom (tanggal, angka) sesuai kebutuhan.
6. **Buat model & visual**: Hubungkan view dimensi/fakta sesuai star-schema.

### Contoh DSN (opsional)
- Driver: MariaDB ODBC 3.2 Driver (atau MySQL ODBC 8.0)
- Server: `localhost` (port 3306 default XAMPP)
- Database: `pelaporan`
- User: `pelaporan_bi` (minimal SELECT pada view yang diizinkan)

## 2) REST API (Alternatif)

Sistem menyediakan endpoint BI terpisah agar tidak bentrok dengan rute API Sanctum untuk APK.

### Endpoint Kasus
```text
GET /api/v1/bi/kasus
```

- **Auth**: Token statis (query/header). Token dikonfigurasi via `.env` (`POWERBI_TOKEN`).
- **Contoh**:
  ```http
  GET http://pelaporan.local/api/v1/bi/kasus?token=TOKEN_BI&dari=2026-01-01&sampai=2026-12-31&status=Terbuka&urgensi=Tinggi
  ```
- **Response**: JSON berisi `ringkasan`, `per_urgensi`, `per_kategori`, `per_penanggung_jawab`, `terlambat`, `data[]` (halaman) dll — sesuai implementasi `LaporanApiController@kasus()`.

> Catatan: endpoint BI lain dapat ditambahkan dengan prefix `/bi/` untuk menghindari konflik dengan endpoint APK (`/api/v1/kasus`, dll.).

## 3) Ekspor XLSX (Fallback Cepat)

Jika Power BI tidak dapat mengakses DB langsung, gunakan ekspor XLSX dari aplikasi:
- `GET /api/v1/ekspor/laporan` (mentor/admin) – rekap laporan
- `GET /api/v1/ekspor/absensi` (mentor/admin) – rekap absensi
- `GET /api/v1/ekspor/kasus` (mentor/admin) – rekap kasus

File dihasilkan via `PhpOffice\PhpSpreadsheet` (`streamDownload`), format rapi (header berwarna korporat #003D7D teks putih). Dapat diimpor Power BI via `Get Data → Excel Workbook`.

## 4) Daftar View untuk Power BI

Berdasarkan star-schema yang ada di DB `pelaporan` (23 view), berikut rekomendasi pemakaian:

| View | Peran | Keterangan |
|---|---|---|
| `vw_dim_tanggal` | Dimensi | Kalender 2024–2030 (hari, bulan, tahun, kuartal, hari kerja) — jembatan waktu. |
| `vw_dim_pengguna` | Dimensi | Pengguna terdaftar (id, nama, email, role, divisi, status). |
| `vw_fakta_laporan_harian` | Fakta | Laporan harian teragregasi per baris (tanggal, user_id, status, durasi dll). |
| `vw_fakta_absensi` | Fakta | Absensi harian (user_id, tanggal, status, jam masuk/pulang). |
| `vw_fakta_kasus` | Fakta | Kasus (kode, status, urgensi, kategori, PIC, target, progres). |
| `vw_cost_avoidance` | Fakta | Cost avoidance (estimasi, realisasi, nilai dihindari, status verifikasi, pembuat). |
| `vw_fakta_tugas` | Fakta | Tugas (penugasan, tenggat, status, penyelesaian). |
| `vw_kepatuhan_harian` | Ukur | Indikator kepatuhan per tanggal/pengguna. |

> Sesuaikan pemilihan view dengan kebutuhan laporan Power BI (hindari query tabel mentah; utamakan view yang sudah disiapkan).

## 5) Contoh Query (ODBC)

### Jumlah laporan per status & bulan
```sql
SELECT
  DATE_FORMAT(t.tanggal, '%Y-%m') AS bulan,
  f.status,
  COUNT(*) AS jumlah
FROM vw_fakta_laporan_harian f
JOIN vw_dim_tanggal t ON t.tanggal = f.tanggal
GROUP BY bulan, f.status
ORDER BY bulan;
```

### Kasus terlambat per PIC
```sql
SELECT
  COALESCE(p.nama, f.penanggung_jawab_teks, 'Belum Ditentukan') AS pic,
  COUNT(*) AS kasus_terlambat
FROM vw_fakta_kasus f
LEFT JOIN vw_dim_pengguna p ON p.id = f.penanggung_jawab_id
WHERE f.status_kasus <> 'Selesai'
  AND f.target_selesai IS NOT NULL
  AND f.target_selesai < CURDATE()
GROUP BY pic
ORDER BY kasus_terlambat DESC;
```

### Kehadiran per pengguna (periode)
```sql
SELECT
  p.nama,
  f.status,
  COUNT(*) AS jumlah
FROM vw_fakta_absensi f
JOIN vw_dim_pengguna p ON p.id = f.user_id
WHERE f.tanggal BETWEEN '2026-10-01' AND '2026-10-31'
GROUP BY p.nama, f.status;
```

## 6) Keamanan & Rekomendasi

- **User BI read-only:** Buat `pelaporan_bi` dengan hak `SELECT` **hanya** pada view yang diperlukan (bukan tabel langsung). Hindari `INSERT/UPDATE/DELETE`.
- **Token REST:** `POWERBI_TOKEN` jangan disebar; gunakan variabel lingkungan/secret di lingkungan Power BI Gateway jika dipakai terpusat.
- **Jaringan:** Batasi IP yang boleh konek ODBC (firewall/XAMPP) sesuai kebutuhan.
- **Row-level awareness:** Jika ada kebutuhan pembatasan per divisi, terapkan filter di Power BI atau tambahkan view dengan filter yang aman.
- **Audit minimal:** Logging akses REST via middleware/token check (sudah diterapkan untuk endpoint `/bi/*`).

## 7) Checklist Go-Live (Power BI)

- [ ] DSN ODBC tervalidasi dari mesin Power BI.
- [ ] User `pelaporan_bi` memiliki grant SELECT terbatas sesuai view yang dipilih.
- [ ] Refresh berhasil (refresh manual/Desktop) tanpa error kolom/tanggal.
- [ ] Model relasi antar view benar (tanggal↔fakta, pengguna↔fakta).
- [ ] Alternatif REST+XLSX diuji & didokumentasikan ke tim.
- [ ] `docs/POWERBI.md` ini tersedia di repo & dibagikan ke pengguna Power BI.

## Referensi Terkait

- `app/Http/Controllers/Api/LaporanApiController::kasus()` – endpoint `/bi/kasus`
- `app/Http/Controllers/Api/EksporController` – ekspor XLSX
- `database/views/` – definisi view (termasuk `vw_dim_tanggal.sql` yang telah diperbaiki)
- `panduan_powerbi.php` – panduan interaktif di aplikasi (legacy)