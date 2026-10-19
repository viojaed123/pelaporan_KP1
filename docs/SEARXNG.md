# Menyiapkan SearXNG untuk `web_search`

Alat AI `web_search` memakai urutan penyedia berikut (lihat
`ai-service/app/tools/search.py`):

1. **Google Search grounding bawaan Gemini** (utama, otomatis aktif bila
   `AI_GEMINI_API_KEY` terisi) — tanpa konfigurasi tambahan, hasil disertai
   kutipan/tautan dari Google.
2. **SearXNG** (cadangan pertama) — instans meta-pencari yang dihosting
   sendiri, ramah privasi, dan stabil untuk pemakaian internal.
3. **DuckDuckGo Lite** (cadangan terakhir) — *best-effort* (sering memblokir
   pengambilan otomatis dan tidak tahan terhadap proxy TLS korporat).

Dokumen ini menjelaskan langkah 2 (SearXNG), yang **sangat disarankan**
sebagai cadangan bila Gemini Search dibatasi kuota (galat 429) atau jaringan
keluar diblokir.

---

## 1. Jalankan SearXNG dengan Docker

Buat `docker-compose.searxng.yml`:

```yaml
services:
  searxng:
    image: searxng/searxng:latest
    container_name: searxng
    restart: unless-stopped
    ports:
      - "8888:8080"
    environment:
      - SEARXNG_BASE_URL=http://localhost:8888/
    volumes:
      - ./searxng:/etc/searxng
```

Lalu:

```bat
docker compose -f docker-compose.searxng.yml up -d
```

## 2. Aktifkan format JSON

`web_search` membaca endpoint JSON (`?format=json`). Secara bawaan SearXNG
menonaktifkannya. Sunting `searxng/settings.yml`:

```yaml
search:
  formats:
    - html
    - json
```

Simpan lalu jalankan ulang kontainer.

## 3. Uji

```bat
curl.exe "http://127.0.0.1:8888/search?q=ibu+kota+indonesia&format=json"
```

Respons harus JSON dengan larik `results`.

## 4. Hubungkan ke layanan AI

Di `ai-service/.env` (SearXNG dipakai otomatis setelah Gemini Search):

```env
AI_WEB_SEARCH_AKTIF=true
AI_SEARXNG_URL=http://127.0.0.1:8888
AI_WEB_SEARCH_TIMEOUT=12
AI_WEB_SEARCH_HASIL=5
# Bila di balik proxy TLS korporat (mis. Zscaler), tunjuk CA tambahan:
# AI_HTTP_CA_BUNDLE=C:\path\ke\cacert-perusahaan.pem
```

Jalankan ulang layanan AI (`run.ps1`). Uji dari UI `asisten.php`:

> "Cari berita terbaru tentang transisi energi."

Balasan harus menyebut tautan sumber. Bila `web_search` mengembalikan
`ok:false`, pesannya menjelaskan penyebab (SearXNG belum aktif / tidak ada
internet / TLS gagal).

---

## Catatan

- Jangan menunjuk ke instans SearXNG publik (`searx.be`, dst.) untuk
  pemakaian rutin: banyak yang melarang akses API otomatis dan hasilnya
  tidak dapat diandalkan.
- SearXNG hanya membaca internet **publik**; data internal (laporan,
  absensi, jadwal) tetap dicari lewat alat data internal
  (`search_notes`, `statistics`, `daftar_jadwal`) — bukan `web_search`.
- Aturan lengkap ada di `ai-service/skills/pencarian/SKILL.md`.
