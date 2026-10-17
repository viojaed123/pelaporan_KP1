---
name: pencarian
description: >-
  Panduan memakai kemampuan pencarian web pada Asisten Pelaporan. Gunakan skill
  ini bila pengguna menanyakan informasi publik, berita, peraturan, istilah
  teknis mutakhir, atau hal apa pun yang tidak tersedia di basis data aplikasi.
  Menjelaskan kapan memakai `web_search`, kapan memakai data internal
  (`search_notes`, `statistics`), cara memasang SearXNG, dan cara menyebut
  sumber dengan benar.
---

# Skill: Pencarian Web

## Ringkasan

Asisten Pelaporan punya dua jenis sumber informasi:

| Sumber              | Alat                                                       | Kapan dipakai                                   |
| ------------------- | ---------------------------------------------------------- | ----------------------------------------------- |
| **Data internal**   | `search_notes`, `statistics`, `trend_analysis`, `daftar_jadwal` | Pertanyaan soal laporan/absensi/jadwal/kasus.   |
| **Internet publik** | `web_search`                                               | Berita, peraturan, istilah, informasi terkini.  |

Aturan mutlak: **data laporan/absensi internal TIDAK boleh dicari lewat
`web_search`.** Gunakan alat data internal.

## Kapan memakai `web_search`

Pakai bila:
- Pertanyaan menyangkut **berita/kejadian terkini**.
- Menanyakan **peraturan/standar eksternal** (mis. regulasi migas, K3).
- Menjelaskan **istilah teknis** yang mungkin berubah.
- Memverifikasi fakta yang tidak ada di basis data aplikasi.

Jangan pakai bila:
- Pertanyaan tentang laporan, absensi, jadwal, atau kasus pengguna.
- Statistik internal aplikasi.
- Tindakan menulis apa pun (pencarian hanya membaca).

## Cara memanggil

Parameter `web_search`:

| Parameter | Wajib | Keterangan                                             |
| --------- | ----- | ------------------------------------------------------ |
| `kueri`   | ya    | Kata kunci / pertanyaan.                               |
| `jumlah`  | tidak | Jumlah hasil 1–10 (bawaan `AI_WEB_SEARCH_HASIL`, = 5). |
| `bahasa`  | tidak | Kode bahasa SearXNG, mis. `id` atau `en`.              |
| `segar`   | tidak | `true` = abaikan cache, paksa pencarian baru.          |

Contoh: `web_search(kueri="regulasi K3 sektor migas 2025", bahasa="id")`.

## Penyedia & konfigurasi

1. **SearXNG** (utama) — instans meta-search yang dihosting sendiri.
   - Set `AI_SEARXNG_URL` (mis. `http://127.0.0.1:8888`) pada `ai-service/.env`.
   - Jalankan lewat Docker, contoh:

     ```yaml
     services:
       searxng:
         image: searxng/searxng:latest
         ports: ["8888:8080"]
         environment:
           - SEARXNG_BASE_URL=http://localhost:8888/
     ```

2. **DuckDuckGo Lite** (cadangan) — dipakai otomatis bila `AI_SEARXNG_URL`
   kosong atau SearXNG gagal. Best-effort, tanpa kunci API.

Sakelar utama: `AI_WEB_SEARCH_AKTIF`. Bila `false`, `web_search` selalu
menolak dengan pesan jelas.

Cache: hasil disimpan sementara selama `AI_WEB_SEARCH_CACHE_MENIT` menit untuk
menghemat permintaan. Set `segar=true` untuk melewati cache.

## Cara menjawab

- **Selalu sebutkan tautan sumber** setiap klaim dari internet.
- Tegaskan bahwa hasil internet **bukan data resmi aplikasi** dan bisa berubah.
- Bila `web_search` mengembalikan `{"ok": false, ...}`, jelaskan penyebabnya
  (mis. SearXNG belum aktif / tidak ada internet) dan tawarkan alternatif.

## Rujukan berkas

- Alat: `ai-service/app/tools/search.py`.
- Konfigurasi: `ai-service/app/config.py`, `ai-service/.env.example`.
- Prompt aturan: `ai-service/app/prompts/system_chat.md`, `.../domain.md`.
