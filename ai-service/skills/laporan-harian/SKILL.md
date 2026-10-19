---
name: laporan-harian
description: >-
  Panduan kerja untuk Sistem Pelaporan Kegiatan Harian Pertamina
  Hulu Rokan. Gunakan skill ini saat membantu karyawan, mentor, atau admin
  menelusuri, memeriksa, merangkum, memvalidasi, mengekspor, atau memelihara
  (cadangan/pemulihan) laporan kegiatan. Mencakup kontrak endpoint layanan AI
  FastAPI, skema basis data pelaporan, peran & hak akses, alur status
  laporan, konvensi ringkasan, serta alat yang boleh dipanggil.
---

# Skill: Laporan Kegiatan Harian

## Ringkasan

Aplikasi **Sistem Pelaporan Kegiatan Harian** dipakai karyawan di
lingkungan Pertamina Hulu Rokan untuk mencatat kegiatan harian, absensi, kasus,
dan laporan akhir. Ada tiga peran:

| Peran      | Hak akses                                                             |
| ---------- | --------------------------------------------------------------------- |
| `karyawan` | Karyawan. **Hanya** laporan & data miliknya sendiri.                |
| `mentor`   | Pembimbing. Melihat data seluruh peserta, memberi catatan, memeriksa. |
| `admin`    | Pengelola. Akses penuh, termasuk pencadangan/pemulihan basis data.    |

Layanan AI berupa microservice **Python/FastAPI** (`ai-service/`) yang menjadi
gateway tunggal antara aplikasi PHP dan model LLM: **Google Gemini
(`gemini-3.8-flash`) sebagai model utama**, dengan **Ollama (`qwen2.5:7b`)**
sebagai cadangan lokal otomatis. PHP tidak pernah memanggil Gemini/Ollama
langsung.

## Kontrak layanan AI

Semua endpoint **kecuali** `GET /health` memerlukan header
`X-Service-Key: <AI_SERVICE_KEY>`. Bila seluruh rantai model (Gemini maupun
Ollama) tidak tersedia, endpoint AI membalas HTTP **503** dengan badan
`{"detail": "..."}` (atau jawaban deterministik bila
`AI_DEGRADE_DETERMINISTIC=true`).

| Metode & jalur            | Guna                                        | Kunci balasan |
| ------------------------- | ------------------------------------------- | ------------- |
| `GET  /health`            | Status layanan (selalu 200)                 | `ok, model, ollama, database` |
| `POST /chat`              | Tanya jawab asisten (function calling)      | `balasan, sumber, tools_dipakai` |
| `POST /summarize`         | Ringkasan mingguan/bulanan/harian/periode   | `balasan, ringkasan, statistik, tabel` |
| `POST /narrative`         | Narasi laporan panjang                      | `balasan, narasi` |
| `POST /validate`          | Periksa kelengkapan laporan                 | `verdict, rincian, jumlah` |
| `POST /jobs/proses`       | Proses job `ai_jobs` yang mengantre         | `diproses, job[]` |
| `POST /jobs/pulihkan-macet` | Kembalikan job macet ke antrean           | `dipulihkan` |
| `GET  /jobs`              | Daftar job                                  | `jumlah, job[]` |
| `GET  /jobs/{id}`         | Status & hasil satu job                     | `id, jenis, status, hasil` |
| `GET  /exports/{nama}`    | Unduh berkas `.xlsx`/`.docx` hasil ekspor   | berkas biner |

Contoh:

```bash
curl -X POST http://127.0.0.1:8000/chat \
  -H "X-Service-Key: $AI_SERVICE_KEY" -H "Content-Type: application/json" \
  -d '{"pesan":"Cari laporan saya minggu lalu","user_id":1,"role":"karyawan","nama":"Vio"}'
```

## Skema basis data (inti)

Database `pelaporan` (MariaDB 10.4 / MySQL).

- `users(id, nama, email, password, role, status, email_verified_at, divisi,
  telepon, foto_profil, created_at, updated_at)` — `role` ∈
  {`karyawan`,`mentor`,`admin`}; `status` ∈ {`aktif`,`menunggu`,`nonaktif`}.
  Tabel `users` **tidak** punya kolom `username`; pencocokan pengguna memakai
  `email` atau `nama`.
- `laporan_harian(id, user_id, tanggal, jam_mulai, jam_selesai, kegiatan,
  deskripsi, hasil, kendala, rencana_berikutnya, status, catatan_mentor,
  created_at, updated_at)`.
- `absensi(id, user_id, tanggal, jam_masuk, jam_pulang, status, keterangan,
  kegiatan_hari_ini, kondisi_kesehatan, created_at, updated_at)` —
  `status` ∈ {`hadir`,`izin`,`sakit`,`alpa`}; `kondisi_kesehatan` ∈
  {`sehat`,`kurang_sehat`,`sakit`,`pemulihan`}.
- `jadwal(id, user_id, judul, deskripsi, mulai, selesai, lokasi,
  pengingat_menit, pengingat_terkirim, status, dibuat_pada, diperbarui_pada)` —
  `status` ∈ {`rencana`,`selesai`,`batal`}.
- `komentar_laporan`, `kasus`, `pihak_terlibat`, `audit_log`,
  `laporan_terkirim`, `backup_riwayat`, `ai_jobs`, `sesi_ai`, `impor_profil`,
  `impor_sejarah`.

### Status laporan harian

| Status         | Arti                                    | Boleh diubah pemilik |
| -------------- | --------------------------------------- | -------------------- |
| `draft`        | Masih disusun                           | ya                   |
| `dikirim`      | Menunggu pemeriksaan mentor             | tidak                |
| `perlu_revisi` | Mentor minta perbaikan                  | ya                   |
| `diperiksa`    | Sudah diperiksa, final untuk periode itu| tidak                |

### Kelengkapan laporan

Sebuah laporan dianggap **lengkap** bila memuat: `tanggal` (YYYY-MM-DD, bukan
masa depan), `kegiatan` (≤ 255 karakter), dan `deskripsi`. Disarankan:
`jam_mulai`, `jam_selesai`, `hasil`, `kendala`, `rencana_berikutnya`. Jam
berformat `HH:MM` dan `jam_selesai` tidak boleh mendahului `jam_mulai`.

## Alat (tools) layanan AI

Gelung agen memanggil alat berdasarkan peran pemanggil:

| Alat               | Peran   | Guna singkat                                                  |
| ------------------ | ------- | ------------------------------------------------------------- |
| `search_notes`     | semua   | Cari laporan harian (rentang tanggal, kata kunci, status).    |
| `update_note`      | semua\* | Ubah satu laporan; **bawaan pratinjau**, `konfirmasi=true` menyimpan. |
| `validate_report`  | semua   | Validasi kelengkapan laporan.                                 |
| `statistics`       | semua   | Statistik kegiatan (jumlah, sebaran status, hari aktif).      |
| `trend_analysis`   | semua   | Tren antar pekan/bulan.                                       |
| `weekly_summary`   | semua   | Data ringkasan mingguan.                                      |
| `monthly_summary`  | semua   | Data ringkasan bulanan.                                       |
| `narrative_report` | semua   | Narasi laporan panjang.                                       |
| `export_excel`     | semua   | Buat `.xlsx`.                                                 |
| `export_word`      | semua   | Buat `.docx`.                                                 |
| `backup_database`  | admin   | Buat cadangan `mysqldump`.                                    |
| `list_backups`     | admin   | Daftar berkas cadangan.                                       |
| `restore_database` | admin   | **Approval-gated**; butuh `konfirmasi="PULIHKAN"`.            |
| `send_email`       | semua   | **Approval-gated**; hanya usulan, tidak mengirim.             |
| `send_whatsapp`    | semua   | **Approval-gated**; hanya usulan, tidak mengirim.             |
| `web_search`       | semua   | Cari informasi publik/terkini di internet (SearXNG, cadangan DuckDuckGo). |
| `daftar_jadwal`    | semua   | Tampilkan agenda milik pengguna.                              |
| `buat_jadwal`      | semua   | Tambah jadwal; **bawaan pratinjau**, `konfirmasi=true` menyimpan. |
| `ubah_jadwal`      | semua   | Ubah jadwal; **bawaan pratinjau**, `konfirmasi=true` menyimpan. |
| `hapus_jadwal`     | semua   | Hapus jadwal; **bawaan pratinjau**, `konfirmasi=true` menghapus. |
| `ingatkan_jadwal`  | semua   | Kirim pengingat segera (dalam aplikasi); butuh `konfirmasi=true`. |

\* `update_note` hanya untuk pemilik laporan (atau admin), dan hanya saat
status `draft`/`perlu_revisi`.

### Aturan tindakan berisiko (wajib dipatuhi)

1. `restore_database`, `send_email`, dan `send_whatsapp` **tidak pernah**
   dijalankan otomatis. Sampaikan usulan kepada pengguna dan minta persetujuan
   eksplisit dulu.
2. `update_note` bawaan hanya **pratinjau**. Jangan set `konfirmasi=true`
   tanpa persetujuan pengguna.
3. Peran `karyawan` tidak pernah boleh melihat atau mengubah data pengguna
   lain. Untuk peran ini, alat otomatis membatasi ke `user_id` sendiri.

## Konvensi ringkasan

- **Mingguan** dimulai **Senin** dan berakhir **Minggu**.
- **Bulanan** mengikuti bulan kalender (tanggal 1 s/d akhir bulan).
- Sertakan selalu: periode, jumlah laporan, jumlah hari aktif, sebaran status,
  kegiatan utama, kendala berulang, dan rekomendasi.
- Saat menghitung "hari kerja", hitung Senin–Jumat saja.
- Seluruh keluaran dalam **Bahasa Indonesia** yang formal dan ringkas.

## Menjalankan & merawat

```powershell
# dari ai-service/
.\run.ps1                      # server (127.0.0.1:8000)
.\run.ps1 -Worker              # pekerja ai_jobs (gelung)
.\run.ps1 -Worker -Sekali      # proses semua job lalu keluar
.venv\Scripts\python -m pytest # uji tanpa Ollama (mock)
```

- Kredensial basis data diambil dari `.env` (`AI_DB_*`). Kueri baca diarahkan
  ke akun baca-saja `bi_reader`; bila belum diberi hak, otomatis jatuh ke akun
  aplikasi `pelaporan_app` (dicatat sebagai peringatan).
- Berkas ekspor ditulis ke `ai-service/exports/`.
- Berkas cadangan ditulis ke `../backups/db/` (sama dengan PHP) dengan pola
  nama `pelaporan_YYYYmmdd_HHMMSS_<tipe>.sql`.

## Rujukan berkas

- Kontrak HTTP PHP: `includes/ai_client.php`, `api_chat.php`.
- Skema & migrasi: `database/migrations/`.
- Layanan AI: `ai-service/app/` (`routers/`, `tools/`, `services/`).
- Prompt sistem: `ai-service/app/prompts/*.md`.
