# Tinjauan Struktur Repositori

> Disusun: 19 Oktober 2026 · Cakupan: seluruh pohon repositori kecuali
> `vendor/`, `node_modules/`, `.venv/`, dan isi riwayat git (diverifikasi
> terpisah).

## Ringkasan eksekutif

Repositori ini berisi **tiga aplikasi yang tumbuh di satu pohon**:

1. **Aplikasi web legacy** — ~70 halaman PHP datar di root, memakai
   `includes/bootstrap.php` + mysqli.
2. **Aplikasi Laravel 12** — kerangka di root yang sama, disajikan dari
   `public/`, dengan React SPA (`resources/js/react/`) + 37 blade + REST
   `/api/v1`.
3. **Aplikasi Android** — Kotlin/Jetpack Compose di `mobile/`, klien REST
   Laravel.

Arsitektur ini **berfungsi** (diperkuat `.htaccess` ganda dan pengujian dua
jalur), tetapi membawa utang teknis nyata: logika bisnis yang sama
diimplementasikan dua kali, dua sistem migrasi basis data, dua sumber
kredensial, dan salinan aset yang perlahan menyimpang. Dokumen ini memetakan
temuan, memilah mana yang **sudah diperbaiki**, mana yang **aman untuk
ditunda**, dan mana yang **harus segera ditindak**.

---

## A. Dua kerangka aplikasi dalam satu webroot — RISIKO SEDANG

**Bukti.**
- Halaman legacy (`dashboard.php`, `login.php`, …) berada di root yang sama
  dengan berkas kerangka Laravel (`artisan`, `composer.json`, `app/`,
  `routes/`, `resources/`).
- Dua titik masuk: root `.htaccess` menyajikan aplikasi legacy;
  `public/.htaccess` menyajikan Laravel (SPA di `/app`, API di `/api/v1`).
- Root `.htaccess` kini melarang `app|bootstrap|config|routes|resources|
  storage|tests|vendor|database|ai-service|tools|mobile|node_modules|lang|
  backups|docs|includes` serta `.git`, `.env.*`, `*.sql`, `*.md`.

**Penilaian.** Pengerasan akses HTTP sudah memadai — ketiga aplikasi tidak
saling menembus lewat web. Bahaya tersisa bersifat **operasional**: developer
asing (atau kita sendiri) dapat "menyelipkan" berkas PHP baru di root dan
langsung terekspos tanpa melalui routing Laravel; dan penambahan direktori
baru wajib diingatkan ke `.htaccess` secara manual.

**Rekomendasi.**
1. *(Jangka panjang, P2)* Pindahkan halaman legacy ke subfolder khusus
   (mis. `legacy/`) yang menjadi satu-satunya `DocumentRoot` aplikasi lama —
   root repositori berhenti menjadi webroot. Butuh penyesuaian `require`
   ~70 berkas, jadi lakukan hanya saat ada jeda rilis besar.
2. *(Sekarang, sudah dilakukan)* Daftar blokir `.htaccess` diperbarui:
   `tools`, `mobile`, `node_modules`, `lang` ditambahkan; `*.md`/`*.sql`
   ikut diblokir.
3. *(Sekarang, sudah dilakukan)* `/bootstrap/cache/*.php` masuk
   `.gitignore` agar cache framework tidak ter-commit.

## B. Duplikasi logika bisnis — RISIKO SEDANG-TINGGI

**Bukti.**

| Lapis | Legacy PHP | Laravel |
|---|---|---|
| Kontroler | halaman flat + `includes/*.php` | `app/Http/Controllers/*` **dan** `app/Http/Controllers/Api/*` |
| Nama ganda | — | `AbsensiController`, `AuthController`, `KasusController`, `LaporanController`, `NotifikasiController`, `RingkasanController`, `TugasController` ada di **kedua** namespace |
| Tampilan | `includes/layout.php` + `app.css` | 37 blade + **React SPA** (`resources/js/react/`) + aplikasi Compose |
| Migrasi DB | `database/schema/legacy/*.sql` (25 berkas) | `database/migrations/*.php` (8+ berkas) |
| Uji | `phpunit-legacy.xml` + `tests/legacy/*` | `phpunit.xml` + `tests/Feature|Unit` |
| Koneksi DB | `koneksi.php` (mysqli, konstanta `includes/config.php`) | `config/database.php` (Eloquent, `.env`) |

**Penilaian.** Ketiganya (PHP, SPA, Android) memang klien dari **satu**
basis data `pelaporan` — sumber kebenaran tunggal sudah benar. Namun aturan
bisnis (mis. alur status laporan `draft → dikirim → diperiksa →
perlu_revisi`, aturan visibilitas kasus per peran) kini hidup di **tiga
tempat** dan harus dijaga seragam secara manual. Nama kontroler ganda di
dua namespace membingungkan saat navigasi cepat (`LaporanController` ada
dua kali dengan isi berbeda; `Api` punya `LaporanApiController` **dan**
`LaporanController`).

**Rekomendasi.**
1. *(P1 — murah)* Ganti nama `app/Http/Controllers/Api/LaporanController.php`
   menjadi nama yang mencerminkan perannya (mis. `LaporanMobileController`)
   untuk menghapus ambiguitas nama. Pemeriksaan cepat `routes/api.php` untuk
   rujukan.
2. *(P2)* Tetapkan **Laravel sebagai satu-satunya tujuan evolusi**: fitur
   baru hanya ditulis di Laravel; legacy dibekukan (perbaikan bug saja).
   Cantumkan di `README.md` bila belum.
3. *(P2)* Kredensial basis data kini di dua berkas (`includes/config_lokal.php`
   untuk legacy, `.env` untuk Laravel). Saat Go-Live, samakan akun DB keduanya
   dan catat di `docs/OPERASIONAL.md` bahwa keduanya wajib diganti bersamaan.
4. *(P3)* Migrasi legacy (`database/schema/legacy/`) tidak lagi berubah —
   tandai folder sebagai arsip baca-saja di `README`-nya (sudah ada).

## C. Aset yang terpecah: `assets/` vs `public/assets/` — RISIKO SEDANG

**Bukti.**
- `assets/` (2498 baris `app.css`) dipakai halaman legacy;
  `public/assets/` (2500 baris) dipakai blade/SPA. Saat diverifikasi, kedua
  `app.css` **sudah menyimpang**: isi `public/` punya tambahan pemetaan kelas
  SPA (`min-height: calc(100vh - var(--topbar-h))`) yang tidak ada di
  `assets/`, dan `assets/` masih berisi mojibake `�?"` pada komentar.
- `assets/js/app.js` dan `public/assets/js/app.js` masih identik.
- Chart.js 4.4.9 kini ter-vendor **dua salinan**:
  `assets/js/vendor/chart.umd.min.js` dan
  `public/assets/vendor/chart.umd.min.js` (206 KB masing-masing) — dipilih
  agar kedua aplikasi berdiri sendiri tanpa jalur silang.

**Penilaian.** Divergensi CSS bukti nyata bahwa dua salinan akan terus
menyimpang. Selama legacy masih hidup, pemisahan ini sulit dihindari, tetapi
bisa dikendalikan.

**Rekomendasi.**
1. *(Sekarang, sudah dilakukan)* Semua pemakaian Chart.js di halaman legacy
   (6 halaman) dipindahkan dari CDN ke vendor lokal + pembantu
   `assets/js/grafik.js`; blade memakai salinan `public/`.
2. *(P1)* Jadikan `public/assets/css/app.css` **satu-satunya** sumber CSS
   yang diedit; salinan `assets/` hanya menerima perubahan yang benar-benar
   dibutuhkan legacy, atau bahkan legacy diarahkan memuat
   `public/assets/css/app.css` langsung (kedua `DocumentRoot` menunjuk tree
   yang sama di XAMPP, jadi jalur relatif `../public/assets/...` berfungsi —
   tapi lebih rapi: simpan file induk di satu tempat lalu salin otomatis
   lewat skrip kecil saat build).
3. *(P2)* Hapus mojibake `�?"` di `assets/css/app.css` (komentar encoding
   Windows-1252) saat sentuhan berikutnya.

## D. Kebersihan repositori & webroot — RISIKO SEDANG

**Bukti.**
- `backups/` berada **di dalam webroot** dan berisi: 3 dump SQL ±500 KB
  (`pelaporan-2026*.sql`), log surat, `httpd-vhosts.conf.cadangan`,
  `httpd-uji-err.txt`, dua berkas draft commit (`pesan_commit_*.txt`), dan
  satu `.xlsx` acak. Dilindungi `backups/.htaccess` (deny-all) dan
  `.gitignore`, tetapi tetap berada di dalam pohon yang disajikan web.
- `node_modules/` (berat) ada di root — sudah diblokir `.htaccess` dan
  `.gitignore`.
- `pasang-tugas-penjadwal.cmd` tergeletak di root (alat sekali pakai).
- `.phpunit.result.cache` pernah ter-commit — **sudah dihapus** dan masuk
  `.gitignore`.
- **Riwayat git bersih**: pada seluruh 54 commit, `includes/config_lokal.php`,
  `.env`, dan `ai-service/.env` tidak pernah ter-commit; prefiks kunci API
  Gemini tidak ditemukan di commit mana pun.

**Rekomendasi.**
1. *(Sekarang, sudah dilakukan)* `.htaccess` root: blokir
   `tools|mobile|node_modules|lang`, `.git`, `*.sql`, `*.md`, `*.ps1`;
   `.gitignore`: `/bootstrap/cache/*.php`.
2. *(P1)* Pindahkan isi `backups/` ke **luar** webroot (mis.
   `C:\pelaporan-cadangan\`) dan ubah target `tools/backup_db.php` +
   `backup.php` ke sana. Dump berisi data pribadi karyawan — meski
   deny-all, prinsip "jangan simpan data sensitif di webroot" harus
   dipenuhi. Minimal: bersihkan berkas sampah (`httpd-uji-*`,
   `pesan_commit_*.txt`, `.xlsx` acak) yang tidak lagi berguna.
3. *(P2)* Pindahkan `pasang-tugas-penjadwal.cmd` ke `tools/`.

## E. Konfigurasi AI — RISIKO RENDAH (sudah ditangani)

**Bukti.**
- Rantai model kini: **Gemini `gemini-3.8-flash` → `gemini-3.5-flash-lite` →
  Ollama `qwen2.5:7b` → `qwen2.5:3b` → deterministik** pada `ai-service`
  (`app/llm.py`, `app/gemini_client.py`), dan `LLM_*` (endpoint kompatibel
  OpenAI Gemini) pada Laravel untuk SPA/APK — kedua permukaan.
- `web_search` berurutan: Google Search grounding Gemini → SearXNG →
  DuckDuckGo, dengan kutipan `sumber` dipertahankan.
- 78 uji pytest lulus tanpa jaringan; `/health` live `gemini:true`.
- Dokumen (`README.md`, `docs/INTEGRASI_AI.md`, `docs/SEARXNG.md`,
  `ai-service/README.md`, skill SKILL.md, `docs/OPERASIONAL.md`,
  `docs/KEAMANAN.md`, `docs/PANDUAN_DEPLOY.md`) sudah seragam Gemini-utama.

**Tindakan wajib pengguna (di luar repositori).**
1. **Rotasi kunci Gemini** — kunci `AQ.Ab8RN…` pernah ditampilkan dalam
   percakapan. Buat kunci baru di https://aistudio.google.com/apikey lalu
   ganti `AI_GEMINI_API_KEY` (`ai-service/.env`) dan `LLM_API_KEY`
   (`.env` root). Kedua berkas sudah di-`.gitignore`.
2. Bila memakai SearXNG, pastikan `AI_SEARXNG_URL` terisi agar cadangan
   pencarian aktif saat kuota Gemini Search dibatasi (galat 429).

## F. Prioritas ringkas

| # | Temuan | Prioritas | Status |
|---|---|---|---|
| 1 | Rotasi kunci Gemini (pernah tampil di chat) | **P1 — pengguna** | menunggu pengguna |
| 2 | SQL dump di dalam webroot `backups/` + sampah | **P1** | didokumentasikan |
| 3 | Ambiguitas nama `Api/LaporanController` vs `Web/LaporanController` | **P1** | didokumentasikan |
| 4 | Divergensi `assets/` vs `public/assets/` CSS | **P1** | dibatasi (Chart.js ter-vendor, SPA punya tambahan sah) |
| 5 | Dua kredensial DB (`config_lokal.php` + `.env`) | **P2** | didokumentasikan |
| 6 | Legacy dibekukan; evolusi hanya di Laravel | **P2** | rekomendasi kebijakan |
| 7 | Halaman legacy pindah dari root webroot | **P3** | tunda sampai rilis besar |
| 8 | Mojibake komentar CSS legacy; skrip `.cmd` di root | **P3** | didokumentasikan |

## G. Perbaikan higiene yang sudah dieksekusi pada peninjauan ini

- `.htaccess` root: blokir `tools`, `mobile`, `node_modules`, `lang`,
  `.git`, `*.sql`, `*.md`, `*.ps1`, `.env.*` (sebagian sudah ada, kini
  lengkap).
- `.gitignore`: `/bootstrap/cache/*.php`; `.phpunit.result.cache` dihapus
  dari tracking.
- `README.md`: 21 perintah impor basis data diarahkan ke jalur benar
  `database\schema\legacy\` (sebelumnya `database\migrations\` yang tidak
  berisi berkas SQL itu).
- Chart.js 4.4.9 di-vendor lokal (legacy + Laravel) — tidak lagi bergantung
  CDN.
- Seluruh dokumentasi AI diselaraskan ke rantai Gemini → Ollama →
  deterministik; bagian Hermes dihapus (folder `hermes/` sudah tidak ada).
