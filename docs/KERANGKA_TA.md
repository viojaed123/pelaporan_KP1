# Kerangka Laporan Tugas Akhir

## Pengembangan Sistem Pelaporan Kegiatan Harian pada PT Pertamina Hulu Rokan (PHR) dengan Arsitektur Microservice Berbasis Large Language Model

> Dokumen ini adalah kerangka lengkap laporan tugas akhir (skripsi) untuk Sistem
> Pelaporan Kegiatan Harian PT Pertamina Hulu Rokan.
> Semua angka di bawah **dihasilkan dari kode proyek yang benar-benar ada**,
> bukan placeholder. Tanda `‹…›` berarti bagian yang wajib diisi dari data
> lapangan (tidak bisa diambil dari kode).

---

## Identitas Penelitian

| Komponen | Isi |
|---|---|
| **Judul utama** | Pengembangan Sistem Pelaporan Kegiatan Harian pada PT Pertamina Hulu Rokan (PHR) dengan Arsitektur Microservice Berbasis Large Language Model |
| **Jenis laporan** | Laporan tugas akhir (skripsi) — BAB I–V |
| **Kata kunci** | Sistem Pelaporan Kegiatan Harian; Arsitektur Microservice; Large Language Model; Function Calling; Role-Based Access Control; Strangler Fig Migration; Android; Power BI |
| **Objek penelitian** | `ai-service` (FastAPI) sebagai *gateway* tunggal LLM + integrasi 5 subsistem pada satu basis data |
| **Subjek/lokasi** | PT Pertamina Hulu Rokan (PHR) — Region Blok Rokan, Riau; lingkungan uji lokal (XAMPP, emulator Android, Ollama) |
| **Metode penelitian** | *Development Research* / Applied Research; SDLC *Prototyping* iteratif + *Strangler Fig* untuk migrasi |
| **Pendekatan** | Kualitatif (analisis kebutuhan, wawancara) + kuantitatif (UAT, uji performa, uji akurasi AI) |

### Angka acuan sistem (hasil inventarisasi kode)

| Aspek | Nilai | Sumber |
|---|---|---|
| Endpoint API (Laravel `/api/v1`) | **37** | `routes/api.php` |
| Endpoint AI lapisan legacy (PHP) | **3** | `api_chat.php`, `api_validasi.php`, `api_notifikasi_jumlah.php` |
| Halaman React SPA | **16** | `resources/js/react/pages/` |
| Layar Android | **11** | `mobile/.../ui/layar/` |
| Alat (*tools*) agen AI | **21** | `ai-service/app/tools/` |
| Berkas *prompt* sistem AI | **5** | `ai-service/app/prompts/` |
| Skill AI | **3** | `ai-service/skills/` |
| View analitik Power BI | **22** | `powerbi/sql/02_views.sql` |
| Tabel basis data | **47** (37 tabel legacy + 10 migrasi Laravel yang membuat 11 tabel) | `database/schema/legacy/`, `database/migrations/` |
| Berkas `includes/` (inti legacy) | **27** | `includes/` |
| Controller Laravel | **24** (14 web + 10 API) | `app/Http/Controllers/` |
| Model Eloquent | **37** | `app/Models/` |
| Service Laravel | **7** | `app/Services/` |
| Middleware Laravel | **2** | `app/Http/Middleware/` |
| Halaman PHP legacy di akar | **61** | `/*.php` |
| Peran pengguna | **3** (karyawan, mentor, admin) | `includes/auth.php`, `includes/layout.php` |
| Uji otomatis | **423** (182 Laravel + 163 legacy + 78 pytest) | `tests/`, `ai-service/tests/` |
| Halaman legacy dengan penjaga peran | **21** (`wajib_role()`) | `includes/auth.php` + halaman akar |

---

## Daftar Isi

```
BAB I    PENDAHULUAN
BAB II   TINJAUAN PUSTAKA
BAB III  METODE PENELITIAN
BAB IV   HASIL PEMBAHASAN
BAB V    PENUTUP
DAFTAR PUSTAKA
LAMPIRAN
```

---

# BAB I — PENDAHULUAN

## 1.1 Latar Belakang

Tiga lapisan narasi. Jangan langsung masuk "kita membuat aplikasi".

### 1.1.1 Latar belakang industri

Aktivitas lapangan PT Pertamina Hulu Rokan pada Blok Rokan membutuhkan pelaporan
yang **terukur, lintas lapangan, dan dapat diaudit**. Laporan kegiatan harian,
absensi, dan pelacakan kasus tersebar pada berkas Excel dan arsip manual.
Akibatnya pelaporan lambat, rekap menjadi subjektif, dan ketika terjadi
temuan, **tidak ada jejak digital** yang menunjukkan siapa mengubah apa dan kapan.

### 1.1.2 Latar belakang teknologi

Aplikasi yang telah berjalan dibangun sebagai aplikasi PHP *flat* (monolitik):
satu berkas per halaman, logika bercampur dengan tampilan, basis data diakses
langsung dari halaman. Pola ini sulit dirawat dan tidak mendukung integrasi
klien baru. Namun sistem **tidak boleh dihentikan** karena dipakai daily di
lapangan. Solusinya bukan menulis ulang (*big-bang rewrite*), melainkan
migrasi bertahap dengan pendekatan *strangler fig*.

### 1.1.3 Latar belakang intelijen buatan

Data pelaporan harian bersifat **unstruktur** — berupa narasi lapangan, bukan
baris tabel. Merekap, memvalidasi kelengkapan, dan menjawab pertanyaan
"kegiatan saya minggu lalu" secara manual memerlukan waktu dan menghasilkan
hasil yang berbeda antar-pengeriksa. Large Language Model (LLM) dapat
menyelesaikan pekerjaan tersebut, **hanya jika** dibatasi secara ketat:

- akses data harus mengikuti peran pengguna;
- tindakan berisiko (memulihkan basis data, mengirim surel/WhatsApp, mengubah
  jadwal) **tidak boleh** dijalankan otomatis;
- harus ada model cadangan lokal agar layanan tetap hidup saat jaringan
  korporat/proxy bermasalah.

### 1.1.4 Penutup latar belakang

Paragraf penghubung yang menyatukan tiga hal di atas menjadi rumusan
permasalahan, misalnya:

> "Berdasarkan uraian tersebut, PT Pertamina Hulu Rokan memerlukan sistem
> pelaporan yang tidak hanya mencentralisasi data, tetapi juga aman,
> terintegratif lintas platform, dan mampu mengubah data narasi lapangan
> menjadi informasi manajerial secara otomatis. Penelitian ini
> pengembangan sistem pelaporan kegiatan harian dengan arsitektur microservice
> yang mengintegrasikan lima subsistem pada satu basis data,
> dengan microservice AI sebagai gateway tunggal berbasis Large Language Model
> yang dilengkapi function calling, model cadangan lokal, dan penjaga hak akses
> berbasis peran."

**Tabel 1.1 — Permasalahan yang teridentifikasi**

| No. | Permasalahan | Dampak | Solusi yang dirancang |
|---|---|---|---|
| 1 | Rekap manual dari berkas Excel | Luas waktu pengawas, hasil subjektif | Microservice AI: ringkasan mingguan/bulanan otomatis |
| 2 | Akses data tanpa pembatasan peran | Risiko kebocoran data antar-divisi | RBAC 3 peran (`peta_akses()` + `wajib_role()` + `CekPeran`) |
| 3 | Sistem legacy monolitik sulit dirawat | Waktu perubahan fitur lama | Strangler fig: Laravel + React di atas basis data yang sama |
| 4 | Petugas lapangan sering terlambat melapor | Kepatuhan pelaporan rendah | Aplikasi Android 11 layar (Kotlin + Compose) + absensi check-in/out |
| 5 | Analitik terpisah dari sistem operasional | Duplikasi data & risiko akses | 22 SQL VIEW read-only + akun `bi_reader` untuk Power BI |
| 6 | Perubahan data tidak dapat ditelusuri | Tidak ada bukti akuntabilitas | `audit_log` + approval-gated pada tindakan AI |

## 1.2 Rumusan Masalah

> ⚠️ Bagian paling krusial. Penguji menilai **judul ↔ rumusan masalah ↔ hasil**.
> Rumusan wajib berbentuk "bagaimana" dan **benar-benar dijawab** di Bab IV.

| RM | Rumusan Masalah | Dijawab pada |
|---|---|---|
| RM-1 | Bagaimana merancang arsitektur integrasi yang menggabungkan lima subsistem (legacy PHP, Laravel + React, Android, microservice AI, Power BI) pada satu basis data secara konsisten tanpa menghentikan operasional? | 4.2 |
| RM-2 | Bagaimana membangun microservice AI sebagai *gateway* tunggal yang dilengkapi function calling atas 21 alat, rantai model utama–cadangan, dan pembatasan akses berbasis peran? | 4.3 |
| RM-3 | Bagaimana memastikan tindakan AI berisiko (pemulihan basis data, pengiriman surel/WhatsApp, perubahan jadwal dan laporan) tidak dapat dijalankan tanpa persetujuan eksplisit pengguna? | 4.3.5 |
| RM-4 | Bagaimana menerapkan RBAC tiga peran, jejak audit, autentikasi dua faktor, dan manajemen sesi secara konsisten pada seluruh subsistem? | 4.5 |
| RM-5 | Bagaimana mengukur efektivitas fungsi AI pada konteks pelaporan lapangan — ketepatan jawaban, waktu respons, dan keberhasilan mekanisme cadangan? | 4.8 |
| RM-6 | Bagaimana memvalidasi sistem secara fungsional, keamanan, performa, dan penerimaan pengguna? | 4.7, 4.9 |

## 1.3 Tujuan

1. Menganalisis kebutuhan pelaporan kegiatan harian pada PT Pertamina Hulu Rokan.
2. Merancang arsitektur integrasi lima subsistem dengan pendekatan *strangler fig*.
3. Membangun microservice AI berbasis LLM dengan 21 alat function calling dan rantai model cadangan.
4. Menerapkan RBAC tiga peran, jejak audit, 2FA/OTP, dan batas sesi lintas platform.
5. Membangun aplikasi Android untuk petugas lapangan dengan autentikasi paritas.
6. Menyediakan lapisan analitik *read-only* (23 VIEW) untuk Power BI.
7. Menguji efektivitas sistem dan fungsi AI melalui pengujian otomatis dan UAT.

## 1.4 Manfaat

### 1.4.1 Manfaat praktis

| Penerima | Manfaat |
|---|---|
| Petugas lapangan (karyawan) | Input laporan & absensi lebih cepat lewat Android; akses riwayat sendiri; status absensi otomatis |
| Mentor/Supervisor | Rekap laporan & absensi lintas karyawan otomatis; ringkasan mingguan dari data narasi |
| Administrator | Jejak audit lengkap; cadangan data otomatis; manajemen peran terpusat; Power BI untuk manajemen |
| PT Pertamina Hulu Rokan | Kepatuhan pelaporan meningkat; data terpusat dan dapat ditelusuri |

### 1.4.2 Manfaat akademik

- Contoh konkret implementasi **arsitektur microservice + LLM agent** pada
  industri migas, yang relatif jarang dik-studikan secara akademik.
- Menyumbang referensi praktis bagi tim pengembangan internal (*knowledge base*)
  untuk migrasi sistem legacy berikutnya.

### 1.4.3 Manfaat bagi pengembangan lanjutan

- Fondasi untuk *retrieval-augmented generation* (RAG) atas arsip SRM/CID.
- Fondasi prediksi kepatuhan pelaporan.
- Fondasi integrasi dengan sistem HR/SAP untuk validasi absensi.

## 1.5 Batasan Masalah

- **Objek**: aplikasi pelaporan kegiatan harian PT Pertamina Hulu Rokan,
  Region Blok Rokan, Riau.
- **Sifat penelitian**: *development research* atas sistem yang **sudah
  berjalan dan dipakai**, bukan pembuatan dari nol.
- **Strategi legacy**: halaman legacy **tidak ditulis ulang**; fokus pada
  integrasi dan migrasi bertahap.
- **Cakupan LLM**: Gemini `gemini-3.8-flash` (utama) dan Ollama `qwen2.5:7b`
  (cadangan lokal). Tidak mencakup model yang dilatih khusus (*fine-tuning*).
- **Tidak mencakup**: integrasi ERP, akuntansi, sistem HR pusat, dan
  penulisan ulang modul legacy yang belum dimigrasikan.
- **Pengujian**: black-box otomatis + UAT internal; **bukan** uji beban
  berskala produksi.
- **Periode**: data uji terkumpul ‹bulan/tahun›.

## 1.6 Definisi Operasional

| Istilah | Definisi dalam laporan ini |
|---|---|
| Microservice | Layanan perangkat lunak yang dapat dikembangkan, diuji, dan dijalankan secara independen, serta berkomunikasi antar-layanan melalui protokol standar (HTTP/JSON) |
| Gateway AI | `ai-service` (FastAPI) sebagai satu-satunya pintu masuk aplikasi PHP menuju model LLM; aplikasi PHP tidak pernah memanggil LLM secara langsung |
| Function calling | Mekanisme LLM meminta pemanggilan alat/function terdefinisi dengan argumen terstruktur (JSON Schema), lalu aplikasi menjalankan alat tersebut dan mengembalikan hasilnya |
| Rantai model (*fallback chain*) | Urutan percobaan penyedia LLM: Gemini → Gemini lite → Ollama `qwen2.5:7b` → `qwen2.5:3b`, berhenti pada yang pertama merespons |
| Degradasi anggun (*graceful degradation*) | Perilaku layanan saat seluruh model tak terjangkau: HTTP 503 dengan pesan jelas, atau hasil deterministik bila `AI_DEGRADE_DETERMINISTIC=true` |
| Approval-gated | Tindakan AI yang hanya dieksekusi setelah persetujuan eksplisit pengguna; tanpa persetujuan, alat hanya mengembalikan usulan (*dry-run*) |
| Strangler fig | Pola migrasi bertahap: sistem baru secara bertahap "menggantikan" kapabilitas sistem lama satu per satu, dengan basis data yang sama |
| RBAC | *Role-Based Access Control* — penetapan hak akses berdasarkan peran pengguna |
| Jejak audit (*audit trail*) | Rekam jejak perubahan data: siapa, apa, kapan, dan dari mana |
| VIEW read-only | Objek basis data logis dengan hak `SELECT` saja, dipakai lapisan analitik tanpa akses ke tabel dasar |
|_black-box testing_ | Pengujian yang hanya melihat perilaku antarmuka/keluaran sistem, tanpa akses ke struktur internal |
| UAT | *User Acceptance Test* — pengujian penerimaan oleh pengguna akhir dengan instrumen kuesioner |

---

# BAB II — TINJAUAN PUSTAKA

## 2.1 Teori Sistem dan Sistem Pelaporan

- Definisi sistem sebagai kumpulan komponen yang interrelated untuk mencapai
  tujuan (input → proses → output → feedback).
- Sistem informasi: sistem yang menghimpun, memproses, dan menyajikan
  informasi untuk pengambilan keputusan.
- Sistem pelaporan: subclass sistem informasi yang **menyajikan informasi
  agregat** untuk manajemen, berbeda dari sistem transaksi yang mencatat
  operasional harian.
- Implikasi desain: pelaporan harus dapat ditelusuri ke sumbernya
  (data lineage), sehingga jejak audit bukan pelengkap melainkan kebutuhan.

**Referensi kunci**: Laudon & Laudon (2022); Davis & Hill (2022);
Jogiyantono (2018).

## 2.2 Arsitektur Microservice versus Monolitik

- Definisi dan ciri microservice (Richardson & Martin, 2018).
- Tabel perbandingan: *coupling*, independensi deployment, skalabilitas,
  *fault isolation*, overhead jaringan, dan biaya operasional.

**Konsep yang benar-benar diimplementasikan pada sistem ini:**

| Konsep | Wujud pada sistem |
|---|---|
| API Gateway | `ai-service` menjadi satu-satunya gateway LLM; `X-Service-Key` sebagai penanda klien tepercaya |
| Fallback / *resilient client* | `RantaiKlien` di `ai-service/app/llm.py` mencoba penyedia berurutan |
| *Graceful degradation* | `AI_DEGRADE_DETERMINISTIC` menentukan perilaku saat model mati |
| *Fault isolation* | Microservice AI mati → halaman non-AI tetap berfungsi penuh (PHP hanya memeriksa status 2xx `/health`) |
| Shared database vs. database per service | Dipakai **shared database** dengan justifikasi konsistensi data operasional & transaksi lintas-modul |
| *Compensating action* / approval gate | Tindakan destruktif AI menghasilkan cadangan otomatis + approval eksplisit |

**Referensi kunci**: Richardson & Martin (2018); Newman (2015); Fowler (2014,
*Microservice Premium*).

## 2.3 Large Language Model, Prompt, dan Function Calling

- Konsep LLM: token, konteks, parameter sampling, dan halusinasi.
- *In-context learning* dan *retrieval-augmented generation*; pada sistem ini
  pengetahuan agen dibentuk lewat *skill* (prompt sistem) pada folder
  `skills/laporan-harian/`, `skills/pencarian/`, `skills/jadwal/`.
- **Function calling / tool use**: JSON Schema, daftar `required`, dispatch ke
  registry alat, siklus observasi–aksi–observasi.
- **Strategi multi-model**: mengapa diperlukan *fallback* (biaya, latensi,
  keandalan);
  Gemini (cloud, kualitas) sebagai utama, Ollama (lokal, offline) sebagai cadangan.
- **Keamanan LLM**: risiko *prompt injection*; mitigasi pada sistem ini —
  `X-Service-Key`, daftar putih peran per alat, `dry-run` default, pencatatan
  audit, dan batasan data lintas pengguna.
- **Pencarian web berlapis**: Google Search grounding → SearXNG → DuckDuckGo Lite.

**Referensi kunci**: Vaswani et al. (2017); Google DeepMind (2024, Gemini);
Qwen Team (2024, Qwen2.5); Anthropic (2024, *Building Effective Agents*).

## 2.4 Keamanan Aplikasi

- **RBAC**: model *Access Control Matrix* (Sandhu et al., 2000); *default deny*
  — kemampuan tak terdaftar hanya untuk admin (`peta_akses()`).
- **Autentikasi dua faktor / OTP**: TOTP (RFC 6238); implementasi pada sistem
  menggunakan kode OTP yang dikirim via surel, serta `backup_codes` untuk
  pemulihan.
- **Manajemen sesi**: batas idle 30 menit, usia mutlak 8 jam (`BatasSesi`),
  `session.use_strict_mode=1`, token Bearer (Laravel Sanctum) untuk klien
  non-cookie.
- **Hashing kata sandi**: bcrypt/Argon2 + `password_history` (larangan pemakaian
  ulang kata sandi), ditambah kebijakan kekuatan sandi.
- **Least privilege**: akun aplikasi (tulis) vs akun `bi_reader` (baca saja);
  Power BI tidak pernah menyentuh tabel dasar.
- **Audit trail & non-repudiation**.
- **OWASP Top 10 (2021)**: A01 Broken Access Control, A02 Cryptographic
  Failures, A03 Injection (seluruh kueri ber-parameter `%s`), A07 Identification
  & Authentication Failures, A09 Security Logging & Monitoring Failures.

**Referensi kunci**: OWASP Foundation (2021); Sandhu et al. (2000);
Mardanovic & Adams (2016, *Two-Factor Authentication*).

## 2.5 Migrasi Sistem Legacy

- Pola *Strangler Fig Application* (Fowler, 2004) — *branch by abstraction*,
  *expand and contract* sebagai variasi.
- *Anti-pattern*: *Big Bang rewrite* — berisiko besar pada sistem operasional.
- Justifikasi: sistem legacy masih melayani lalu lintas yang nyata; migrasi harus
  bertahap, dapat dil mundur, dan tidak boleh kehilangan data.

**Referensi kunci**: Fowler (2004); Humble & Farley (2018, *Continuous
Delivery*).

## 2.6 Metode Pengembangan Perangkat Lunak

- Model SDLC: *waterfall*, *incremental*, *RAD*, *spiral*.
- **Prototyping** (Heuser, 2015) — dipilih karena sistem ini berkembang
  bersamaan dengan kebutuhan operasional yang berubah cepat.
- Prinsip rekayasa yang diterapkan: *Single Responsibility Principle*, DRY,
  konvensi API REST/JSON dengan versi (`/api/v1`), konvensi pesan error
  berbahasa Indonesia, *rate limiting* pada endpoint sensitif.

## 2.7 Penelitian Terkait

> ⚠️ **Isi tabel ini dengan pencarian nyata** (Google Scholar, Garuda,
> SINTA, ScienceDirect). Minimal 8 sumber: 4 jurnal ilmiah + 2 buku + 2
> dokumentasi teknis. Kerangka berikut hanya kolomnya.

| No. | Penulis & Tahun | Judul | Fokus | Perbedaan dengan penelitian ini |
|---|---|---|---|---|
| 1 | ‹…› (‹tahun›) | ‹…› | ‹…› | ‹…› |
| 2 | ‹…› (‹tahun›) | ‹…› | ‹…› | ‹…› |
| 3 | ‹…› (‹tahun›) | ‹…› | ‹…› | ‹…› |
| 4 | ‹…› (‹tahun›) | ‹…› | ‹…› | ‹…› |
| 5 | ‹…› (‹tahun›) | ‹…› | ‹…› | ‹…› |
| 6 | ‹…› (‹tahun›) | ‹…› | ‹…› | ‹…› |

## 2.8 Landasan Pemakaian Teknologi

| Lapisan | Teknologi | Versi | Alasan |
|---|---|---|---|
| Legacy | PHP (flat file) | ≥ 8.2 | Sistem eksisting; tidak dimigrasikan penuh |
| Framework | Laravel | 12 | API, Sanctum, Scheduler, Queue, migration |
| Frontend | React + Vite + Tailwind CSS | 19 / 7 / 4 | SPA cepat, berbasis komponen, mode gelap |
| Charting | Chart.js + react-chartjs-2 | 4.5 / 5.3 | Ringan, tanpa dependensi berat, mode gelap |
| AI service | Python + FastAPI + Uvicorn | 3.11–3.13 / 0.115 / 0.34 | Async, type-safe, ringan |
| Validasi | Pydantic + pydantic-settings | 2.10 / 2.7 | Skema request/response & konfigurasi terpusat |
| LLM utama | Google Gemini | `gemini-3.8-flash` | Kualitas tinggi, function calling, Search grounding |
| LLM cadangan | Ollama + Qwen2.5 | `qwen2.5:7b` / `qwen2.5:3b` | Lokal, tetap hidup saat jaringan down |
| Mobile | Kotlin + Jetpack Compose | 2.1.21 / Material 3 | Native, satu berkas per layar |
| Autentikasi mobile | Laravel Sanctum | 4.3 | Token Bearer, paritas dengan SPA |
| Basis data | MariaDB / MySQL | 10.4+ | Shared database, kompatibel XAMPP |
| Dokumen | dompdf / PhpSpreadsheet / PHPMailer | 3.1 / 5.10 / 6.10 | Ekspor PDF/XLSX & surel OTP |
| Analitik | Power BI + 22 SQL VIEW | — | Lapisan baca-saja, risiko rendah |
| Pengujian | PHPUnit / PHPUnit-legacy / pytest | 11.5 / — / 8.3 | 423 uji otomatis |

---

# BAB III — METODE PENELITIAN

> Rincian lengkap (tabel use case, matriks RBAC, rancangan API, lingkungan)
> ada di **[`docs/KERANGKA_BAB_III.md`](KERANGKA_BAB_III.md)**.

## 3.1 Jenis dan Lokasi Penelitian

- **Jenis**: *Development Research* (penelitian pengembangan sistem).
- **Lokasi studi kasus**: PT Pertamina Hulu Rokan; lingkungan pengembangan dan
  pengujian di lokasi (XAMPP, emulator Android, Ollama).
- **Pendekatan**: kualitatif (analisis kebutuhan, wawancara, observasi)
  dipadukan kuantitatif (UAT, uji performa, uji akurasi AI).

## 3.2 Teknik Pengumpulan Data

| Teknik | Instrumen | Data yang diperoleh |
|---|---|---|
| Observasi | Field notes | Alur kerja pelaporan lapangan dan fungsi mentor |
| Wawancara | Pedoman semi-terstruktur ‹nama› | Kebutuhan per peran, keluhan atas proses lama |
| Studi dokumen | SOP, form Excel, arsip rekap ‹periode› | Proses *existing* dan baseline waktu kerja |
| Audit kode & basis data | Pemeriksaan berkas & skema | Gap arsitektur, keamanan, dan reuse |

## 3.3 Analisis Kebutuhan

- **3.3.1 Kebutuhan fungsional** → 18 use case (lihat `KERANGKA_BAB_III.md` §3.3.1).
- **3.3.2 Kebutuhan non-fungsional** → 15 kebutuhan non-fungsional dalam 6 kategori (lihat `KERANGKA_BAB_III.md` §3.3.2).
- **3.3.3 Matriks modul dan peran** → **40 modul** (34 tampil di menu + 6
  halaman yang dijaga `wajib_role()` tanpa entri sidebar), diturunkan dari
  `menu_tersedia()` (`includes/layout.php`) dan `peta_akses()`
  (`includes/auth.php`).
- **3.3.4 Rincian modul inti dan pemetaan kode** → 28 berkas `includes/`,
  62 halaman legacy di akar (16 kelompok domain), dan 25 controller Laravel
  yang dipetakan ke endpoint serta model (Tabel 3.4A, 3.4B, 3.4C).

## 3.4 Perancangan Sistem

- **3.4.1** Diagram use case (3 diagram: karyawan, mentor, admin).
- **3.4.2** Diagram activity: alur persetujuan laporan; alur fallback AI;
  alur pemulihan basis data.
- **3.4.3** Diagram sequence: login + 2FA; akses API dengan Sanctum;
  **percakapan dengan function calling** (paling penting).
- **3.4.4** Diagram component/deployment: lima subsistem + jaringan + basis data.
- **3.4.5** ERD ringkas 8–10 entitas inti (ERD lengkap → Lampiran A).
- **3.4.6** Diagram arsitektur microservice (gambar utama Bab IV).
- **3.4.7** Rancangan antarmuka: wireframe 8–10 layar kunci, tema korporat PHR
  (`#003D7D`, `#005EB8`, `#EB1C24`, `#159447`, latar `#F5F5F5`), mode gelap.

## 3.5 Rancangan Antarmuka API

Tabel lengkap 37 endpoint Laravel (metode, path, autentikasi, peran, deskripsi)
**plus 3 endpoint AI pada lapisan legacy PHP** → `KERANGKA_BAB_III.md` §3.5
(Tabel 3.7–3.10 dan Tabel 3.10A).

**Kaidah API**: JSON; `Authorization: Bearer <token>`; status `200/201/400/401/
403/422/500/503`; paginasi; pesan error berbahasa Indonesia; *rate limiting*
(10/mnt login, 20/mnt asisten, 30/mnt panel AI legacy); header `X-Service-Key`
untuk klien layanan.

## 3.6 Rancangan Keamanan

- **3.6.1** Matriks hak akses RBAC (tabel lengkap → `KERANGKA_BAB_III.md` §3.6).
- **3.6.2** Rancangan autentikasi & 2FA (alur, tabel `user_otp`,
  `login_attempts`, `login_locks`, `backup_codes`).
- **3.6.3** Rancangan jejak audit (tabel `audit_log`).
- **3.6.4** Rancangan *approval gate* untuk tindakan AI berisiko.

## 3.7 Lingkungan Implementasi

Hardware, sistem operasi, perangkat lunak, dan alat bantu → `KERANGKA_BAB_III.md` §3.7.

## 3.8 Metode Pengujian

| Jenis | Metode | Instrumen | Volume/Target |
|---|---|---|---|
| Fungsional (Laravel) | Black-box | PHPUnit 11 | 182 uji |
| Fungsional (legacy) | Black-box | PHPUnit-legacy | 163 uji |
| Microservice AI | Unit + mock LLM | pytest 8 | 78 uji |
| Keamanan | Manual + OWASP checklist | Form review | 10 butir |
| Performa | Beban ringan | Apache Bench / k6 | ‹50 rps› |
| Kualitas AI | Ketepatan & respons | Rubrik manual | 30 skenario prompt |
| UAT | Kuesioner + observasi | Form UAT | ≥ 15 responden (3 peran) |

---

# BAB IV — HASIL PEMBAHASAN

> **Pola wajib:** Implementasi → Hasil uji → Analisis terhadap RM.
> Tutup setiap sub-bab dengan paragraf *"Hal ini menjawab RM-x."*

## 4.1 Verifikasi Hasil Perancangan

Tabel: unsur rancangan → wujud implementasi → status (tercapai / deviasi) →
alasan deviasi. *Deviasi tampilkan sebagai keputusan desain yang sadar.*

Dua deviasi nyata yang sudah terverifikasi di kode dan wajib disebut:

| Unsur rancangan | Wujud implementasi | Status | Alasan / analisis |
|---|---|---|---|
| Impor dijaga `boleh_akses('impor.data')` (mentor/admin) | `impor_laporan.php` menjaganya lewat `impor_jenis_untuk_role()`; `boleh_akses('impor.data')` tidak pernah dipanggil | Deviasi | Karyawan **memang perlu** mengimpor, tetapi hanya datanya sendiri (`$paksaUser = $uid`). Konvensi penjagaan jadi dua: peta kemampuan untuk hapus, fungsi jenis-impor untuk impor. Perlu diseragamkan → bahan Saran 5.2. |
| Semua komponen berjalan di produksi | `hermes/hermes.config.yaml` disertakan tetapi tidak dimuat kode mana pun | Deviasi sadar | Komponen opsional untuk agen eksternal; dipertahankan sebagai kemasan, bukan dependensi. |
| Seluruh uji dapat dijalankan pada klon bersih | `ai-service/hermes/` ada di `.gitignore`, tetapi `test_packaging.py` membacanya tanpa penjaga | Deviasi | Satu uji bergantung pada berkas lokal. Dicatat pada 4.3.9 dan 5.3; perbaikan diusulkan pada 5.2. |


## 4.2 Arsitektur Integrasi dan Inti Sistem (RM-1)

- **4.2.1 Strategi migrasi strangler fig.** Diagram: legacy
  (DocumentRoot `localhost/pelaporan/`) ⇄ Laravel (VirtualHost) pada **satu
  basis data**. Aturan: rute baru dibuat di Laravel; halaman lama tetap
  dilayani flat-PHP; konsistensi data dijamin karena basis data sama.
  Tabel 4.1 — status migrasi per modul.
- **4.2.2 Modul inti legacy PHP.** Laporan harian + alur persetujuan; absensi
  + kondisi kesehatan; kasus + tim penugasan; tugas; jadwal & pengingat; SRM/CID;
  peta + geokode; notifikasi; impor data.
  *Gambar kunci*: alur persetujuan laporan (draft → dikirim → diperiksa →
  perlu revisi → disetujui).
  Rincian modul inti (27 berkas di `includes/`; pemetaan lengkap pada
  **Tabel 3.4A** di Bab III subbab 3.3.4):

  | Kelompok | Berkas inti | Tanggung jawab |
  |---|---|---|
  | Laporan & persetujuan | `keputusan_laporan.php`, `komentar.php`, `validators.php` | Satu pintu transisi status laporan (`laporan_putuskan()`), komentar revisi, validasi isian |
  | Akun & autentikasi | `akun.php`, `security.php`, `csrf.php` | Token satu-pakai (SHA-256) untuk verifikasi surel & reset sandi, pembatas laju, token CSRF |
  | Notifikasi | `notifikasi.php`, `pemberitahuan.php`, `badge_helper.php` | Satu sumber pembuatan notifikasi (menghapus duplikasi), pemberitahuan, penghitung lencana |
  | Kanal keluar | `mailer.php`, `whatsapp.php` | Surel OTP/notifikasi (PHPMailer, mode log saat SMTP kosong), WhatsApp via gateway Fonnte/Wablas |
  | Wilayah & peta | `wilayah.php` | Normalisasi nama wilayah, tautan Google Maps, geokode Nominatim, resolusi kolom koordinat |
  | Integrasi AI | `ai_client.php` | Satu-satunya jalur PHP → `ai-service` (header `X-Service-Key`), tidak pernah memanggil LLM langsung |
  | Konfigurasi | `config.php`, `config_lokal.php` | Pemisahan rahasia dari kode (kredensial DB, SMTP, gateway WA) di berkas yang dilindungi `.htaccess` |

  Tiga jalur AI pada lapisan legacy melengkapi `/api/v1/asisten/kirim`:
  `api_chat.php` (panel asisten), `api_validasi.php` (pemeriksaan kelengkapan
  laporan oleh AI, tetap membalas struktur sama saat layanan mati), dan
  `api_notifikasi_jumlah.php` (lencana notifikasi). Rincian di Tabel 3.10A.

- **4.2.3 Lapisan Laravel 12 + React 19 SPA.** 17 halaman React, 45 endpoint
  `/api/v1`, **25 controller** (14 web + 11 API) yang memetakan endpoint ke
  model, 38 model Eloquent, 7 service domain (`AuditLogger`, `KeamananLogin`,
  `Otp`, `KodeCadangan`, `PendaftaranSesi`, `RiwayatSandi`, `CocokPengguna`),
  Sanctum, middleware `BatasSesi` (idle 30 mnt / maks 8 jam) dan
  `CekPeran` (`role:mentor,admin`), Vite + Tailwind 4, mode gelap, Chart.js 4.
  **Tabel 3.4C** memetakan setiap controller ke endpoint dan modelnya.
- **4.2.4 Lapisan analitik read-only.** 23 VIEW `SQL SECURITY DEFINER`,
  akun `bi_reader` hanya `SELECT`. Tabel 4.2 — daftar 23 view dan fungsi
  analitiknya.

## 4.3 Microservice AI (RM-2, RM-3)

- **4.3.1 Gateway tunggal.** Diagram: PHP `includes/ai_client.php` →
  `X-Service-Key` → FastAPI → Gemini/Ollama → MariaDB (`bi_reader`, fallback ke
  akun aplikasi). Aturan tegas: PHP tidak pernah memanggil LLM langsung.
- **4.3.2 Rantai model dan fallback.** `gemini-3.8-flash` → `gemini-3.5-flash-lite`
  → `qwen2.5:7b` → `qwen2.5:3b`. Degradasi: HTTP 503 atau mode deterministik.
  **Tabel 4.3** — hasil uji fallback per skenario gangguan.
- **4.3.3 Function calling dan registry 21 alat.** **Tabel 4.4** — nama alat,
  peran, metode, argumen, efek samping, tercatat di audit?
  Daftar: `search_notes`, `update_note`, `validate_report`, `statistics`,
  `trend_analysis`, `weekly_summary`, `monthly_summary`, `narrative_report`,
  `export_excel`, `export_word`, `backup_database`, `list_backups`,
  `restore_database`, `send_email`, `send_whatsapp`, `web_search`,
  `daftar_jadwal`, `buat_jadwal`, `ubah_jadwal`, `hapus_jadwal`,
  `ingatkan_jadwal`.
  *Diagram sequence* satu percakapan penuh (pengguna → model → alat → DB → jawaban bersumber).
- **4.3.4 Otorisasi pada lapisan alat.** `ToolContext.role` → `boleh_dipakai()`
  → daftar putih per alat; `backup_*`/`restore_database` khusus admin;
  `update_note` hanya pemilik (atau admin) dan hanya saat status
  `draft`/`perlu_revisi`; alat jadwal hanya menyentuh agenda pemanggil.
- **4.3.5 Approval gate untuk tindakan berisiko (RM-3).** Tabel 4.5:
  `restore_database` (butuh `konfirmasi="PULIHKAN"` + cadangan pengaman
  otomatis), `send_email`, `send_whatsapp`, `update_note` (*dry-run* default),
  seluruh alat tulis jadwal (`konfirmasi=true`). Diagram activity *approval loop*.
- **4.3.6 Antrean `ai_jobs`.** UUID; status `antre|proses|selesai|gagal`; klaim
  atomik tanpa `SKIP LOCKED` (MariaDB 10.4); proses deterministik tanpa LLM;
  `pulihkan-macet?menit=15`; Task Scheduler tiap menit.
- **4.3.7 Pencarian web tiga penyedia.** Gemini Search grounding → SearXNG →
  DuckDuckGo Lite; kegagalan → `ok:false` dengan pesan jelas; dukungan
  `AI_HTTP_CA_BUNDLE` untuk proxy korporat.
- **4.3.8 Skill dan templat prompt sistem.** Tiga skill di `ai-service/skills/`
  (`laporan-harian`, `pencarian`, `jadwal`) berisi peran & hak akses, skema
  basis data, alur status, kontrak endpoint, dan aturan tindakan berisiko.
  Di sisi eksekusi, prompt disusun dari **5 berkas di `ai-service/app/prompts/`**:

  | Berkas | Dipakai oleh | Fungsi |
  |---|---|---|
  | `domain.md` | semua jalur | Konteks domain PHR: istilah, peran, tabel, dan aturan bisnis |
  | `system_chat.md` | `/chat` | Kepribadian asisten, gaya jawaban, kewajiban menyebut sumber |
  | `system_narrative.md` | alat `narrative_report` | Nada dan struktur narasi laporan |
  | `system_summary.md` | alat `weekly_summary` / `monthly_summary` | Aturan pemadatan ringkasan |
  | `system_validate.md` | alat `validate_report` | Rubrik kelengkapan laporan |

  Pemuatan prompt dipisah dari kode (`prompts/__init__.py`), sehingga perubahan
  nada jawaban tidak menyentuh logika agen. Pemetaan prompt → jalur
  pemanggilan diletakkan pada **Tabel 4.16** (Lampiran J).
- **4.3.9 Kemasan agen eksternal (Hermes) dan catatan reproduksibilitas.**
  `ai-service/hermes/hermes.config.yaml` disediakan sebagai jalur integrasi
  opsional: berkas ini memetakan Ollama lokal (`qwen2.5:7b`,
  `http://localhost:11434/v1`) sebagai *custom provider* pada **Hermes Agent**
  pihak ketiga, beserta instruksi penyalinan skill. Berkas ini **tidak dipakai
  oleh aplikasi berjalan** — aplikasi selalu memanggil `ai-service` melalui
  FastAPI. Hal ini sengaja dilaporkan sebagai komponen pendukung, bukan bagian
  arsitektur produksi.

  > **Temuan jujur (wajib dibahas di 4.7 dan 5.3).** Direktori
  > `ai-service/hermes/` terdaftar pada `.gitignore` sehingga **tidak ikut
  > dalam repositori**, sementara `tests/test_packaging.py` membacanya tanpa
  > penjaga keberadaan berkas (`_baca()` langsung `read_text()`). Artinya
  > **klon bersih akan gagal pada satu uji** (`test_hermes_config_valid`)
  > meskipun seluruh 78 uji lulus di mesin pengembang. Ini bukan kegagalan
  > fungsional, melainkan cacat reproduksibilitas. Perbaikan yang diusulkan
  > pada Subbab 5.2: (a) keluarkan berkas dari `.gitignore` sebagai contoh
  > (`hermes.config.example.yaml`), atau (b) tambahkan `pytest.skip` bila
  > berkas tidak ada, atau (c) hapus uji tersebut karena komponennya opsional.
  > Temuan ini ditulis apa adanya agar klaim "423 uji lulus" dapat
  > dipertanggungjawabkan beserta syarat lingkungannya.



## 4.4 Aplikasi Android (dukungan RM-1)

- Kotlin 2.1 + Compose Material 3; `MainActivity` tunggal + `NavHost` + laci
  menu (padanan sidebar web); 11 layar.
- Retrofit 2.11 + OkHttp + Gson; DataStore untuk token dan **alamat server yang
  dapat diarahkan ke server mana pun** (memudahkan demo dan uji lapangan).
- 2FA OTP saat masuk; paritas autentikasi, aturan peran, dan data dengan web.
- Tabel 4.6 — pemetaan layar → endpoint. Screenshot 4 layar kunci.
- **Catatan jujur untuk Bab V**: HTTP cleartext untuk jaringan internal;
  go-live wajib HTTPS.

## 4.5 Keamanan, Audit, dan Manajemen Peran (RM-4)

- Tabel 4.7 — hasil matriks RBAC terimplementasi (bukti nyata dari kode).
- 2FA/OTP + `backup_codes` + `user_otp` + `login_attempts`/`login_locks`.
- `password_history` + kebijakan kekuatan sandi
  (`tests/Unit/KebijakanSandiTest.php`).
- `sesi_aktif` + `BatasSesi` + `session.use_strict_mode`.
- Pertahanan berlapis lain yang terverifikasi di kode:

  | Lapis | Berkas | Wujud |
  |---|---|---|
  | CSRF | `includes/csrf.php` | Token per aksi (`global`, `hapus`, `impor`), diperiksa `csrf_cek()` |
  | Validasi masukan | `includes/validators.php` | `validasi_laporan()` / `validasi_revisi()` dijalankan sebelum simpan |
  | Kontrol objek (IDOR) | `includes/kasus.php`, `lihat_foto.php` | `kasus_izin_lihat()` / `kasus_izin_kelola()`; izin foto = pemilik **atau** mentor/admin |
  | Unggahan berkas | `includes/impor_excel.php`, `lihat_foto.php` | Daftar ekstensi putih, batas ukuran, pemeriksaan MIME `finfo` |
  | Pembatas laju | `includes/security.php`, `api_chat.php` | `login_*_kunci()`, 30 pesan/menit per sesi |
  | Rahasia konfigurasi | `includes/config_lokal.php` | Kredensial di luar kode, diabaikan Git, dilindungi `.htaccess` |
  | Hak akses basis data | `powerbi/sql/02_views.sql` | Akun aplikasi (tulis) vs `bi_reader` (baca saja) |

- `audit_log`: perubahan laporan, `update_note` oleh AI, tindakan jadwal.
  Screenshot.
- Distribusi hak akses: akun aplikasi (tulis) vs `bi_reader` (baca).

## 4.6 Impor Data dan Visualisasi

- Impor Excel/CSV dengan pemetaan kolom **berbasis indeks** (legacy) maupun
  **berbasis nama header** (baru); normalisasi sisi server
  (`impor_normalisasi_pemetaan()`).
- Dua jalur impor yang berbeda tujuan: `impor_laporan.php` (jenis tetap,
  dipetakan ke tabel tetap) dan `impor_dataset.php` (header berkas **langsung
  menjadi kolom** `dataset`), sehingga data apa pun bisa langsung divisualkan
  tanpa menambah tabel atau query grafik baru.
- Penyimpanan dataset: JSON pada `dataset_baris.data_json` + profil kolom
  `dataset.kolom_json` (kunci, tipe, jumlah kosong).
- Empat permukaan visualisasi: `analisis_dataset.php` (dataset generik),
  `analisis_sosial.php` (4 tab: SRM, CID, laporan, absensi), dasbor per peran,
  dan **23 view Power BI**. Nilai kolom kosong dikelompokkan sebagai
  "Belum diisi" agar grafik tetap tergambar pada data belum lengkap.
- Chart.js 4: batang, garis, donat; konsisten pada mode terang dan gelap.
- Screenshot grafik + tabel ringkas.


## 4.7 Hasil Pengujian

> **Sumber bahan siap pakai:** `docs/UJI_COBA.md` (36 KB) sudah memuat skenario
> uji manual per modul, daftar periksa keamanan, dan prosedur uji fallback.
> Dokumen itu dipakai langsung sebagai bahan Tabel 4.9–4.12 sehingga tidak
> perlu menyusun ulang dari nol; yang ditambahkan hanya kolom *actual* dan
> *status* hasil pelaksanaan nyata.

**Tabel 4.8 — Ringkasan uji otomatis**

| Suite | Framework | Jumlah | Status |
|---|---|---|---|
| Laravel Feature/Unit | PHPUnit 11 | 182 | Lulus |
| Legacy | PHPUnit-legacy | 163 | Lulus |
| ai-service | pytest 8 | 78 | Lulus |
| **Total** | | **423** | |

**Tabel 4.9** — uji fungsional manual per modul (ID | skenario | expected | actual | status).
**Tabel 4.10** — uji keamanan checklist OWASP 10 butir + screenshot.
**Tabel 4.11** — uji performa (beban, konkurensi, p95).
**Tabel 4.12** — matriks uji fallback AI (4 skenario gangguan; rincian di Tabel 3.21).

## 4.8 Efektivitas Fungsi AI (RM-5)

**Tabel 4.13 — Uji akurasi fungsi AI**

| No. | Skenario pertanyaan | Benar? | Provider aktif | Waktu respons | Sumber dirujuk? |
|---|---|---|---|---|---|
| 1 | "Cari laporan saya minggu lalu" | Ya | `gemini-3.8-flash` | ‹…› s | Ya |
| 2 | "Ringkasan kegiatan bulan ini" | Ya | `gemini-3.8-flash` | ‹…› s | — |
| 3 | "Validasi kelengkapan laporan ‹tanggal›" | Ya | `qwen2.5:7b` (fallback) | ‹…› s | — |
| … | | | | | |

- **4.8.1 Ketepatan** — persentase benar per kategori (retrieval, ringkasan,
  validasi, jadwal).
- **4.8.2 Waktu respons** — perbandingan Gemini vs Ollama (tabel + grafik).
- **4.8.3 Efektivitas fallback** — 100% skenario gangguan terlayani tanpa
  downtime layanan non-AI.
- **4.8.4 Dampak terhadap efisiensi** — perbandingan waktu rekap manual vs
  otomatis (data lapangan ‹…›). *Ini angka paling meyakinkan di TA.*
- **4.8.5 Keterbatasan** — halusinasi, ketergantungan internet, model lokal
  pada tugas bernuansa.

## 4.9 Hasil UAT (RM-6)

- Profil responden: karyawan ‹n›, mentor ‹n›, admin ‹n›.

**Tabel 4.14 — Hasil UAT**

| Aspek | Sangat Baik | Baik | Cukup | Buruk | Rata-rata |
|---|---|---|---|---|---|
| Kemudahan input laporan | | | | | |
| Kegunaan dasbor | | | | | |
| Kecepatan muat halaman | | | | | |
| Kejelasan tampilan | | | | | |
| Kegunaan asisten AI | | | | | |
| Keamanan & privasi data | | | | | |
| Ketepatan & kecepatan respons AI | | | | | |

- Umpan balik kualitatif (kutipan responden).
- **Tabel 4.15** — temuan UAT → tindakan perbaikan → status.

## 4.10 Pembahasan Terhadap Rumusan Masalah

| RM | Hasil | Bukti | Subbab |
|---|---|---|---|
| RM-1 | Arsitektur integrasi lima subsistem berjalan pada satu basis data | Gambar 4.1, Tabel 4.1 | 4.2 |
| RM-2 | Gateway AI + 21 alat function calling + rantai cadangan aktif | Gambar 4.3, Tabel 4.3–4.4 | 4.3 |
| RM-3 | Seluruh tindakan berisiko berpintu persetujuan (*approval-gated*) | Tabel 4.5 | 4.3.5 |
| RM-4 | RBAC, audit, 2FA, batas sesi aktif lintas platform | Tabel 4.7 | 4.5 |
| RM-5 | Ketepatan ‹…›%, p95 ‹…› s, fallback 100% skenario | Tabel 4.13 | 4.8 |
| RM-6 | 423 uji otomatis lulus + UAT ‹n› responden | Tabel 4.8, 4.14 | 4.7, 4.9 |

## 4.11 Analisis Keseluruhan

- Pemetaan tujuan penelitian vs bukti pencapaian (tabel).
- Analisis kelayakan: teknis, operasional, ekonomis, jadwal.
- Refleksi: apa yang sudah terpenuhi dan apa yang masih menjadi keterbatasan.

---

# BAB V — PENUTUP

## 5.1 Kesimpulan

Tulis 5–7 butir; masing-masing **dimulai dari kalimat yang menjawab satu
rumusan masalah**, lalu diberi angka. Contoh:

1. Arsitektur integrasi lima subsistem berhasil dibangun pada satu basis data
   MariaDB dengan pendekatan strangler fig, memuat 17 halaman React, 45 endpoint
   API, 11 layar Android, dan 23 view analitik read-only.
2. Microservice AI berhasil berfungsi sebagai gateway tunggal dengan 21 alat
   berbasis function calling, rantai cadangan Gemini → Ollama, dan 100%
   tindakan berisiko berpintu persetujuan (*approval-gated*).
3. Pengujian otomatis mencapai 423 uji yang lulus seluruhnya (182 PHPUnit
   Laravel + 163 PHPUnit legacy + 78 pytest AI), dan UAT terhadap
   ‹n› responden rerata ‹…› pada skala 1–5.
4. Efektivitas fungsi AI terukur: ketepatan ‹…›% pada ‹…› skenario, waktu
   respons p95 ‹…› detik, dan fallback berhasil pada 100% skenario gangguan.

## 5.2 Saran

### 5.2.1 Untuk PT Pertamina Hulu Rokan

1. Lanjutkan migrasi bertahap ke Laravel untuk modul yang masih legacy
   (ikuti Tabel 4.1).
2. Aktifkan HTTPS dan hapus `usesCleartextTraffic` sebelum go-live Android;
   simpan keystore rilis di repositori aman.
3. Berikan hak `SELECT` eksplisit pada `bi_reader` untuk seluruh tabel inti —
   saat ini masih jatuh ke akun aplikasi (lihat `ai-service/README.md`).
4. Pasang pemantauan `/health` sebagai alarm ketersediaan model.
5. Integrasikan dengan sistem HR untuk validasi absensi otomatis.

### 5.2.2 Untuk penelitian dan pengembangan lanjutan

6. Terapkan RAG dengan embedding atas arsip SRM/CID untuk analisis kebijakan.
7. Kembangkan prediksi kepatuhan pelaporan (notifikasi otomatis bila
   kepatuhan menurun).
8. Bangun evaluasi berkala (benchmark) untuk setiap prompt/skill agar
   regresi kualitas terdeteksi.
9. Tambahkan uji beban skala penuh dan pipeline CI/CD.
10. Kaji notifikasi berbasis AI untuk ringkasan harian otomatis ke atasan.
11. Jadikan seluruh uji dapat dijalankan pada klon bersih: jadikan berkas
    contoh (`hermes.config.example.yaml`) yang dilacak Git, dan tambahkan
    `pytest.skip` bila komponen opsional tidak tersedia.
12. Seragamkan penjagaan hak akses pada satu mekanisme (`boleh_akses()`) agar
    peta kemampuan benar-benar menjadi sumber tunggal kebenaran.

## 5.3 Keterbatasan

1. Data uji relatif terbatas; tabel `absensi` masih kosong pada periode
   penulisan sehingga perlu *seeding* data uji untuk UAT bermakna.
2. Uji beban belum dilakukan pada skala produksi.
3. Model lokal `qwen2.5:3b` belum memadai untuk tugas narasi kompleks.
4. Aplikasi Android masih memakai HTTP cleartext (jaringan internal).
5. Integrasi masih memakai satu basis data bersama — belum ada isolasi data
   per layanan.
6. Evaluasi akurasi AI berbasis rubrik manual, belum benchmark otomatis.
7. **Reproduksibilitas uji belum sepenuhnya terjaga**: `ai-service/hermes/`
   tidak dilacak Git, sedangkan `test_packaging.py` membacanya tanpa penjaga,
   sehingga klon bersih akan gagal pada satu uji. Seluruh angka "423 uji lulus"
   berlaku pada mesin pengembang yang memiliki berkas tersebut (lihat 4.3.9).
8. Konvensi penjagaan hak akses belum seragam: `peta_akses()` dipakai untuk
   `srm.hapus`/`cid.hapus`, sedangkan impor dijaga `impor_jenis_untuk_role()`
   sehingga `impor.data` pada peta kemampuan tidak pernah dievaluasi.

---

# DAFTAR PUSTAKA (contoh format)

> Sesuaikan dengan IEEE atau APA sesuai panduan kampus.

```
[1]  M. Fowler, "Strangler Fig Application," 2004.
     [Online]. Available: https://martinfowler.com/bliki/StranglerFigApplication.html
[2]  S. Newman, Microservices: Designing Fine-Grained Systems. Sebastopol, CA:
     O'Reilly Media, 2015.
[3]  L. Richardson and N. Martin, Microservices Patterns. Manning, 2018.
[4]  A. Vaswani et al., "Attention Is All You Need," in Proc. NeurIPS, 2017,
     pp. 5998–6008.
[5]  Google DeepMind, "Gemini 1.5: Advancing the Frontier of Large Language
     Models," 2024.
[6]  Qwen Team, "Qwen2.5 Technical Report," arXiv:2412.15115, 2024.
[7]  R. S. Sandhu et al., "Role-Based Access Control Models," IEEE Computer,
     vol. 43, no. 6, pp. 38–47, 2000.
[8]  OWASP Foundation, "OWASP Top 10: The Ten Most Critical Web Application
     Security Risks," 2021.
[9]  W. Heuser, Systems Analysis and Design in a Changing World, 8th ed.
     Pearson, 2015.
[10] M. Fowler, Refactoring: Improving the Design of Existing Code, 2nd ed.
     Addison-Wesley, 2018.
```

---

# LAMPIRAN

| Lampiran | Isi |
|---|---|
| A | ERD lengkap (**48 tabel**: 37 legacy + 12 dari 11 migrasi Laravel) + kamus data |
| B | Listing kode inti: `ai-service/app/llm.py`, `ai-service/app/tools/registry.py`, `ai-service/app/prompts/`, `includes/ai_client.php`, `includes/keputusan_laporan.php`, `app/Http/Middleware/BatasSesi.php`, `app/Http/Middleware/CekPeran.php`, `includes/auth.php` (`peta_akses()`) |
| C | DDL hak akses SQL (`bi_reader`, `SQL SECURITY DEFINER`) |
| D | Kontrak API lengkap (OpenAPI/Swagger dari `/docs`) |
| E | Instrumen UAT + data mentah + rekap hasil (rujukan: `docs/UJI_COBA.md`) |
| F | Tabel hasil uji AI lengkap (30 skenario) |
| G | Screenshot seluruh alur: login → 2FA → dasbor → buat laporan → persetujuan mentor → admin → asisten AI → dataset → Power BI → APK |
| H | Daftar 23 view Power BI beserta deskripsi kolom |
| I | Daftar tabel dan 23 view + relasi |
| J | Dokumentasi skill AI (`ai-service/skills/`) dan 5 templat prompt (`ai-service/app/prompts/`) |
| K | Daftar 28 berkas `includes/` beserta fungsi ekspornya |
| L | Daftar 25 controller + 38 model + 7 service Laravel |
| M | Perkakas operasional: `scripts/` dan `tools/` (penjadwal, backup, pemeliharaan, geokode, sinkron aset) |
| N | Konfigurasi agen eksternal Hermes (`ai-service/hermes/hermes.config.yaml`) — komponen opsional |

---

# ✅ CHECKLIST DATA YANG HARUS DIKUMPULKAN DARI LAPANGAN

| # | Data | Untuk | Cara |
|---|---|---|---|
| 1 | Waktu rekap manual vs otomatis (‹n› mentor × ‹m› periode) | 1.1, 4.8.4 | Stopwatch |
| 2 | Hasil UAT (nilai per aspek + kutipan) | 4.9 | Form UAT, ≥ 15 responden |
| 3 | 30 skenario prompt AI + verifikasi | 4.8 | Uji manual + rubrik, catat provider & waktu |
| 4 | Baseline manual (jumlah lembar rekap/minggu) | 1.1 | Wawancara |
| 5 | Dokumen SOP & form Excel (asli) | 3.2, Lampiran | Amankan izin |
| 6 | Surat izin publikasi nama & logo PHR | Cover, Judul | Bagian Hukum |
| 7 | Data uji (seed) untuk `absensi` & `laporan_harian` | 4.7, 4.9 | Seeding skrip |

---

# 🛑 KESALAHAN YANG HARUS DIHINDARI

| Kesalahan | Akibat |
|---|---|
| Judul tanpa unsur arsitektur | Terlalu luas; penguji bertanya "novelty-nya apa?" |
| Mengaku menulis ulang sistem dari nol | Tidak sesuai strategi strangler fig yang dipakai |
| Hanya mendokumentasikan kode tanpa analisis | Bukan penelitian |
| Rumusan masalah tidak dijawab eksplisit di Bab IV | Nilai turun besar |
| Tidak ada batasan masalah | Penguji tidak dapat menilai kelayakan |
| Mengklaim uji beban tanpa benar-benar melaksanakannya | Mudah dibantah |
| Menyalin pustaka dari situs | Plagiat |
| Menampilkan nama/logo perusahaan tanpa izin | Risiko administratif |
| Mematikan modul legacy lalu bilang "sudah migrasi" | Klaim tidak terbukti |
| Membebankan data field realistis (mis. nama karyawan asli di lampiran) | Pelanggaran privasi |

---

# 📌 CATATAN UNTUK DRAF BAB II DAN IV

Kerangka di atas sudah *evidence-based*. Saat menulis, gunakan pola kalimat
berikut agar Bab IV tetap terikat dengan rumusan masalah:

> **"Pada subbab ini, [bukti teknis]. Dengan demikian, hal ini menjawab RM-x
> yang menyatakan bahwa …"**

Serta pola analisis:

> **"Berdasarkan Tabel 4.13, ketepatan fungsi AI pada kategori *retrieval*
> mencapai ‹…›%, lebih tinggi dibandingkan kategori *narasi* (‹…›%) karena …"**
