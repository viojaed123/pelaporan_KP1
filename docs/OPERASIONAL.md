# Panduan Operasional

Ringkasan tugas rutin untuk pengelola sistem. Lihat juga
[KEAMANAN.md](KEAMANAN.md) dan [PANDUAN_DEPLOY.md](PANDUAN_DEPLOY.md).

---

## 1. Menyalakan / mematikan layanan AI

Layanan AI (FastAPI + Gemini/Ollama) berjalan di `http://127.0.0.1:8000`.

| Aksi | Cara |
|---|---|
| Nyalakan | jalankan `ai-service\run.ps1` atau `ai-service\jalankan-ai.cmd` |
| Cek | buka `http://127.0.0.1:8000/health` → `{"ok":true,...}` |
| Matikan | tutup proses `python` di Task Manager, atau hentikan pemakai port 8000 |

Agar otomatis saat login, salin `ai-service\jalankan-ai.cmd` ke folder Startup:

```bat
copy ai-service\jalankan-ai.cmd "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\"
```

Bila layanan mati, aplikasi tetap berjalan; tombol/panel AI akan melapor
"layanan tidak tersedia".

---

## 2. Pengingat laporan harian

Skrip: `scripts\pengingat_harian.php` (idempoten — dijalankan dua kali untuk
tanggal sama tidak menggandakan notifikasi).

```bat
REM uji tanpa mengirim
C:\xampp\php\php.exe scripts\pengingat_harian.php --kering

REM kirim sungguhan untuk tanggal tertentu
C:\xampp\php\php.exe scripts\pengingat_harian.php --tanggal=2026-10-16
```

Terdaftar sebagai tugas Task Scheduler **"Pelaporan Harian - Pengingat Harian"**
(harian 08:00). Kelola:

```bat
schtasks /Query  /TN "Pelaporan Harian - Pengingat Harian"
schtasks /Run    /TN "Pelaporan Harian - Pengingat Harian"
schtasks /Delete /TN "Pelaporan Harian - Pengingat Harian" /F
```

Untuk memasang kedua tugas sekaligus (pengingat + layanan AI) sebagai SYSTEM
agar tetap berjalan tanpa login, jalankan **sebagai Administrator**:

```bat
pasang-tugas-penjadwal.cmd
```

Admin juga dapat mengirim pengingat manual dari menu **Pengingat** di aplikasi.

### 2.1 Penjadwal Laravel (lingkungan migrasi)

Setelah aplikasi dijalankan lewat Laravel, pengingat harian memakai perintah
Artisan **`pengingat:harian`** — porting dari skrip di atas, tetap idempoten:

```bat
REM uji tanpa mengirim
C:\xampp\php\php.exe artisan pengingat:harian --kering

REM kirim sungguhan untuk tanggal tertentu
C:\xampp\php\php.exe artisan pengingat:harian --tanggal=2026-10-16

REM lihat jadwal yang terdaftar
C:\xampp\php\php.exe artisan schedule:list
```

Penjadwalan ditangani Laravel Scheduler: satu tugas sistem menjalankan
`scripts\jalankan_penjadwal.cmd` **tiap menit** (memanggil
`php artisan schedule:run`). Perintah `pengingat:harian` dijadwalkan harian
pukul 08:00 (lihat `routes/console.php`):

```bat
schtasks /Create /SC MINUTE /MO 1 /TN "PHR Penjadwal" ^
  /TR "C:\xampp\htdocs\pelaporan\scripts\jalankan_penjadwal.cmd"
```

---

## 3. Pencadangan & pemulihan

Lewat aplikasi: menu **Cadangan** (admin). Cadangan disimpan di `backups/db/`
(maksimal 10 berkas terakhir). Pemulihan memerlukan konfirmasi teks `PULIHKAN`.

Hal yang perlu diketahui:

- Berkas cadangan **sudah dibersihkan dari `DEFINER=`** agar dapat dipulihkan
  oleh akun non-SUPER.
- Setiap berkas dicatat di tabel `backup_riwayat` (ukuran + checksum).
- Setelah memulihkan, 11 view Power BI tetap utuh.

### 3.1 Pencadangan terjadwal (lingkungan Laravel)

Skrip `tools\backup_db.php` (kanonik) menjalankan `mysqldump --single-transaction`
(aman saat basis data live), menyimpan ke
`C:\pelaporan-cadangan\pelaporan_YYYYmmdd_HHMMSS.sql`, dan merotasi berkas lebih
tua dari batas `--simpan`. Tambahkan `--log=` untuk mencatat, dan
`--sumber=env` untuk membaca kredensial dari `.env` (tidak ada sandi di dalam
skrip).

**Lokasi cadangan berada DI LUAR webroot** (`C:\pelaporan-cadangan`), karena
dump memuat data pribadi karyawan dan tidak boleh berada di pohon yang
disajikan Apache. Ubah lewat `--dir=PATH` atau variabel lingkungan
`PHR_BACKUP_DIR`.

```bat
REM manual (kredensial includes\config.php)
php tools\backup_db.php --simpan=30

REM dari sisi Laravel: kredensial .env + catat ke backup.log
php tools\backup_db.php --simpan=30 --sumber=env --log=backup.log

REM ke lokasi lain, simpan 14 hari
php tools\backup_db.php --simpan=14 --dir=D:\backups
```

Pemasangan harian pukul 23:30 (jalankan sebagai Administrator):

```bat
schtasks /Create /SC DAILY /ST 23:30 /TN "PHR Backup DB" ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\pelaporan\tools\backup_db.php --simpan=30 --sumber=env --log=backup.log"
```

Uji pemulihan ke basis data sementara — wajib minimal sekali (lihat
checklist `GO_LIVE.md` §2):

> **Catatan.** `tools\backup_db.php` menyebut nama basis data sebagai argumen
> posisi (bukan `--databases`), sehingga dump **tidak** memuat
> `CREATE DATABASE`/`USE`. Pulihkan langsung ke basis data tujuan:
> `mysql -u root pelaporan_uji < berkas.sql`.
>
> Sebagian dump lama (dari skrip `.ps1` yang sudah dihapus) **memuat** baris
> `CREATE DATABASE` dan `USE \`pelaporan\``; bila memakai berkas seperti itu,
> saring dulu baris tersebut agar tidak menimpa basis data **ASLI**:

```powershell
$mysql  = 'C:\xampp\mysql\bin\mysql.exe'
$src    = 'C:\pelaporan-cadangan\pelaporan-20261019-062821.sql'
$saring = "$env:TEMP\pelaporan_uji.sql"

& $mysql -u root -e "CREATE DATABASE `pelaporan_uji` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$baris = [System.IO.File]::ReadAllLines($src, [System.Text.Encoding]::UTF8)
$pilih = $baris | Where-Object { -not $_.StartsWith('CREATE DATABASE ') -and $_ -ne 'USE `pelaporan`;' }
[System.IO.File]::WriteAllLines($saring, [string[]]$pilih, (New-Object System.Text.UTF8Encoding $false))
cmd /c "$mysql -u root pelaporan_uji < `"$saring`""
```

Lalu bandingkan isi hasil pulih dengan aslinya, dan hapus basis data
sementara:

```powershell
$tabel = & $mysql -u root -N -B -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='pelaporan' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
$sql   = ($tabel | ForEach-Object { "SELECT '$_' AS t, COUNT(*) AS n FROM ``$_``" }) -join ' UNION ALL '
Compare-Object (& $mysql -u root -N -B -e $sql pelaporan) (& $mysql -u root -N -B -e $sql pelaporan_uji)
& $mysql -u root -e "DROP DATABASE IF EXISTS `pelaporan_uji`"
```

Baris keluaran `Compare-Object` = selisih. Selisih wajar bila memang ada
aktivitas setelah jam cadangan (mis. akun baru/terhapus). Uji terakhir
19 Okt 2026:46 tabel — selisih hanya pada7 tabel yang berubah *setelah*
jam dump;22 view identik.

---

## 4. Log

| Sumber | Lokasi |
|---|---|
| Audit aplikasi | tabel `audit_log` (menu **Riwayat Perubahan**) |
| Email mode pengembangan | `backups/mail-log/YYYY-MM-DD.log` |
| Galat PHP | log Apache/PHP (`xampp\apache\logs\error.log`) |
| Log Laravel (API/SPA/APK) | `storage/logs/laravel-YYYY-MM-DD.log` (kanal `daily`, retensi 14 hari; `LOG_CHANNEL=daily` di `.env`) |

Email mode pengembangan aktif saat `SMTP_HOST` kosong. Isi `SMTP_*` di
`includes/config.php` untuk pengiriman sungguhan.

---

## 5. Pemecahan masalah singkat

| Gejala | Periksa |
|---|---|
| Halaman memantul ke login | sesi kedaluwarsa; masuk ulang |
| Panel AI "tidak tersedia" | layanan di port 8000; `AI_SERVICE_KEY` sama di `config.php` & `.env` |
| Notifikasi laporan tidak terkirim | setelan `SMTP_*` (email) / `WA_GATEWAY`+`WA_API_KEY` (WhatsApp) |
| Pengingat tidak jalan | tugas Task Scheduler aktif; uji `--kering` |
| Power BI gagal konek | akun `bi_reader`, view, MySQL Connector/NET terpasang |

---

## 6. Yang wajib dijaga

- Jangan menaruh sandi asli di berkas migrasi/dokumentasi — hanya di
  `includes/config.php` dan `ai-service/.env`.
- `APP_DEBUG` tetap `false` di produksi.
- Akun uji `tes_*@uji.local` **tidak boleh** ada di produksi.

---

## 7. Mengelola pengguna (tambah, ubah peran, hapus)

| Kebutuhan | Cara |
|---|---|
| Karyawan mendaftar sendiri | `register.php` — langsung `status=aktif`, peran `karyawan` |
| Buat / setel ulang akun admin | `C:\xampp\php\php.exe scripts\buat_admin.php --email=... --nama=... --sandi='...'` (ada `--paksa` bila sandi tidak lolos kebijakan) |
| Ubah peran jadi `mentor`/`admin` | Tidak ada menu webnya; lewat SQL: `UPDATE users SET role='mentor' WHERE id=<N>;` lalu pengguna keluar-masuk agar sesi memakai peran baru |
| Nonaktifkan / aktifkan akun | menu **Profil** (admin) — menyetel `status`; akun `menunggu`/`nonaktif` tidak dapat masuk, sesi lama dicabut. Admin tidak dapat mengubah status akunnya sendiri |
| Verifikasi akun manual | menu **Profil** (admin) → "verifikasi pengguna" untuk akun yang tersangkut `menunggu` (menyetel `status=aktif` + `email_verified_at`) |
| Setel ulang sandi pengguna lain | menu **Profil** (admin); sesi lama pengguna dicabut |
| Lihat / ekspor daftar pengguna | menu **Profil** (admin) → ekspor CSV |
| Audit akun & peran | `C:\xampp\php\php.exe artisan pengguna:audit` (read-only) |
| Hapus akun permanen | `C:\xampp\php\php.exe artisan pengguna:hapus <id>` (laporan) lalu `... --yakin` |

Catatan penting:

- Jalankan `pengguna:audit` sebelum **dan** sesudah mengubah akun. Nama
  karyawan ganda membuat pencocokan PIC/anggota kasus (diketik bebas) ambigu.
- `pengguna:hapus` menolak akun yang masih memiliki data bisnis (perlu
  `--paksakan`), **tidak** menghapus kasus orang lain di mana akun itu hanya
  PIC (hanya `penanggung_jawab_id` dikosongkan, nama ketikan tetap), dan
  mencatat penghapusan pada `audit_log`. Lakukan cadangan (§3) sebelum eksekusi.
- `login_locks`, token API (`personal_access_tokens`), dan sesi ikut dibersihkan
  otomatis oleh `pengguna:hapus`.

---

## 8. Verifikasi dua faktor (2FA)

| Kebutuhan | Cara |
|---|---|
| Aktifkan | **Profil** → "Aktifkan 2FA" → masukkan kode OTP6 digit yang dikirim ke surel |
| Kode cadangan |10 kode (format `XXXXXX-XXXX`) ditampilkan **sekali** saat aktivasi — minta pengguna menyimpannya |
| Masuk dengan kode cadangan | di halaman `dua_faktor.php`, masukkan kode cadangan alih-alih OTP |
| Buat ulang / nonaktifkan | **Profil**; menonaktifkan sekaligus menghapus kode cadangan lama |

- Kode cadangan disimpan sebagai **hash** (tabel `backup_codes`) — operator
  tidak dapat membaca ulangnya. Bila semuanya hilang: buat ulang lewat Profil
  (perlu masuk) atau setel ulang 2FA.
- **2FA membutuhkan SMTP nyata** (`SMTP_*` di `includes/config.php`); tanpa
  itu kode OTP hanya masuk log mode pengembangan `backups/mail-log/`.
- Aktivasi/nonaktif tercatat pada `audit_log` dan tabel `user_security`.
