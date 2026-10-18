# Checklist Go-Live — Migrasi Laravel + React SPA + APK

Dokumen ini melengkapi `docs/PANDUAN_DEPLOY.md` (checklist keamanan aplikasi
lama) dengan hal-hal **baru** yang muncul setelah migrasi bertahap
(strangler-fig): Laravel 12, React SPA di `/app`, API `/api/v1`, dan APK
Android. Tandai `[x]` bila sudah beres.

> Prinsip: aplikasi lama di `localhost` tetap hidup sampai semua kotak di bawah
> tercentang, sehingga **selalu ada jalur mundur**.

---

## 1. HTTPS / TLS  *(wajib sebelum publik)*

- [ ] Sertifikat TLS terpasang untuk nama domain internal (mis. `pelaporan.phr.co.id`).
- [ ] Apache XAMPP: aktifkan `mod_ssl`, buat vhost `:443` yang menunjuk ke
      `C:\xampp\htdocs\pelaporan\public`, dan alihkan HTTP→HTTPS.
- [ ] `APP_URL` di `.env` memakai `https://…`.
- [ ] Set `SANCTUM_STATEFUL_DOMAINS` / `SESSION_SECURE_COOKIE=true`.
- [ ] **APK**: ganti `BASE_URL` ke `https://…` dan hapus
      `android:usesCleartextTraffic="true"` dari `AndroidManifest.xml`, lalu
      build ulang.
- [ ] Uji: buka `https://…/app` → SPA memuat tanpa peringatan campuran konten.

> **Sudah tersedia:** contoh vhost HTTPS siap sunting di
> `scripts\vhost_https.conf.example` — memuat pengalihan HTTP→HTTPS, `DocumentRoot`
> ke `public/`, header keamanan dasar, dan catatan sertifikat. Salin isinya ke
> `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, aktifkan `mod_ssl`, lalu
> restart Apache.

## 2. Pencadangan basis data terjadwal

- [x] Skrip dump harian `mysqldump` (semua tabel; DB `pelaporan`) —
      `scripts\backup_db.cmd`/`.ps1`, sudah diuji19 Okt 2026
      (46 tabel +22 view,0,48 MB).
- [ ] Simpan ke `backups/` **dan** salin ke lokasi lain (NAS/luar server).
      (*Skrip mendukung `-Tujuan <path>`; penyalinan efektif menunggu
      kredensial akses NAS.*)
- [ ] `schtasks` harian, mis. 23:30, `mysqldump --single-transaction`.
      (*Butuh izin admin Windows; jalankan `scripts\jalankan_penjadwal.cmd`
      sebagai Administrator.*)
- [x] Uji **pulihkan** dari cadangan ke DB sementara, minimal sekali.
      *(19 Okt 2026: `pelaporan-20261019-062821.sql` dipulihkan ke
      `pelaporan_uji_restore` lalu dibandingkan baris per tabel:46 tabel —
      selisih hanya7 tabel yang memang berubah SETelah jam cadangan
      (pembersihan akun ganda),39 tabel identik,22 view =22.
      DB sementara dihapus kembali.)*
- [x] Simpan cadangan minimal30 hari (rotasi) — default
      `backup_db.ps1 -HariSimpan 30` menghapus cadangan lebih tua.

Contoh (sesuaikan user/sandi):
```bat
mysqldump --single-transaction -u pelaporan_app -p"***" pelaporan > D:\backups\pelaporan-%DATE%.sql
```

> **Sudah tersedia:** `scripts\backup_db.ps1` (+ `scripts\backup_db.cmd`)
> membuat dump `mysqldump --single-transaction` ke
> `backups\pelaporan-YYYYMMDD-HHmmss.sql`, mencatat ke `backups\backup.log`,
> dan merotasi berkas > 30 hari. Kredensial dibaca dari `.env`.
> Pasang harian 23:30 (Administrator):
> ```bat
> schtasks /Create /SC DAILY /ST 23:30 /TN "PHR Backup DB" ^
>   /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\pelaporan\scripts\backup_db.ps1"
> ```
> (Uji pemulihan: lihat `docs/OPERASIONAL.md` §3.1.)

## 3. SMTP surel organisasi

- [ ] `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
      `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` (bukan Gmail pribadi).
- [ ] OTP 2FA & notifikasi terkirim dari alamat resmi.
- [ ] Uji kirim OTP ke surel pengguna nyata.
- [ ] SPF/DKIM domain pengirim sudah benar (agar tidak masuk spam).

## 4. Penjadwal & antrean (cron)

- [ ] `php artisan schedule:run` dipanggil tiap menit (Task Scheduler / cron).
- [ ] `pengingat_harian.php` (lama) **atau** perintah Laravel padanannya aktif.
- [ ] Bila memakai queue: `php artisan queue:work` berjalan sebagai layanan.
- [ ] Verifikasi log: tugas terjadwal benar-benar tereksekusi.

> **Sudah tersedia:** perintah Laravel `pengingat:harian` (porting
> `scripts\pengingat_harian.php`, idempoten) dengan jadwal harian 08:00 di
> `routes/console.php`. Jalankan penjadwal tiap menit dengan memasang
> `scripts\jalankan_penjadwal.cmd`:
> ```bat
> schtasks /Create /SC MINUTE /MO 1 /TN "PHR Penjadwal" ^
>   /TR "C:\xampp\htdocs\pelaporan\scripts\jalankan_penjadwal.cmd"
> ```
> Uji: `php artisan pengingat:harian --kering` (tanpa mengirim).

## 5. Akun admin & peran

- [ ] Akun administrator resmi dibuat (bukan akun uji).
- [x] Semua akun uji dihapus: `DELETE FROM users WHERE email LIKE 'tes\_%@uji.local';`
      (verifikasi: `pengguna:audit` → "Akun uji (@uji.local): 0").
- [ ] Peran (`karyawan`/`mentor`/`admin`) sudah benar untuk tiap pengguna.
- [x] Akun `root` tidak dipakai aplikasi (`.env` `DB_USERNAME=pelaporan_app`
      dan `includes/config.php` `DB_USER=pelaporan_app`; `root` hanya ada
      sebagai nilai bawaan *fallback* `config/database.php` yang tertimpa).
- [x] Nama karyawan duplikat = 0 (`pengguna:audit`): nama ganda membuat
      pencocokan PIC/anggota kasus yang diketik bebas jadi ambigu.
      *(Dibersihkan 19 Okt 2026: akun ganda #46/#47/#48 dihapus dengan
      `pengguna:hapus`, #1 dipertahankan.)*
- [x] Token API pengguna lama dicabut bila perlu: `DELETE FROM personal_access_tokens;`
      (verifikasi: `pengguna:audit` → jumlah token 0).

> **Alat bantu (read-only):** `php artisan pengguna:audit` melaporkan sebaran
> peran & status, akun uji `@uji.local`, nama karyawan duplikat, akun belum
> aktif, dan jumlah token API — tanpa mengubah data. Nama duplikat penting
> karena PIC/anggota kasus diketik bebas lalu dicocokkan ke nama pengguna.
>
> **Pembersihan akun ganda:** `php artisan pengguna:hapus <id>` (tanpa opsi
> apa pun = laporan read-only, aman dijalankan kapan saja) lalu
> `php artisan pengguna:hapus <id> --yakin`. Akun yang masih memiliki data
> bisnis ditolak kecuali `--paksakan`; kasus di mana akun sekadar PIC tidak
> ikut terhapus (hanya `penanggung_jawab_id` dikosongkan, nama ketikan tetap).
> Seluruh operasi satu transaksi, tercatat pada `audit_log`, dan ada
> `scripts\backup_db.cmd` untuk cadangan sebelum eksekusi.

## 6. UAT (User Acceptance Test)

- [ ] Skenario harian karyawan lolos di **web** (`/app`) *dan* **APK**:
      masuk → isi laporan → check-in/out → kerjakan tugas.
- [ ] Skenario mentor/admin: rekap, verifikasi laporan, buat kasus (PIC &
      anggota diketik bebas, pencocokan otomatis benar).
- [ ] Hasil uji ditandatangani pemilik proses (PHR) — lihat `docs/UJI_COBA.md`.

## 7. Dokumentasi & SOP

- [x] `docs/PANDUAN_PENGGUNA.md` diperbarui (ada bagian SPA & APK).
- [x] `mobile/README.md` menjadi rujukan pemasangan APK (menyiapkan alamat
      server, membangun, tanda tangan rilis, peta endpoint).
- [x] SOP operasional: cara cadangkan, pulihkan, tambah pengguna, atur 2FA —
      `docs/OPERASIONAL.md` §3 (cadangan & pemulihan, termasuk peringatan
      `USE \`pelaporan\`` pada dump & uji pulihkan yang benar), §7 (mengelola
      pengguna: daftar, ubah peran, nonaktifkan, verifikasi manual,
      `pengguna:audit`/`pengguna:hapus`), §8 (2FA & kode cadangan), §4 (log).
- [ ] Kontak dukungan/escalation jelas. *(Butuh informasi organisasi: nama &
      kontak PIC sistem, jalur eskalasi layanan hosting/surel.)*

## 8. Pemantauan galat & kesehatan

- [x] Kanal `LOG_CHANNEL` diarahkan ke berkas/rotasi yang terpantau
      (`LOG_CHANNEL=daily` → `storage/logs/laravel-YYYY-MM-DD.log`,
      retensi `LOG_DAILY_DAYS=14`, `LOG_LEVEL=error`, `APP_DEBUG=false`).
      Disamakan pada `.env.example`. *Terverifikasi19 Okt2026: berkas
      harian terbentuk saat log ditulis.*
- [ ] Halaman `/health` (layanan AI) & rute diagnostik Laravel dipantau.
      (*Sudah disiapkan: rute bawaan Laravel `/up` menjawab `200 Application
      up` — diuji19 Okt2026 lewat `http://localhost/pelaporan/public/up`;
      `/health` layanan AI hanya relevan bila `LLM_BASE_URL` diaktifkan —
      periksa lewat `status.php` (perlu masuk).*)
- [ ] Pengingat melihat `storage/logs/laravel-YYYY-MM-DD.log` berkala
      (kanal daily; berkas lama `laravel.log` milik kanal `stack` lama).
- [ ] (Opsional) Integrasi pelaporan error (mis. Sentry) bila diizinkan.

## 9. Penutup: matikan jalur lama

Setelah semua di atas tercentang **dan** tim nyaman:

- [ ] Alihkan `localhost` dari aplikasi lama ke Laravel `public`.
- [ ] Arsipkan `legacy-backup/` di luar server (bukan di repositori).
- [ ] Umumkan ke pengguna bahwa alamat aplikasi berubah.
