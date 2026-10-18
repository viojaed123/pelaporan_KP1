# Sistem Pelaporan Kegiatan Harian — PHR

Sistem pelaporan kegiatan harian untuk karyawan dan magang,
dilengkapi alur persetujuan, absensi, pelacakan kasus, keamanan berlapis,
asisten AI lokal, dan visualisasi Power BI.

Warna korporat dipertahankan: **#003D7D** (biru tua), **#005EB8** (biru),
**#EB1C24** (merah), **#159447** (hijau), latar **#F5F5F5**.

---

## 1. Persyaratan

| Komponen | Versi teruji |
|---|---|
| PHP | 8.2 (XAMPP), ekstensi: `mysqli`, `curl`, `mbstring`, `openssl`, `json` |
| Database | MariaDB 10.4 (XAMPP) |
| Composer | dipakai untuk dependensi PHP |
| Python | 3.10+ (opsional, untuk layanan AI) |
| Ollama | model `qwen2.5:7b` (opsional, untuk AI) |
| Power BI Desktop | (opsional, untuk visualisasi) |

---

## 2. Pemasangan

### 2.1. Basis data

```bat
mysql -u pelaporan_app -p pelaporan < database\migrations\001_skema_awal.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\002_keamanan_login.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\003_audit_log.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\004_ai_service.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\010_user_db.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\011_hak_akses_app.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\012_pengiriman_laporan.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\013_peningkatan_akun.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\014_komentar_laporan.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\015_absensi_kegiatan_kesehatan.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\016_foto_profil.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\017_impor_laporan.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\018_jadwal.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\020_otp_jenis.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\021_srm_cid.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\022_cid_bigint.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\023_wilayah_lokasi.sql
mysql -u pelaporan_app -p pelaporan < database\migrations\024_geokode_cache.sql
```

Migrations 010-011 membuat akun DB terpisah dengan hak minimum. Gunakan akun tersebut di `config_lokal.php`.

Migrations 010, 011, dan 019 memberi/membuat akun DB dan hak akses, jadi **harus dijalankan sebagai `root`** (akun aplikasi tidak punya `GRANT OPTION`):

```bat
mysql -u root pelaporan < database\migrations\010_user_db.sql
mysql -u root pelaporan < database\migrations\011_hak_akses_app.sql
mysql -u root pelaporan < database\migrations\019_akses_baca_ai.sql
```

Lapisan analitik Power BI (22 view) juga dibuat dengan akun `root`:

```bat
mysql -u root --default-character-set=utf8mb4 pelaporan < powerbi\sql\02_views.sql
```

### 2.2. Konfigurasi lokal (rahasia)

Rahasia aplikasi **tidak** disimpan di `includes/config.php`. Salin berkas contoh lalu isi nilai asli:

```bat
copy includes\config_lokal.example.php includes\config_lokal.php
```

Berkas `includes/config_lokal.php` diabaikan Git (.gitignore). Isi setidaknya:

- `DB_PASS` — kata sandi akun basis data
- `AI_SERVICE_KEY` — kunci layanan AI (harus sama dengan `ai-service/.env`)
- `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` — SMTP untuk surel
- `WA_GATEWAY`, `WA_API_KEY` — (opsional) gateway WhatsApp

Lihat komentar dalam `config_lokal.example.php` untuk detail.

### 2.3. Dependensi PHP

```bat
composer install
```

### 2.4. Akun administrator

Pendaftaran mandiri membuat akun **karyawan** yang langsung aktif. Untuk akun
**admin**, gunakan skrip baris perintah (membuat bila belum ada, atau menyetel
ulang bila sudah ada):

```bat
C:\xampp\php\php.exe scripts\buat_admin.php --email=admin@pelaporan.local
```

Tanpa `--sandi`, skrip akan meminta kata sandi (disembunyikan). Bisa juga:
`--nama="Administrator" --sandi=Rahasia123`.

### 2.5. Impor data SRM & CID (data sosial)

Berkas Excel berisi lembar **SRM** dan/atau **CID** (C&D) dapat diimpor lewat
**halaman unggah terpadu** `impor_laporan.php` (pilih jenis "SRM & CID (Excel)" —
tanpa pemetaan kolom, lembar terdeteksi otomatis, ada peringatan ganti-semua),
halaman khusus `impor_srm_cid.php` (admin), atau lewat baris perintah:

```bat
C:\xampp\php\php.exe scripts\impor_srm_cid.php --berkas="C:\path\data.xlsx" --jenis=semua
```

Impor bersifat **ganti-semua per jenis** dan dijalankan dalam satu transaksi.
Sel rumus pada berkas (mis. `=R2+S2+T2+U2+V2`) dibaca dari nilai cache-nya,
sehingga angka tetap benar.

### 2.6. Peta lokasi & geokode wilayah

Fitur peta memetakan lokasi seluruh data (laporan harian, absensi, jadwal,
kasus, SRM, CID) ke koordinat. Sumber koordinat: hasil **geokode daring**
(OpenStreetMap Nominatim) untuk master wilayah, ditambah GPS/pilih-titik di
peta pada formulir laporan & absensi.

Siapkan master wilayah sekali saja (butuh internet; patuh 1 permintaan/detik):

```bat
C:\xampp\php\php.exe scripts\geokode_wilayah.php
C:\xampp\php\php.exe scripts\resolusi_lokasi.php
```

- `geokode_wilayah.php` mengisi koordinat untuk baris `wilayah` (kabupaten,
  kecamatan, desa) memakai alias untuk nama yang tidak baku.
- `resolusi_lokasi.php` mengisi `wilayah_id`, `garis_lintang`, `garis_bujur`
  pada data SRM/CID berdasarkan nama wilayahnya.

Halaman **Peta Lokasi** (`peta.php`, semua peran) menampilkan peta Leaflet
gabungan dengan penyaring tahun/kabupaten/pencarian dan tombol
**Cek Lokasi** (tautan Google Maps) pada tabel data. Setiap kali data diunggah,
titik baru **disorot** (cincin berdenyut) dan panel **Chart.js** menampilkan
ringkasan (titik per sumber, 10 kabupaten terbanyak, tren 12 bulan).

Koordinat dari data yang diunggah diselesaikan otomatis dengan urutan:
**koordinat pada berkas -> master `wilayah` -> cache `geokode_cache` -> Nominatim**.
Hasil Nominatim disimpan di `geokode_cache` (migrasi `024_geokode_cache.sql`)
sehingga pencarian ulang tidak memanggil jaringan lagi. Sisa yang belum
terpetakan ditampilkan sebagai "N lokasi menunggu geokode" dan dapat
dilanjutkan lewat `scripts\resolusi_lokasi.php`.

> Catatan: `navigator.geolocation` hanya aktif pada `https://` atau `localhost`.
> Via LAN `http://192.168.x.x` GPS diblokir; gunakan kolom teks lokasi atau
> klik peta, ditambah tautan Google Maps sebagai jalur yang selalu tersedia.

### 2.7. Layanan AI (opsional)

Lihat `ai-service/README.md` untuk menjalankan FastAPI + Ollama/Hermes.

---

## 3. Akses

Aplikasi web tersedia di:

- `http://localhost/pelaporan/`
- `http://192.168.56.1/pelaporan/` (jaringan virtual)

Akun contoh (jika tidak dihapus): lihat data awal migrations. Pendaftaran mandiri
kini membuat akun yang **langsung aktif** (tanpa verifikasi surel), dan **lupa
kata sandi memakai kode OTP** ke surel (bukan tautan). Akun admin dibuat/disetel
ulang lewat `scripts\buat_admin.php` (lihat 2.4).

### 3.1. Aplikasi baru (Laravel 12): SPA & API

Migrasi berjalan bertahap (*strangler-fig*): aplikasi lama tetap di
`localhost/pelaporan/`, sedangkan versi **Laravel 12** disajikan dari
`public/` (mis. `http://192.168.56.1/`). Dua permukaan baru:

- **SPA React** — `http://192.168.56.1/app` (shell Blade + bundel Vite;
  sumber di `resources/js/react/`). Semua panggilan memakai API di bawah.
- **API `/api/v1/*`** — fondasi bersama untuk SPA dan APK. Autentikasi
  **Laravel Sanctum** (token Bearer dari `personal_access_tokens`); masuk lewat
  `POST /api/v1/auth/login` (mendukung alur 2FA ticket → OTP). Sebagian endpoint
  ringkas untuk Power BI memakai token statis (`POWERBI_API_TOKEN`).

Bangun ulang aset SPA setelah mengubah kode React:

```bat
npm.cmd run build
```

### 3.2. APK Android (native, Kotlin)

Aplikasi mobile native ada di `mobile/` (Kotlin + Jetpack Compose, klien tipis
ke `/api/v1/*`). Lihat `mobile/README.md`. Dasar singkat:

```powershell
cd mobile
.\gradlew.bat assembleDebug   # hasil: mobile\app\build\outputs\apk\debug\app-debug.apk
```

Alamat server dapat diubah langsung di layar masuk APK.

---

## 4. Struktur & keamanan

- Semua halaman wajib memanggil `includes/bootstrap.php`.
- CSRF per-aksi, rate limit + lockout login, 2FA OTP (opsional), sesi aktif, kebijakan kata sandi (minimal **8** karakter), riwayat audit.
- `includes/config_lokal.php` dilindungi oleh `.htaccess` (403 untuk akses HTTP).
- **Lupa kata sandi via kode OTP** (`lupa_sandi.php`): alur 3 langkah, kode 6 angka sekali pakai lewat surel; seluruh sesi dicabut setelah kata sandi berganti.
- Token akun lama (verifikasi/reset lewat tautan) tetap didukung untuk tautan lama; disimpan ter-hash (SHA-256), sekali pakai, kedaluwarsa.
- Diskusi per laporan **laporan<->mentor** (`komentar_laporan`) dengan notifikasi otomatis.
- Ekspor CSV daftar pengguna (`profil.php?ekspor=pengguna`) dan jejak audit (`riwayat_audit.php?ekspor=csv`).
- **Absensi** mencatat *kegiatan hari ini* dan *kondisi kesehatan* (swa-laporan) selain jam masuk/pulang.
- **Aksi massal laporan** (`mentor_dashboard.php` -> `mentor_bulk.php`): tandai diperiksa / minta revisi banyak laporan sekaligus; audit & notifikasi tetap per laporan.
- **Foto profil opsional** (`profil.php`): unggah JPG/PNG/WEBP maks. 2 MB. Nama berkas acak, MIME divalidasi dari isi berkas, disimpan di `uploads/avatar/` (PHP tidak dapat dieksekusi di sana). Tanpa foto, avatar memakai inisial berwarna sesuai peran.
- **Impor laporan Excel/CSV** (`impor_laporan.php`, **semua peran**): alur 3 langkah (unggah -> petakan kolom -> tinjau & simpan) dengan pemetaan kolom manual, profil pemetaan tersimpan, dedup per (pengguna, tanggal, kegiatan), dan riwayat impor (`impor_sejarah`). **Karyawan** hanya dapat mengunggah data **miliknya sendiri** (kolom "Pengguna" disembunyikan dan pemilik dipaksa ke akun yang masuk); **mentor/admin** dapat mengunggah data semua karyawan serta berkas **SRM & CID**. Kolom **Garis Lintang/Bujur** opsional: bila kosong, koordinat dicari otomatis dari teks lokasi (master wilayah -> cache -> Nominatim) dan titiknya langsung **disorot di `peta.php`**. Berkas sementara di `backups/impor/` (403 via HTTP) dan dibersihkan otomatis.
- **Jadwal & pengingat kegiatan** (`jadwal.php`, semua peran): kalender bulanan, CRUD agenda milik sendiri (status rencana/selesai/batal), plus pengingat otomatis via `scripts\pengingat_jadwal.php` (notifikasi aplikasi + surel + WhatsApp).
- **Data sosial SRM & CID** (`srm.php`, `cid.php`, semua peran): tampilan ringkasan + penyaring + tabel berhalaman untuk kegiatan *Social Relations Management* dan program *Community Involvement/Development* (TJSL). Impor dari Excel lewat `impor_srm_cid.php` (admin). Data juga diekspos sebagai view Power BI.
- **Peta lokasi** (`peta.php`, semua peran): peta Leaflet gabungan seluruh data (laporan, absensi, jadwal, kasus, SRM, CID) dengan penyaring tahun/kabupaten/pencarian; formulir laporan & absensi dapat menandai titik (GPS/klik peta) atau mengetik alamat yang otomatis dicocokkan ke master wilayah. Data yang baru diunggah **disorot** (cincin berdenyut + banner) dan panel **Chart.js** menampilkan ringkasan sebaran & tren. Setiap baris punya tautan **Cek Lokasi** ke Google Maps.
- **Ekspor dataset Power BI** (`powerbi_ekspor.php`, admin): unduh lapisan view sebagai CSV per view, workbook `.xlsx` multi-lembar, arsip ZIP, atau `.pbids` (MySQL maupun **ODBC**). Panduan langkah-langkah ada di `panduan_powerbi.php` dan `powerbi\README.md`. Daftarkan DSN dengan `scripts\buat_dsn_powerbi.ps1`.
- **Alat AI baru**: `web_search` (SearXNG + cadangan DuckDuckGo) dan `daftar_jadwal`/`buat_jadwal`/`ubah_jadwal`/`hapus_jadwal`/`ingatkan_jadwal` (approval-gated).
- **Antarmuka & dasbor**: gaya terkonsolidasi di `assets/css/app.css` (alias digabung ke aturan asli, kode mati dibuang, `:focus-visible` + `prefers-reduced-motion` ditambahkan) tanpa mengubah warna korporat. Ikon memakai **SVG garis monokrom** (`ikon_sp()`) menggantikan emoji berwarna di **seluruh halaman** (dasbor, kerja harian, halaman masuk/OTP, sampai tautan "Cek Lokasi") agar tampilan lebih bersih; simbol geometris UI (`&#9776;` menu, `&#9662;` chevron, panah, centang) dipertahankan. Halaman pembuka `index.php` kini satu halaman informatif: hero dengan efek logo, ringkasan fitur, **Cara Kerja**, **Tanya Jawab**, dan **Kontak** (surel & telepon). Dasbor karyawan memakai kartu `.stat` terpadu + **Aksi Cepat** (bagian absensi menampilkan kolom **Sakit/Izin/Alpha**); dasbor mentor kini berpaginasi (25/hal.) dengan kepala tabel lengket, tampilan kartu di layar kecil, dan chip filter cepat; admin memilki dasbor sendiri `admin_dashboard.php`. Sidebar mendukung **mode mini**, **grup terlipat**, **pencarian menu**, skip-link, dan preferensi tersimpan di `localStorage`.

---

## 5. Pemeliharaan & operasional

### 5.1. Tugas berkala

Skrip pemeliharaan membersihkan token akun basi dan (opsional) memangkas
audit/notifikasi lama:

```bat
php tools\pemeliharaan.php
php tools\pemeliharaan.php --hari-audit=365 --hari-notifikasi=90
```

Jadwalkan harian via **Task Scheduler**:

| Proyek | Waktu | Perintah |
|---|---|---|
| Bersih token & log | harian 02:00 | `C:\xampp\php\php.exe "C:\xampp\htdocs\pelaporan\tools\pemeliharaan.php"` |
| Cadangan DB | harian 01:30 | `C:\xampp\htdocs\pelaporan\tools\backup_db.bat --simpan=30` |
| Pengingat jadwal | tiap 5 menit | `C:\xampp\php\php.exe "C:\xampp\htdocs\pelaporan\scripts\pengingat_jadwal.php"` |

Padanan cron (Linux):

```cron
30 1 * * * php /var/www/pelaporan/tools/backup_db.php --simpan=30
0  2 * * * php /var/www/pelaporan/tools/pemeliharaan.php --hari-audit=365
*/5 * * * * php /var/www/pelaporan/scripts/pengingat_jadwal.php
```

Setelah aplikasi berjalan di atas **Laravel**, gunakan Laravel Scheduler:
satu tugas sistem menjalankan `scripts\jalankan_penjadwal.cmd` tiap menit
(`php artisan schedule:run`). Perintah `pengingat:harian` (porting
`scripts\pengingat_harian.php`) terdaftar harian 08:00 di `routes/console.php`:

```bat
C:\xampp\php\php.exe artisan pengingat:harian --kering   REM uji tanpa mengirim
C:\xampp\php\php.exe artisan schedule:list              REM lihat jadwal

schtasks /Create /SC MINUTE /MO 1 /TN "PHR Penjadwal" ^
  /TR "C:\xampp\htdocs\pelaporan\scripts\jalankan_penjadwal.cmd"
```

### 5.2. Cadangan basis data

```bat
php tools\backup_db.php --simpan=30
```

Hasil disimpan di `backups/pelaporan_YYYYmmdd_HHMMSS.sql` (direktori
dilindungi `.htaccess` → 403, dan diabaikan Git). Kata sandi dilewatkan lewat
`MYSQL_PWD` agar tidak tampak di daftar proses. Pulihkan dengan:

```bat
mysql -u pelaporan_app -p pelaporan < backups\pelaporan_20261016_013000.sql
```

Untuk lingkungan Laravel tersedia `scripts\backup_db.ps1` (+ `backup_db.cmd`)
yang membaca kredensial dari `.env`, menjalankan
`mysqldump --single-transaction`, menyimpan ke
`backups\pelaporan-YYYYMMDD-HHmmss.sql`, mencatat ke `backups\backup.log`, dan
merotasi berkas lebih tua dari 30 hari:

```bat
scripts\backup_db.cmd                 REM simpan 30 hari
scripts\backup_db.cmd -HariSimpan 14 -Tujuan D:\backups
```

### 5.3. Layanan AI (opsional)

Ringkasan cepat:

```bat
cd ai-service
python -m venv .venv && .venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env    REM isi AI_SERVICE_KEY (harus sama dengan config_lokal.php)
uvicorn app:app --host 127.0.0.1 --port 8000
```

Bila layanan mati, fitur AI dinonaktifkan otomatis dan aplikasi tetap berjalan.
Pantau statusnya di **Status Sistem** (`status.php`, khusus admin).

### 5.4. Pemecahan masalah

| Gejala | Penyebab & solusi |
|---|---|
| Halaman putih / error 500 | Periksa `php -l` berkas terkait dan `logs/`. Pastikan `includes/config_lokal.php` ada. |
| "Koneksi database gagal" | Cek `DB_HOST/DB_USER/DB_PASS` di `config_lokal.php`; pastikan MariaDB berjalan. |
| Surel/verifikasi tak terkirim | Isi `SMTP_*`; bila kosong, surel ditulis ke log pengembangan. Buka `status.php`. |
| Login diblokir "akun belum aktif" | Setujui di **profil.php → Administrasi Pengguna**, atau klik tautan verifikasi. |
| Terlalu banyak percobaan login | Tunggu masa lockout atau jalankan `tools\pemeliharaan.php`. |
| AI "tidak aktif" | Jalankan layanan AI; pastikan `AI_SERVICE_KEY` sama di kedua sisi. |

---

## 6. Pengujian

Lint semua berkas PHP:

```bat
php -l includes/*.php *.php
```

Jalankan unit + integrasi test (PHPUnit):

```bat
vendor\bin\phpunit
```

Uji coba terperinci: lihat `docs/UJI_COBA.md`.
