# AGENTS.md — Sistem Pelaporan Kegiatan Harian PHR

## Arsitektur (penting)
- **Hibrida**: Laravel 12 (SPA React + API) + aplikasi flat-PHP lama. Keduanya berbagi database yang sama.
- **Laravel entry**: `artisan`, `bootstrap/app.php`, rute di `routes/web.php`/`routes/api.php`, middleware custom `App\Http\Middleware\{BatasSesi,CekPeran}`.
- **Legacy entry**: file `.php` di root + `includes/`. Setiap halaman wajib `require_once __DIR__.'/includes/bootstrap.php'` (urutan: config → session → koneksi → helpers → csrf → auth → helper lain → layout).
- **Migrasi bertahap (strangler-fig)**: hal baru pindah ke Laravel; halaman lama tetap jalan. Jangan menghapus file legacy tanpa memastikan rute Laravel sudah menggantikannya.
- **Frontend**: Vite + Tailwind CSS 4, React 19 di `resources/js/react/` (SPA). Aset dibangun dengan `npm run build`.

## Perintah pengembang (wajib tahu)
```bat
composer install          # PHP deps
npm install               # JS deps
npm run dev               # dev server Vite
npm run build             # build aset React/SPA
```

**Pengujian:**
```bat
vendor\bin\phpunit                        # Laravel tests (phpunit.xml, uses sqlite :memory:)
composer test                              # sama seperti di atas (clear config + artisan test)
composer test:legacy                      # tes legacy (phpunit-legacy.xml, bootstrap tests/legacy/bootstrap.php)
composer test:all                         # keduanya
php -l includes/*.php *.php              # lint cepat file penting
```

**Setup awal:**
```bat
composer setup                            # install + copy .env + key:generate
copy includes\config_lokal.example.php includes\config_lokal.php  # isi DB_PASS, SMTP_*, dll (diabaikan git)
```

**Jadwal/operasional (Windows):**
```bat
schtasks /Create /SC MINUTE /MO 1 /TN "PHR Penjadwal" /TR "C:\xampp\htdocs\pelaporan\scripts\jalankan_penjadwal.cmd"  # artisan schedule:run tiap menit
php tools\pemeliharaan.php                 # bersih token/audit (jadwalkan harian 02:00)
php tools\backup_db.php --simpan=30       # backup DB (jadwalkan harian 01:30)
php scripts\pengingat_jadwal.php          # tiap 5 menit
```

## Konfigurasi & rahasia
- **Rahasia tidak di `includes/config.php`** — pakai `includes/config_lokal.php` (dibuat dari contoh, dilindungi `.htaccess`). Wajib diisi untuk DB/app.
- **Laravel env**: `.env` (dari `.env.example`). Default testing pakai SQLite in-memory.
- **Log**: `.env.example` merekomendasikan `LOG_CHANNEL=daily` (retensi 14 hari). Jangan ubah ke `stack` tanpa alasan.
- **Sesi**: Laravel pakai `SESSION_DRIVER=database`. Legacy pakai `includes/session.php` — middleware `BatasSesi` menegakkan idle 30 menit + usia mutlak 8 jam di grup web Laravel.

## Batasan & gotcha
- **Legacy bootstrap**: `tests/legacy/bootstrap.php` mundur 2 level (`dirname(__DIR__,2)`) untuk require `includes/*`. Jangan pindahkan tanpa menyesuaikan.
- **Classmap**: `composer.json` memetakan `includes/` ke classmap — ubah file di `includes/` bisa butuh `composer dump-autoload`.
- **Strangler-fig**: `routes/web.php` dokumentasikan bahwa DocumentRoot beda. Jangan asumsikan semua halaman ada di Laravel.
- **Auth/role**: Di Laravel, daftarkan `auth` dulu baru `role:*` agar user belum login diarahkan ke `/login` (bukan 403). Lihat `bootstrap/app.php`.
- **2FA/OTP**: Halaman `/dua-faktor` bisa diakses tanpa `auth` (pakai penanda sementara). Verifikasi login bisa butuh OTP.
- **Legacy helpers**: Banyak helper di `includes/*.php` dipakai kedua sisi via classmap/autoload files.
- **Power BI/AI/Mobile**: Fitur terpisah (`powerbi/`, `ai-service/`, `mobile/`). Lihat README masing-masing jika menyentuhnya.

## Struktur kunci
- `app/Http/Controllers/`, `app/Http/Middleware/` — kode Laravel baru
- `routes/` — rute Laravel
- `resources/js/react/` — SPA React; entry Vite di `resources/js/app.js`, CSS di `resources/css/app.css`
- `includes/` — inti legacy (bootstrap, auth, session, helpers, impor, jadwal, dll)
- `tests/Feature/`, `tests/Unit/` — Laravel; `tests/legacy/` — legacy PHPUnit
- `database/schema/legacy/` — SQL migrasi awal; `database/migrations/` — migrasi Laravel
- `scripts/`, `tools/` — utilitas CLI/cron
