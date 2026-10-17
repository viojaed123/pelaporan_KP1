# Panduan Operasional

Ringkasan tugas rutin untuk pengelola sistem. Lihat juga
[KEAMANAN.md](KEAMANAN.md) dan [PANDUAN_DEPLOY.md](PANDUAN_DEPLOY.md).

---

## 1. Menyalakan / mematikan layanan AI

Layanan AI (FastAPI + Ollama) berjalan di `http://127.0.0.1:8000`.

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

---

## 3. Pencadangan & pemulihan

Lewat aplikasi: menu **Cadangan** (admin). Cadangan disimpan di `backups/db/`
(maksimal 10 berkas terakhir). Pemulihan memerlukan konfirmasi teks `PULIHKAN`.

Hal yang perlu diketahui:

- Berkas cadangan **sudah dibersihkan dari `DEFINER=`** agar dapat dipulihkan
  oleh akun non-SUPER.
- Setiap berkas dicatat di tabel `backup_riwayat` (ukuran + checksum).
- Setelah memulihkan, 11 view Power BI tetap utuh.

---

## 4. Log

| Sumber | Lokasi |
|---|---|
| Audit aplikasi | tabel `audit_log` (menu **Riwayat Perubahan**) |
| Email mode pengembangan | `backups/mail-log/YYYY-MM-DD.log` |
| Galat PHP | log Apache/PHP (`xampp\apache\logs\error.log`) |

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
