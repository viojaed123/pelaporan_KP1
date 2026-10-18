# Panduan Pengguna

Menu yang tampil menyesuaikan peran. Halaman yang tidak boleh diakses tetap
dijaga di server (menghasilkan 403 walaupun URL ditikis manual).

---

## Antarmuka baru: SPA React (`/app`) & APK Android

Selain halaman lama (`*.php`), tersedia dua antarmuka baru yang memakai API
`/api/v1` dan **token Bearer** (bukan sesi PHP):

### SPA React — `https://<server>/app`

- Masuk dengan **surel + kata sandi**; token disimpan di peramban sehingga
  tetap masuk sampai keluar atau token dicabut.
- Menu menyesuaikan peran:
  - **Karyawan**: Dasbor, Buat Laporan, Riwayat, Absensi, Tugas Saya, Kasus,
    Ringkasan Kegiatan, Kalender Aktivitas, Asisten AI, Notifikasi, Profil.
  - **Mentor/Admin**: tambahan grup **Analitik** — Rekap Laporan, Rekap
    Absensi, Ringkasan Kegiatan, Kalender Aktivitas.
- Halaman rekap hanya untuk mentor/admin; karyawan yang membukanya langsung
  mendapat pesan penolakan (dan API tetap menjawab 403).
- Tampilan/warna sama dengan aplikasi lama (biru PHR), karena memakai berkas
  CSS yang sama.

### APK Android (native)

1. Pasang `mobile/app/build/outputs/apk/debug/app-debug.apk` (aktifkan
   "sumber tidak dikenal" bila perlu).
2. Di layar masuk, isi **Alamat Server** (mis. `http://192.168.56.1/` atau
   `https://pelaporan.phr.co.id/`). Nilai ini tersimpan di perangkat dan
   dapat diubah kapan saja.
3. Masuk dengan surel + kata sandi akun PHR. Fitur: laporan harian, absensi
   check-in/out, tugas, kasus, notifikasi, ringkasan, kalender.

> Untuk produksi, ganti alamat server ke `https://…` dan pasang APK yang
> dibangun tanpa `usesCleartextTraffic` (lihat `docs/GO_LIVE.md` §1).

---

## Karyawan

| Menu | Halaman | Fungsi |
|---|---|---|
| Dasbor | `dashboard.php` | ringkasan laporan, kasus, notifikasi, kalender kecil |
| Ringkasan | `ringkasan.php` | rekap mingguan/bulanan pribadi + narasi AI |
| Kalender | `kalender.php` | tampilan bulanan laporan & absensi pribadi |
| Buat Laporan | `buat_laporan.php` | isi laporan harian (tanggal, jam, kegiatan, deskripsi, hasil, kendala, rencana) |
| Riwayat | `riwayat.php` | daftar laporan + status |
| Revisi | `edit_revisi.php` | mengerjakan laporan yang ditandai *perlu revisi* |

> **Tips — Periksa kelengkapan (AI).** Sebelum menekan **Simpan Draft** /
> **Ajukan Revisi**, klik **✦ Periksa kelengkapan (AI)**. Asisten menilai
> laporan dan menampilkan skor 0–100, daftar bagian yang masih kurang, serta
> catatan. Laporan tetap dapat disimpan bila AI tidak aktif.

| Absensi | `absensi.php` | catat kehadiran/hadir/izin/sakit |
| Kasus | `dashboard_kasus.php`, `detail_kasus.php` | pantau kasus yang ditugaskan |
| Notifikasi | `notifikasi.php` | pemberitahuan laporan & pengingat |
| Asisten AI | `asisten.php` | tanya jawab seputar laporan (butuh layanan AI) |
| Profil | `profil.php` | ubah data diri, kata sandi, aktifkan 2FA |

**Status laporan:** `draft → dikirim → diperiksa`, atau `perlu_revisi → edit_revisi → dikirim` ulang.

---

## Mentor

Menu karyawan ditambah:

| Menu | Halaman | Fungsi |
|---|---|---|
| Dasbor | `mentor_dashboard.php` | rekap karyawan bimbingan |
| Analitik | `ringkasan.php` | rekap tim (peringkat karyawan, tren) |
| Analitik | `kalender.php` | kalender tim; filter per karyawan |
| Analitik | `distribusi_laporan.php` | **ajukan** pengiriman laporan via email/WhatsApp |
| Periksa | `detail_laporan.php` | terima atau minta revisi + catatan |
| Absensi | `mentor_absensi.php` | kehadiran karyawan bimbingan |
| Ekspor | `export_excel.php`, `export_pdf.php`, `export_word.php` | rekap siap kirim |
| Rekap | `rekap_laporan.php`, `rekap_absensi.php` | rekap resmi |

Mentor **tidak** dapat menyetujui pengiriman — hanya admin (403).

---

## Admin

Seluruh menu mentor ditambah:

| Menu | Halaman | Fungsi |
|---|---|---|
| Administrasi | `riwayat_audit.php` | riwayat perubahan & aksi |
| Administrasi | `backup.php` | buat, unduh, pulihkan, hapus cadangan |
| Administrasi | `pengingat.php` | kirim pengingat harian ke karyawan yang belum lapor |
| Administrasi | `distribusi_laporan.php` | **setujui & kirim** / tolak / kirim ulang |

### Menyetujui pengiriman

1. Mentor/admin membuat pengajuan → status **Menunggu persetujuan**.
2. Admin menekan **Setujui & kirim** → status menjadi **Terkirim** atau
   **Gagal** (galat ditampilkan).
3. **Tolak** mengubah status menjadi **Dibatalkan**; **Kirim ulang** tersedia
   untuk status `disetujui`/`gagal`.

### Pengingat otomatis

`pengingat.php` menghitung karyawan yang belum lapor pada tanggal terpilih
(melewati yang berstatus izin/sakit) lalu mengirim notifikasi. Pengiriman
**idempoten** — pengiriman ulang pada tanggal sama tidak menggandakan
notifikasi. Untuk pengiriman terjadwal, jalankan lewat Task Scheduler:

```
php C:\xampp\htdocs\pelaporan\scripts\pengingat_harian.php
```

Di lingkungan Laravel, gunakan perintah Artisan padanannya (tetap idempoten),
dijadwalkan harian 08:00 lewat Laravel Scheduler:

```
php artisan pengingat:harian --kering        REM uji tanpa mengirim
php artisan pengingat:harian                 REM kirim sungguhan
```

---

## Dua Faktor (semua peran)

1. `Profil → Keamanan → Aktifkan 2FA`.
2. Ambil kode 6 digit dari email — pada mode pengembangan baca
   `backups/mail-log/YYYY-MM-DD.log`.
3. Masukkan kode → 2FA aktif. Sejak itu setiap login memerlukan kode sekali
   pakai, **atau** salah satu kode cadangan `XXXXXX-XXXX`.
4. Simpan kode cadangan di tempat aman; tiap kode hanya dapat dipakai sekali.

---

## Mode pengembangan

| Bila | Maka |
|---|---|
| `SMTP_HOST = ''` | surel (OTP, notifikasi) ditulis ke `backups/mail-log/` |
| `WA_GATEWAY = ''` | kanal WhatsApp dilaporkan **nonaktif**, bukan error |
| `AI_AKTIF = false` | tombol AI disembunyikan |

Ganti ketiganya di `includes/config.php` (atau `config_lokal.php`) untuk produksi.
