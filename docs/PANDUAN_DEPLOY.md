# Panduan Deploy (Server Lain)

Dokumen ini memandu memasang **Sistem Pelaporan Kegiatan Harian** di mesin/server
baru. Untuk pemakaian sehari-hari lihat [PANDUAN_PENGGUNA.md](PANDUAN_PENGGUNA.md).

> **Ringkas alur:** siapkan alat → ambil kode → buat database & impor migrasi →
> isi kredensial (runtime) → pasang dependensi PHP → jalankan layanan AI →
> daftarkan penjadwal → uji.

---

## 1. Prasyarat

| Perangkat | Keterangan |
|---|---|
| Web server + PHP | PHP 8.1+ (ekstensi `mysqli`, `mbstring`, `gd`, `zip`, `curl`). XAMPP/Laragon memadai. |
| MariaDB/MySQL | 10.4+ |
| Composer | untuk `phpmailer`, `dompdf`, `phpspreadsheet` |
| Python | 3.10+ (hanya bila memakai layanan AI) |
| Ollama | model `qwen2.5:7b` (opsional; CPU 8–16 GB) |

---

## 2. Ambil kode

Letakkan folder proyek di document root web server, mis.
`C:\xampp\htdocs\pelaporan` (Windows) atau `/var/www/pelaporan` (Linux).

---

## 3. Database

```sql
CREATE DATABASE pelaporan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Impor migrasi berurutan (Windows, sesuaikan path `mysql`):

```bat
mysql -uroot < database\migrations\001_skema_awal.sql
REM ... 002, 003, 004 ...
mysql -uroot < database\migrations\010_user_db.sql
mysql -uroot < database\migrations\011_hak_akses_app.sql
mysql -uroot pelaporan < database\migrations\012_pengiriman_laporan.sql
mysql -uroot pelaporan < powerbi\sql\02_views.sql
```

> Migrasi `010` membuat akun `pelaporan_app` dan `bi_reader` dengan sandi
> penampung (`UBAH_SANDI_APP_INI` / `UBAH_SANDI_BI_INI`). **Ganti** dan samakan
> dengan nilai di langkah 4. Jangan menaruh sandi asli di berkas migrasi.

Buat akun administrator pertama:

```sql
INSERT INTO pelaporan.users (nama, email, password, role, divisi)
VALUES ('Administrator', 'admin@instansi.go.id',
        '<hash bcrypt dari password_hash() PHP>', 'admin', 'TI');
```

Buat hash sandi dengan PHP:

```bat
php -r "echo password_hash('SandiAwalYangKuat!', PASSWORD_DEFAULT), PHP_EOL;"
```

---

## 3.1. Ganti kata sandi akun database

```sql
ALTER USER 'pelaporan_app'@'localhost' IDENTIFIED BY 'SANDI_DB_BARU';
ALTER USER 'pelaporan_app'@'127.0.0.1' IDENTIFIED BY 'SANDI_APP_SAMA';
ALTER USER 'bi_reader'@'localhost'    IDENTIFIED BY 'SANDI_BI_ANDA';
-- sesuaikan/ tambah host lain sesuai kebutuhan koneksi TCP
```

---

## 4. Konfigurasi kredensial (hanya berkas runtime)

Nilai rahasia **hanya** boleh berada di berkas runtime berikut — jangan di
migrasi, dokumentasi, atau salinan yang dibagikan:

| Berkas | Yang diisi |
|---|---|
| `includes/config.php` | `DB_USER`, `DB_PASS`, `AI_SERVICE_KEY`, setelan `SMTP_*`, `WA_*` |
| `ai-service/.env` | `AI_SERVICE_KEY`, `AI_DB_APP_PASSWORD`, `AI_DB_READONLY_PASSWORD` |

`AI_SERVICE_KEY` **wajib sama** di kedua berkas. Setelah menaruh kode ke
server, berkas `ai-service/.env` sudah tercakup oleh `.gitignore` di folder itu.

Saat produksi, biarkan `APP_DEBUG` bernilai `false` di `includes/config.php`
(galat PHP tidak ditampilkan ke pengguna, tetap dicatat ke log server).

---

## 5. Dependensi PHP

```bat
cd C:\xampp\htdocs\pelaporan
composer install --no-dev --optimize-autoloader
```

---

## 6. Layanan AI (opsional)

```bat
cd ai-service
copy .env.example .env
REM sunting .env: AI_SERVICE_KEY + AI_DB_APP_PASSWORD + AI_DB_READONLY_PASSWORD
run.ps1
```

Layanan di `http://127.0.0.1:8000`; uji `GET /health` → `{"ok":true,...}`.
Agar otomatis saat login, salin pembuat jalan ke folder Startup (tanpa admin):

```bat
copy ai-service\jalankan-ai.cmd "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\"
```

---

## 7. Penjadwal (Windows)

Pengingat harian (kirim otomatis ke karyawan yang belum mengisi laporan):

```bat
schtasks /Create /TN "Pelaporan Harian - Pengingat Harian" ^
  /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\pelaporan\scripts\pengingat_harian.php\"" ^
  /SC DAILY /ST 08:00 /F
```

Agar berjalan walau tidak ada yang login, jalankan **sebagai Administrator**
dengan `/RU SYSTEM /RL HIGHEST` (lihat juga `docs/OPERASIONAL.md`) — atau pakai
pemasang sekali-klik `pasang-tugas-penjadwal.cmd` (Run as administrator).

---

## 8. Keamanan (checklist go-live)

- [ ] Hapus akun uji: `DELETE FROM users WHERE email LIKE 'tes\_%@uji.local';`
- [ ] `APP_DEBUG = false`
- [ ] `display_errors` efektif mati (dilakukan `bootstrap.php` saat `APP_DEBUG=false`)
- [ ] Akun `root` tidak dipakai aplikasi; sandi root diganti
- [ ] `pelaporan_app` hanya berhak pada `pelaporan`; `bi_reader` hanya VIEW
- [ ] `backups/`, `uploads/` tidak dapat diakses langsung (`.htaccess`)
- [ ] `AI_SERVICE_KEY`, `DB_PASS`, sandi `bi_reader` sudah bukan nilai contoh
- [ ] Kredensial hanya ada di `includes/config.php` & `ai-service/.env`
- [ ] Pencadangan otomatis & jadwal pengingat aktif

---

## 9. Power BI (opsional)

Impor `powerbi/pelaporan.pbids`, sambungkan ke `localhost` / `pelaporan`
dengan akun `bi_reader` (mode **Import**). Rincian di `powerbi/README.md`.

---

## 10. Uji setelah deploy

1. `GET /health` layanan AI → `ok:true`.
2. Buka `login.php`, masuk, buka `dashboard.php`.
3. Jalankan `php scripts\pengingat_harian.php --kering` → tanpa galat.
4. Jalankan sapuan per peran (lihat [UJI_COBA.md](UJI_COBA.md)).
