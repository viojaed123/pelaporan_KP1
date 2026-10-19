# Layanan AI Pelaporan Harian (ai-service)

Microservice **Python / FastAPI** yang menjadi *gateway* tunggal antara aplikasi
PHP **Sistem Pelaporan Kegiatan Harian** dan model LLM — **Google Gemini
(`gemini-3.8-flash`) sebagai model utama**, dengan **Ollama (Qwen 2.5)** sebagai
cadangan lokal otomatis. Layanan ini menyediakan chatbot asisten (dengan
*function calling*), ringkasan, narasi, validasi, ekspor dokumen, pencadangan,
dan pemrosesan antrean `ai_jobs`.

Aplikasi PHP tidak pernah memanggil Gemini/Ollama secara langsung — semuanya lewat
layanan ini, sesuai kontrak di `includes/ai_client.php` dan `api_chat.php`.

> **English summary** — see [English](#english) at the bottom.

---

## Daftar isi

- [Arsitektur singkat](#arsitektur-singkat)
- [Prasyarat](#prasyarat)
- [Memulai cepat (Windows)](#memulai-cepat-windows)
- [Konfigurasi (.env)](#konfigurasi-env)
- [Menjalankan](#menjalankan)
- [Endpoint & kontrak](#endpoint--kontrak)
- [Alat (tools) agen](#alat-tools-agen)
- [Antrean ai_jobs](#antrean-ai_jobs)
- [Pengujian](#pengujian)
- [Skill laporan-harian](#skill-laporan-harian)
- [Catatan penting](#catatan-penting)
- [Struktur berkas](#struktur-berkas)
- [English](#english)

---

## Arsitektur singkat

```
┌──────────────┐   HTTP + X-Service-Key   ┌───────────────────┐   REST Gemini   ┌──────────────┐
│  Aplikasi    │ ───────────────────────► │  ai-service       │ ──────────────► │ Google       │
│  PHP (web)   │ ◄─────────────────────── │  FastAPI + agen   │ ◄────────────── │ Gemini 3.8   │
└──────┬───────┘   JSON (balasan, sumber)  └─────────┬─────────┘                 └──────────────┘
       │                                             │ (bila Gemini gagal)
       │                                             │ /api/chat
       │                                             ▼
       │                                       ┌──────────┐
       │                                       │  Ollama  │  ← cadangan lokal
       │                                       │ qwen2.5  │
       │                                       └──────────┘
       │                                             │
       │         PyMySQL (parameter terikat)          │
       ▼                                             ▼
  ┌──────────────────────────────────────────────────────────┐
  │  MariaDB/MySQL  pelaporan  (+ tabel ai_jobs)          │
  └──────────────────────────────────────────────────────────┘
```

## Prasyarat

| Komponen | Versi / Catatan |
| -------- | --------------- |
| Python   | 3.11 – 3.13 (diuji pada 3.13) |
| Kunci API Gemini | Dari https://aistudio.google.com/apikey → `AI_GEMINI_API_KEY` |
| Ollama   | *(opsional — cadangan lokal)* Terpasang & berjalan di `http://localhost:11434` |
| Model    | Gemini `gemini-3.8-flash` (utama); lokal `qwen2.5:7b` (Q4), cadangan `qwen2.5:3b` |
| MariaDB  | 10.4+ (XAMPP), database `pelaporan` |
| RAM      | 8–16 GB bila memakai Ollama lokal; Gemini berjalan di cloud |

Unduh model:

```powershell
ollama pull qwen2.5:7b
# opsional, bila RAM terbatas:
ollama pull qwen2.5:3b
```

## Memulai cepat (Windows)

```powershell
cd ai-service

# 1) Siapkan .env
Copy-Item .env.example .env
# edit .env bila perlu (paling penting: AI_SERVICE_KEY harus sama dengan PHP)

# 2) Jalankan (otomatis membuat .venv & memasang dependensi)
.\run.ps1
```

Atau manual:

```powershell
py -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt
Copy-Item .env.example .env
.venv\Scripts\python -m uvicorn app.main:app --host 127.0.0.1 --port 8000
```

> **Penting:** nilai `AI_SERVICE_KEY` pada `.env` **wajib sama** dengan
> `AI_SERVICE_KEY` di `includes/config.php`. Gunakan kunci acak Anda sendiri
> (jangan biarkan nilai contoh dari `.env.example`).

## Konfigurasi (.env)

Seluruh variabel memakai awalan `AI_` (lihat `.env.example` dan `app/config.py`).
Ringkasan yang sering diubah:

| Variabel | Bawaan | Guna |
| -------- | ------ | ---- |
| `AI_SERVICE_KEY` | *(wajib diisi sendiri)* | Kunci layanan (header `X-Service-Key`). |
| `AI_GEMINI_API_KEY` | *(opsional; kosong = Ollama saja)* | Kunci AI Studio; mengaktifkan Gemini sebagai model utama. |
| `AI_GEMINI_MODEL` / `AI_GEMINI_MODEL_FALLBACK` | `gemini-3.8-flash` / `gemini-3.5-flash-lite` | Model Gemini utama & cadangan. |
| `AI_GEMINI_SEARCH_MODEL` | `gemini-3.5-flash-lite` | Model pemanggil Google Search grounding (alat `web_search`). |
| `AI_MODEL` / `AI_MODEL_FALLBACK` | `qwen2.5:7b` / `qwen2.5:3b` | Model Ollama (cadangan lokal). |
| `AI_NUM_CTX` | `8192` | Ukuran konteks Ollama. |
| `AI_DEGRADE_DETERMINISTIC` | `false` | `false`: seluruh model mati → HTTP 503. `true`: pakai hasil deterministik. |
| `AI_DB_APP_USER` / `AI_DB_APP_PASSWORD` | `pelaporan_app` / … | Akun tulis. |
| `AI_DB_READONLY_USER` / `…_PASSWORD` | `bi_reader` / … | Akun baca-saja (jatuh ke akun aplikasi bila belum diberi hak). |
| `AI_BACKUP_DIR` | `../backups/db` | Folder berkas `.sql` (sama dengan PHP). |
| `AI_EXPORT_DIR` | `exports` | Folder `.xlsx`/`.docx`. |
| `AI_JOBS_ENABLED` | `true` | Aktifkan pekerja antrean. |

## Menjalankan

```powershell
.\run.ps1                      # server web (127.0.0.1:8000)
.\run.ps1 -Port 8080           # ganti port
.\run.ps1 -Worker              # pekerja ai_jobs (gelung tanpa henti)
.\run.ps1 -Worker -Sekali      # proses semua job lalu keluar
```

`run.bat` menyediakan padanan tanpa PowerShell:

```bat
run.bat            :: server
run.bat worker     :: pekerja (gelung)
run.bat worker sek :: proses semua job lalu keluar
```

Dokumentasi interaktif (Swagger) tersedia di `/docs` bila `AI_DOCS_PUBLIC=true`.

## Endpoint & kontrak

Semua endpoint **kecuali `/health`** memerlukan header:
`X-Service-Key: <AI_SERVICE_KEY>`. Tanpa kunci sah → **401** `{"detail": ...}`.
Bila seluruh rantai model tidak dapat dijangkau → **503** `{"detail": ...}`
(atau jawaban deterministik bila `AI_DEGRADE_DETERMINISTIC=true`).

### `GET /health`

Selalu **200**, bahkan saat Gemini/Ollama/DB mati (PHP hanya memeriksa status 2xx).

```json
{"ok": true, "model": "gemini-3.8-flash", "gemini": true, "ollama": true,
 "database": true, "versi": "1.0.0",
 "models_tersedia": ["gemini-3.8-flash", "qwen2.5:7b"]}
```

### `POST /chat`

```json
{"pesan": "Cari laporan saya minggu lalu", "user_id": 1, "role": "karyawan",
 "nama": "Vio", "session": 0, "konteks": {}}
```

```json
{"ok": true, "balasan": "…", "sumber": [{"tipe":"laporan_harian","id":4,"tanggal":"2026-10-11","kegiatan":"asd"}],
 "session": 0, "model": "gemini-3.8-flash", "tools_dipakai": ["search_notes"], "iterasi": 2}
```

PHP membaca `balasan` dan `sumber`.

### `POST /summarize`

```json
{"jenis": "mingguan", "parameter": {"user_id": 1, "role": "karyawan"}}
```

Balasan: `ok, jenis, balasan, ringkasan, periode, statistik, tabel, model, mode`.
`jenis` ∈ {`mingguan`,`bulanan`,`harian`,`periode`}.

### `POST /narrative`

```json
{"parameter": {"user_id": 1, "role": "karyawan",
  "tanggal_dari": "2026-10-01", "tanggal_sampai": "2026-10-31"}}
```

Balasan: `ok, balasan, narasi, parameter, statistik, model, mode`.

### `POST /validate`

```json
{"laporan": {"tanggal": "2026-10-14", "kegiatan": "…", "deskripsi": "…"}}
```

`laporan` boleh berupa satu objek, **daftar** objek, atau parameter periode
(`user_id`, `tanggal_dari`, `tanggal_sampai`). Balasan:
`ok, balasan, verdict, rincian, jumlah, model, mode`.

### `/jobs`

| Endpoint | Guna |
| -------- | ---- |
| `POST /jobs/proses?maks=5` | Klaim & proses job `antre`. |
| `POST /jobs/pulihkan-macet?menit=15` | Kembalikan job `proses` yang macet. |
| `GET /jobs?limit=50&status=selesai` | Daftar job. |
| `GET /jobs/{id}` | Status & hasil satu job. |

### `/exports/{nama}`

Mengunduh berkas `.xlsx`/`.docx` hasil `export_excel`/`export_word`
(tetap dilindungi kunci layanan).

## Alat (tools) agen

| Alat | Peran | Guna |
| ---- | ----- | ---- |
| `search_notes` | semua | Cari laporan harian. |
| `update_note` | semua\* | Ubah laporan; **pratinjau** bawaan, `konfirmasi=true` menyimpan. |
| `validate_report` | semua | Validasi kelengkapan. |
| `statistics` | semua | Statistik kegiatan. |
| `trend_analysis` | semua | Tren antar pekan/bulan. |
| `weekly_summary` / `monthly_summary` | semua | Data ringkasan. |
| `narrative_report` | semua | Narasi laporan. |
| `export_excel` / `export_word` | semua | `.xlsx` / `.docx`. |
| `backup_database` / `list_backups` | admin | Cadangan & riwayat. |
| `restore_database` | admin | **Approval-gated** (`konfirmasi="PULIHKAN"`). |
| `send_email` / `send_whatsapp` | semua | **Approval-gated** — hanya usulan. |
| `web_search` | semua | Cari info publik/terkini di internet (Google Search Gemini; cadangan SearXNG/DuckDuckGo). |
| `daftar_jadwal` | semua | Tampilkan agenda pribadi pengguna. |
| `buat_jadwal` / `ubah_jadwal` / `hapus_jadwal` | semua | Kelola agenda; **pratinjau** bawaan, `konfirmasi=true` menerapkan. |
| `ingatkan_jadwal` | semua | Kirim pengingat agenda segera; butuh `konfirmasi=true`. |

Total **21 alat**. Alat jadwal hanya menyentuh agenda **milik pemanggil**
(`ctx.user_id`) — tanpa akses lintas pengguna.

\* `update_note` hanya untuk pemilik (atau admin) dan hanya saat status
`draft`/`perlu_revisi`. Setiap penerapan dicatat ke `audit_log`.

**Tindakan berisiko** (`restore_database`, `send_email`, `send_whatsapp`, dan
seluruh alat tulis jadwal) tidak pernah dijalankan otomatis: alat hanya
mengembalikan *usulan* yang harus disetujui pengguna. `restore_database` juga
otomatis membuat **cadangan pengaman** sebelum menimpa data.

Pencarian web diatur lewat `AI_WEB_SEARCH_AKTIF`, `AI_SEARXNG_URL`,
`AI_WEB_SEARCH_HASIL`, dan (opsional) `AI_HTTP_CA_BUNDLE` untuk CA kustom di
balik proxy korporat — urutan provider: Google Search Gemini → SearXNG →
DuckDuckGo (lihat `docs/SEARXNG.md`). Panduan tiap kemampuan ada di
`skills/laporan-harian/`, `skills/pencarian/`, dan `skills/jadwal/`.

## Antrean ai_jobs

Job disimpan di tabel `ai_jobs` (`id` UUID, `jenis`, `parameter` JSON,
`status` ∈ `antre|proses|selesai|gagal`). Jenis yang didukung antara lain:
`search_notes`, `update_note`, `validate`, `statistics`, `trend_analysis`,
`weekly_summary`, `monthly_summary`, `narrative`, `export_excel`,
`export_word`, `backup_database`, `restore_database`, `send_email`,
`send_whatsapp`.

Pengklaiman bersifat **atomik** tanpa `SKIP LOCKED` (MariaDB 10.4 belum
mendukungnya). Job diproses secara **deterministik** (tanpa LLM).

```powershell
# proses job yang mengantre
curl.exe -X POST "http://127.0.0.1:8000/jobs/proses?maks=5" -H "X-Service-Key: <kunci>"
# lihat status satu job
curl.exe "http://127.0.0.1:8000/jobs/<uuid>" -H "X-Service-Key: <kunci>"
```

Untuk produksi di Windows, jalankan pekerja lewat **Task Scheduler** dengan
`--sekali` tiap menit, atau jalankan `run.ps1 -Worker` sebagai proses layanan.

## Pengujian

```powershell
.venv\Scripts\python -m pytest
```

Seluruh uji berjalan **tanpa Gemini/Ollama hidup** (klien model di-*mock*).

## Skill

Panduan kerja agen (Bahasa Indonesia) ada di folder `skills/`:

- `skills/laporan-harian/SKILL.md` — peran & hak akses, skema basis data, alur
  status laporan, konvensi ringkasan, kontrak endpoint, aturan tindakan berisiko.
- `skills/pencarian/SKILL.md` — kapan memakai `web_search` vs data internal,
  urutan penyedia (Gemini Search/SearXNG/DuckDuckGo), cache, dan cara menyebut
  sumber.
- `skills/jadwal/SKILL.md` — model data `jadwal`, alur *approval-gated*, format
  waktu, dan pengingat otomatis (`scripts/pengingat_jadwal.php`).

## Catatan penting

- **Akun baca-saja.** Kueri baca diarahkan ke `bi_reader`; bila akun itu belum
  diberi hak `SELECT` (mis. `019_akses_baca_ai.sql` belum dijalankan untuk tabel
  inti, atau `powerbi/sql/02_views.sql` untuk view), layanan **otomatis jatuh ke
  akun aplikasi** dan mencatat peringatan `"Kunci baca-saja gagal …"`. Berikan
  hak yang sesuai agar pemisahan hak benar-benar berlaku.
- **Degradasi.** Bawaan `AI_DEGRADE_DETERMINISTIC=false`: saat seluruh model
  (Gemini & Ollama) mati, endpoint AI membalas **503** `{"detail": …}`
  (sesuai kontrak PHP). Set `true` untuk memakai hasil deterministik tanpa
  model.
- **Penulisan terbatas.** `update_note` menulis ke `laporan_harian`, dan alat
  jadwal menulis ke `jadwal` — keduanya dengan daftar-putih/pemeriksaan pemilik,
  penyaringan, serta pencatatan audit.
- **Pencarian web** mengembalikan `ok:false` dengan pesan jelas bila Gemini
  Search, SearXNG, dan cadangan DuckDuckGo semuanya gagal (mis. TLS proxy).
  Set `AI_HTTP_CA_BUNDLE` bila berada di balik proxy korporat.
- **Semua kueri ber-parameter** (`%s`) — terlindungi dari injeksi SQL.
- **`users` tidak punya `updated_at`** (hanya `created_at`).

## Struktur berkas

```
ai-service/
├─ app/
│  ├─ main.py              # FastAPI: middleware, handler galat, mount /exports
│  ├─ config.py            # pengaturan terpusat (pydantic-settings, prefix AI_)
│  ├─ auth.py              # middleware X-Service-Key
│  ├─ db.py                # PyMySQL: kueri ber-parameter, jatuh baca-saja, audit
│  ├─ gemini_client.py     # klien REST Gemini (function calling + Google Search)
│  ├─ ollama_client.py     # klien async Ollama (cadangan lokal)
│  ├─ llm.py               # rantai klien: Gemini utama → Ollama cadangan
│  ├─ agent.py             # gelung agen (function calling)
│  ├─ schemas.py           # skema permintaan/balasan (Pydantic)
│  ├─ prompts/             # prompt sistem (Bahasa Indonesia, .md)
│  ├─ routers/             # /health /chat /summarize /narrative /validate /jobs
│  ├─ tools/               # search_notes, update_note, validate_report, … , backup,
│  │                       #   web_search, buat_jadwal, daftar_jadwal, …
│  ├─ services/            # logika domain murni (reporting, exporter, backup, jobs)
│  └─ jobs/worker.py       # pekerja latar belakang ai_jobs
├─ skills/laporan-harian/SKILL.md
├─ skills/pencarian/SKILL.md
├─ skills/jadwal/SKILL.md
├─ tests/                  # pytest (tanpa Gemini/Ollama hidup; 78 uji)
├─ .env.example
├─ requirements.txt
├─ run.ps1 / run.bat
└─ README.md
```

---

## English

**AI Service for Reporting** is a Python/FastAPI microservice that acts as the
single gateway between the PHP **Activity Reporting** app and LLM models —
**Google Gemini (`gemini-3.8-flash`) as the primary model**, with local
**Ollama (Qwen 2.5)** as automatic fallback. It provides a chat assistant with
tool/function calling, summaries, narratives, validation, document export,
database backup/restore, and processing of the `ai_jobs` queue. The PHP app
never talks to Gemini/Ollama directly.

**Quick start**

```powershell
cd ai-service
Copy-Item .env.example .env        # set AI_SERVICE_KEY (must match PHP)
                                   # + AI_GEMINI_API_KEY (Gemini AI Studio)
.\run.ps1                          # creates .venv, installs deps, starts server
```

**Contract** — every endpoint except `GET /health` requires the
`X-Service-Key` header. `GET /health` always returns 200. If the whole model
chain (Gemini & Ollama) is down, AI endpoints return HTTP **503**
`{"detail": ...}` unless `AI_DEGRADE_DETERMINISTIC=true`. The PHP client reads
`balasan`/`jawaban`, `sumber`, and the error `detail`/`pesan` fields.

**Endpoints** — `GET /health`, `POST /chat`, `POST /summarize`,
`POST /narrative`, `POST /validate`, `POST /jobs/proses`,
`POST /jobs/pulihkan-macet`, `GET /jobs`, `GET /jobs/{id}`, `GET /exports/{name}`.

**Tools** — `search_notes`, `update_note` (dry-run by default),
`validate_report`, `statistics`, `trend_analysis`, `weekly_summary`,
`monthly_summary`, `narrative_report`, `export_excel`, `export_word`,
`backup_database`, `list_backups`, `restore_database` (approval-gated),
`send_email`, `send_whatsapp` (proposals only).

**Safety** — read queries prefer the read-only account `bi_reader` and fall
back to the app account when it lacks grants. Destructive actions
(restore, email, WhatsApp) require explicit user approval. All SQL is
parameterized.

**Web search** — the `web_search` tool tries Google Search grounding (Gemini)
first, then a self-hosted SearXNG, then DuckDuckGo Lite as a last resort.
See `docs/SEARXNG.md`.

**Tests** — `.venv\Scripts\python -m pytest` (runs without live Gemini/Ollama;
78 tests).
