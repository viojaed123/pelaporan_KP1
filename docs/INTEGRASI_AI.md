# Integrasi AI

Arsitektur: **PHP → gateway FastAPI (`ai-service`) → Gemini (`gemini-3.8-flash`,
utama) dengan cadangan Ollama (`qwen2.5:7b`)**. PHP tidak pernah bicara langsung
dengan model; semua pemanggilan melewati satu gateway yang memiliki kunci layanan.

```
Browser ──> api_chat.php / ringkasan.php ──> includes/ai_client.php
                                                │  X-Service-Key
                                                ▼
                                    http://127.0.0.1:8000   (FastAPI)
                                                │
                                   rantai model (app/llm.py)
                                     │             └──► Ollama qwen2.5 (cadangan)
                                     └──► Google Gemini (utama)
                                          gemini-3.8-flash → gemini-3.5-flash-lite
```

---

## 1. Konfigurasi

| Sisi | Berkas | Nilai |
|---|---|---|
| PHP | `includes/config.php` | `AI_BASE_URL`, `AI_SERVICE_KEY`, `AI_AKTIF`, `AI_TIMEOUT_DETIK`, `AI_TIMEOUT_PANJANG` |
| Python | `ai-service/.env` | `AI_SERVICE_KEY` **harus identik**, `AI_GEMINI_API_KEY` (kunci AI Studio) |
| Laravel | `.env` | `LLM_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai`, `LLM_API_KEY`, `LLM_MODEL` (untuk SPA & APK Android) |

Bila kunci layanan tidak sama, setiap permintaan dibalas **401**.

Rantai model (`app/llm.py`): `gemini-3.8-flash` → `gemini-3.5-flash-lite` →
Ollama `qwen2.5:7b` → `qwen2.5:3b` → (opsional) jawaban deterministik.
Kosongkan `AI_GEMINI_API_KEY` untuk kembali ke pola Ollama-saja.

Cek kesehatan (satunya-satunya endpoint tanpa kunci):

```bat
curl http://127.0.0.1:8000/health
{"ok":true,"model":"gemini-3.8-flash","gemini":true,"ollama":true,"database":true,"versi":"1.0.0",...}
```

---

## 2. Kontrak endpoint

Semua endpoint berikut — kecuali `/health` — wajib header `X-Service-Key`.

| Endpoint | Dipakai oleh | Balasan utama |
|---|---|---|
| `GET /health` | `ai_hidup()` | `{ok, model, ollama, database, versi, models_tersedia}` |
| `POST /chat` | `api_chat.php` → `asisten.php` | `{ok, balasan, sumber, session, model, tools_dipakai, iterasi}` |
| `POST /summarize` | `ai_ringkas()` (ringkasan.php) | `{ok, jenis, balasan, ringkasan, periode, statistik, tabel, model, mode}` |
| `POST /narrative` | `ai_narasi()` | `{ok, balasan, narasi, ...}` |
| `POST /validate` | `api_validasi.php` → `ai_validasi()` (tombol *Periksa kelengkapan (AI)* di `buat_laporan.php` / `edit_revisi.php`) | `{ok, balasan, verdict, rincian, jumlah, model, mode}` |
| `POST /jobs` | `ai_antrian_buat()` | antrean tugas berat |
| `GET /jobs/{id}` | `ai_antrian_status()` | status tugas |
| `GET /exports/{nama}` | unduhan hasil ekspor | berkas (butuh kunci; tanpa kunci → 401) |

Laporan model dikembalikan dalam bentuk `mode`:

* `model` — jawaban dihasilkan LLM
* `deterministik` — fallback aturan (dipakai saat Ollama mati **atau**
  `AI_DEGRADE_DETERMINISTIC=true`), hasil selalu sama untuk input sama
* `fallback` — model tidak siap dan deterministik dimatikan

---

## 3. Kemampuan

* **Pencatatan & pencarian catatan harian** — model memanggil alat
  (`tools_dipakai`, `sumber`) untuk mencari sebelum menjawab.
* **Validasi & verifikasi kelengkapan** laporan — tombol *Periksa kelengkapan
  (AI)* pada halaman buat/revisi laporan memanggil `api_validasi.php`
  (endpoint `POST /validate`) dan menampilkan skor 0–100, daftar kekurangan,
  serta catatan *sebelum* laporan disimpan.
* **Statistik, tren, ringkasan mingguan/bulanan** (dengan narasi).
* **Cadangan & pemulihan** — dari AI: hanya lewat antrian dan **wajib
  persetujuan teks `PULIHKAN`**.
* **Ekspor Excel/Word otomatis** — berjalan sebagai tugas latar belakang;
  hasil diunduh dari `/exports/{nama}`.
* **Pengiriman email/WhatsApp** — AI **hanya menyiapkan usulan**; pengiriman
  nyata tetap melalui persetujuan pengguna di `distribusi_laporan.php`.
* **Jadwal & pengingat** — `daftar_jadwal` (baca) dan `buat_jadwal`,
  `ubah_jadwal`, `hapus_jadwal`, `ingatkan_jadwal` (tulis, *approval-gated*:
  bawaan hanya pratinjau, terapkan dengan `konfirmasi=true`). Hanya menyentuh
  agenda **milik pemanggil**.
* **Pencarian web** — `web_search` untuk berita/peraturan/istilah mutakhir:
  **Google Search grounding bawaan Gemini** (utama; butuh `AI_GEMINI_API_KEY`),
  cadangan SearXNG lalu DuckDuckGo. Bukan sumber data internal; selalu sebut
  tautan sumber. Diatur lewat `AI_WEB_SEARCH_AKTIF`, `AI_SEARXNG_URL`, dan
  (opsional) `AI_HTTP_CA_BUNDLE` untuk TLS di balik proxy korporat.

Panduan rinci tiap kemampuan ada di `ai-service/skills/` (`laporan-harian`,
`pencarian`, `jadwal`).

---

## 4. Cara menguji

```powershell
$key = 'KUNCI_ANDA'   # samakan dengan AI_SERVICE_KEY di ai-service/.env

# tanpa kunci -> 401
Invoke-WebRequest 'http://127.0.0.1:8000/summarize' -Method Post -Body '{}' -ContentType 'application/json'

# dengan kunci -> 200 + narasi
$body = @{ jenis='mingguan'; parameter=@{ periode='mingguan' } } | ConvertTo-Json -Depth 4
Invoke-WebRequest 'http://127.0.0.1:8000/summarize' -Method Post `
    -Headers @{ 'X-Service-Key' = $key } -ContentType 'application/json' -Body $body
```

Melalui UI:

1. Pastikan `AI_AKTIF` benar dan layanan hidup → badge **AI siap** muncul di
   `asisten.php` dan `ringkasan.php`.
2. `ringkasan.php` → **Buat narasi** → blok `.narasi-hasil` terisi ±2–3 detik.
3. `asisten.php` → tanyakan sesuatu → balasan menyebut `sumber`/alat yang
   dipakai.
4. Kosongkan `AI_GEMINI_API_KEY` dan hentikan Ollama → endpoint tetap menjawab
   dengan `mode=deterministik` (atau 503 bila fallback deterministik
   dimatikan), tidak pernah error tak terduga.

---

## 5. Perangkat keras

**Gemini (utama)** berjalan di cloud Google — tidak memerlukan GPU/RAM apa pun;
cukup koneksi internet keluar ke `generativelanguage.googleapis.com`.

**Ollama (cadangan lokal)** opsional: **CPU-only, 8–16 GB RAM**:

| Model | Catatan |
|---|---|
| `qwen2.5:7b` Q4 | cadangan utama, `num_ctx=8192` |
| `qwen2.5:3b` | cadangan bila RAM terbatas |

Tugas berat berjalan di antrean asinkron sehingga antarmuka tidak pernah
menunggu model lebih dari `AI_TIMEOUT_DETIK`.

---

## 6. Berkas terkait

```
ai-service/            FastAPI, klien Gemini/Ollama, pengujian (78 uji lulus)
ai-service/app/gemini_client.py  klien REST Gemini (function calling + Google Search)
ai-service/app/llm.py  rantai klien: Gemini utama → Ollama cadangan
ai-service/README.md   panduan lengkap layanan Python
includes/ai_client.php ai_hidup, ai_panggil, ai_ringkas, ai_narasi, ai_validasi,
                       ai_antrian_buat, ai_antrian_status
api_chat.php           proxy chat berautensikasi untuk UI
asisten.php            layar percakapan
ringkasan.php          ringkasan mingguan/bulanan + narasi AI
```
