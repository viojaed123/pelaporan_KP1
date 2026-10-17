---
name: jadwal
description: >-
  Panduan mengelola agenda/jadwal dan pengingat pada Asisten Pelaporan. Gunakan
  skill ini bila pengguna ingin membuat, melihat, mengubah, menghapus, atau
  mengingatkan jadwal kegiatan. Menjelaskan alat `daftar_jadwal`, `buat_jadwal`,
  `ubah_jadwal`, `hapus_jadwal`, `ingatkan_jadwal`, aturan approval-gated, format
  waktu, serta pengingat otomatis lewat scripts/pengingat_jadwal.php.
---

# Skill: Jadwal & Pengingat

## Ringkasan

Asisten dapat mengelola agenda pribadi pengguna lewat tabel `jadwal`. Setiap
pengguna **hanya** dapat mengakses agenda miliknya sendiri — tidak ada akses
lintas pengguna, termasuk mentor/admin.

## Model data

`jadwal(id, user_id, judul, deskripsi, mulai, selesai, lokasi, pengingat_menit,
pengingat_terkirim, status, dibuat_pada, diperbarui_pada)`

| Kolom               | Keterangan                                                       |
| ------------------- | ---------------------------------------------------------------- |
| `judul`             | Nama kegiatan (wajib, ≤ 200 karakter).                          |
| `mulai`             | Waktu mulai, format `YYYY-MM-DD HH:MM:SS` (wajib).              |
| `selesai`           | Waktu selesai (opsional, tidak boleh sebelum `mulai`).          |
| `lokasi`            | Lokasi (opsional, ≤ 200 karakter).                              |
| `pengingat_menit`   | Menit sebelum `mulai` untuk mengirim pengingat (0–10080).       |
| `pengingat_terkirim`| 0/1 — penanda pengingat sudah dikirim (idempoten).              |
| `status`            | `rencana`, `selesai`, atau `batal`.                             |

## Alat

| Alat              | Guna                                              | Approval            |
| ----------------- | ------------------------------------------------- | ------------------- |
| `daftar_jadwal`   | Tampilkan agenda (rentang tanggal & status ops.). | baca saja           |
| `buat_jadwal`     | Tambah jadwal baru.                                | pratinjau → `konfirmasi=true` |
| `ubah_jadwal`     | Ubah satu jadwal.                                  | pratinjau → `konfirmasi=true` |
| `hapus_jadwal`    | Hapus satu jadwal.                                 | pratinjau → `konfirmasi=true` |
| `ingatkan_jadwal` | Kirim pengingat segera (notifikasi aplikasi).      | pratinjau → `konfirmasi=true` |

### Alur approval (wajib dipatuhi)

1. Panggil alat **tanpa** `konfirmasi` — hasilnya pratinjau
   (`status: menunggu_persetujuan`).
2. Sampaikan ringkasan usulan ke pengguna dan minta persetujuan eksplisit.
3. Setelah disetujui, panggil ulang dengan `konfirmasi=true`.

Jangan pernah set `konfirmasi=true` tanpa persetujuan pengguna.

## Format waktu

Terima masukan seperti: `2026-10-17`, `2026-10-17 09:00`,
`2026-10-17T09:00:00`, `17/10/2026 14:30`. Bila hanya tanggal, jam dianggap
`08:00`.

## Pengingat otomatis

Selain `ingatkan_jadwal` (segera), pengingat terjadwal dikirim oleh skrip:

```powershell
php C:\xampp\htdocs\pelaporan\scripts\pengingat_jadwal.php
php ... --kering        # uji tanpa mengirim
php ... --maks=200      # batas jumlah per jalan
```

Jadwalkan lewat Task Scheduler (mis. tiap 5 menit). Skrip mengirim notifikasi
aplikasi + surel + WhatsApp (bila `telepon` terisi dan WhatsApp aktif), lalu
menandai `pengingat_terkirim=1` agar tidak terkirim dua kali.

## Contoh percakapan

- "Ingatkan saya rapat besok jam 9" → `buat_jadwal` (pratinjau) →
  pengguna setuju → `buat_jadwal(konfirmasi=true)`.
- "Apa agenda saya minggu ini?" → `daftar_jadwal(tanggal_dari=..., tanggal_sampai=...)`.
- "Batalkan agenda #12" → `hapus_jadwal` atau `ubah_jadwal(status="batal")`.

## Rujukan berkas

- Alat: `ai-service/app/tools/jadwal.py`.
- Halaman: `jadwal.php`; helper PHP: `includes/jadwal.php`.
- Skrip pengingat: `scripts/pengingat_jadwal.php`.
- Migrasi tabel: `database/migrations/018_jadwal.sql`.
