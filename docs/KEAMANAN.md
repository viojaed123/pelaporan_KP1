# Keamanan Sistem

Dokumen ini menjelaskan ancaman yang relevan dan mitigasi yang terpasang,
beserta cara memverifikasinya.

---

## 1. Pintu masuk (`login.php`)

| Ancaman | Mitigasi | Berkas |
|---|---|---|
| Tebakan kata sandi (*brute force*) | Pembatas laju + peningkatan penundaan + penguncian akun sementara setelah gagal beruntun | `includes/security.php` |
| Kata sandi lemah | Kebijakan panjang, huruf besar/kecil, angka, dan simbol; riwayat kata sandi tidak boleh dipakai ulang | `includes/security.php` |
| Pencurian sesi (*session hijack*) | Cookie `HttpOnly` + `SameSite=Lax`, penajaman sesi (regenerasi ID saat naik hak), penggunaan nama cookie `PHRSESS`, penetapan `session.use_strict_mode` | `includes/config.php`, `includes/session.php` |
| Pengambilalihan akun | Opsi 2FA OTP via email + kode cadangan satu pakai | `dua_faktor.php`, `includes/security.php` |
| Kredensial tersimpan terbuka | Kata sandi hanya disimpan sebagai `password_hash()` (argon2/bcrypt); hash **tidak** ikut diserialisasi ke sesi | `includes/auth.php` |

### Alur 2FA

1. `2fa_minta` → `2fa_aktifkan` (butuh OTP yang benar) — OTP dikirim lewat
   `kirim_email()`. Pada mode pengembangan (`SMTP_HOST=''`) OTP ditulis ke
   `backups/mail-log/YYYY-MM-DD.log`.
2. Setelah aktif, setiap sesi login diwajibkan melewati `dua_faktor.php`
   sebelum halaman mana pun boleh dibuka (diuji: halaman terproteksi
   mengembalikan **302** ke `dua_faktor.php` sebelum OTP).
3. Kode cadangan berformat `XXXXXX-XXXX` (6 heksadesimal, tanda hubung, 4
   heksadesimal = 11 karakter). Satu kode = satu kali pakai; pemakaian ulang
   ditolak.
4. Sukses/gagal OTP dicatat pada `audit_log` — satu entri `login_2fa_gagal`
   per kegagalan, satu `login_2fa_berhasil` per sukses, dan **tidak** ada
   entri gagal yang tercatat saat login berhasil.
5. Menonaktifkan 2FA (`2fa_matikan`, butuh kata sandi) mengembalikan
   `aktif=0` dan menghapus seluruh kode cadangan.

---

## 2. Perlindungan sesi & permintaan

* **Token CSRF per-aksi.** Setiap formulir memanggil
  `csrf_field('nama_aksi')` dan handler memanggil
  `csrf_cek_atau_matikan('nama_aksi')` (fungsi ini `void` — panggil
  apa adanya, **jangan** dibungkus `if`).
* **Endpoint AJAX JSON** (`api_chat.php`, `api_validasi.php`) memakai token
  aksi `global` dari `<meta name="csrf-token">` yang dikirim lewat header
  `X-CSRF-Token`. Keduanya: wajib `POST`, wajib login (**401** bila tidak),
  tanpa token → **403**, metode lain → **405**, dan dibatasi laju per sesi
  (**429**).
* **Pola PRG.** Semua aksi tulis diakhiri `flash_set()` + `header('Location: …')` +
  `exit`, sehingga muat ulang tidak mengulang aksi.
* **Bootstrap terpusat.** `includes/bootstrap.php` adalah satu-satunya titik
  masuk — tidak ada halaman yang memanggil `session_start()` sendiri, sehingga
  kebijakan sesi tidak bisa dilewati.

---

## 3. Kendali akses

| Lapisan | Fungsi | Catatan |
|---|---|---|
| `wajib_login()` | wajib sesi login | dipanggil setiap halaman |
| `wajib_role([...])` | batasan peran | memakai koneksi DB eksplisit sebagai argumen ke-3 |
| Penjaga IDOR | kepemilikan baris | mis. `edit_revisi` milik pengguna lain → **404**, bukan 403, agar tidak membocorkan keberadaan ID |
| `menu_tersedia()` | menu sesuai peran | halaman juga tetap dijaga di server |

Halaman admin (`backup.php`, `riwayat_audit.php`, `pengingat.php`) mengembalikan
**403** untuk peran non-admin, dan aksi `distribusi_setujui`/`distribusi_tolak`
menolak peran non-admin **sebelum** pemeriksaan CSRF sehingga tidak ada
perubahan data.

---

## 4. Unggahan & berkas

* `uploads/.htaccess` menolak eksekusi PHP (`FilesMatch` yang memakai
  `Require all denied` **hanya boleh ada di sini** — menaruhnya di root
  `.htaccess` mematikan seluruh situs, `AH01630`).
* `backups/.htaccess` menolak seluruh akses langsung ke berkas cadangan;
  unduhan hanya lewat `backup.php?aksi=unduh&id=N` (admin).
* Cadangan disimpan di luar `public/` dan dinamai dengan stempel waktu.

---

## 5. Akun database berhak minimal

| Akun | Hak akses | Pengguna |
|---|---|---|
| `pelaporan_app` | `ALL` **hanya** pada `pelaporan.*` | aplikasi PHP + `mysqldump`/`mysql` |
| `bi_reader` | `SELECT` **hanya** pada view BI | Power BI |
| `root` | — | **tidak dipakai aplikasi** |

`pelaporan_app` tidak memiliki `FILE`, `SUPER`, `GRANT OPTION`, maupun akses
lintas basis data. Rincian: `database/migrations/011_hak_akses_app.sql`.

---

## 6. Cadangan & pemulihan

Cadangan dibuat dengan `mysqldump` lalu **diproses ulang** untuk menghapus
`DEFINER=`. Tanpa langkah ini, pemulihan oleh akun non-`SUPER` akan gagal pada
view pertama (`ERROR 1227`) dan — karena `mysql` berhenti pada kesalahan pertama
— **menghapus view yang sudah ada** tanpa sempat membuatnya kembali.

Setelah pemulihan berhasil, katalog `backup_riwayat` di-sinkron ulang dari
isi berkas (katalog adalah metadata operasional, bukan data bisnis). Kegagalan
pemulihan juga men-sinkronkan ulang katalog agar katalog tetap cocok dengan
isi disk.

Pemulihan memerlukan konfirmasi eksplisit `konfirmasi_pulihkan=PULIHKAN`.

---

## 7. Error & informasi

* Lingkungan produksi menampilkan halaman error generik, bukan stack trace.
* Galat query tidak pernah dibocorkan ke tampilan; dicatat lewat `error_log()`.
* Verifikasi: seluruh halaman diuji untuk 3 peran dan tidak ada
  `Fatal error`, `Parse error`, `Warning`, `Notice`, `Deprecated`,
  `Undefined variable`, `mysqli_sql_exception` pada HTML.

---

## 8. Lapisan AI

* Seluruh endpoint layanan AI (kecuali `GET /health`) memerlukan header
  `X-Service-Key` yang sama dengan `AI_SERVICE_KEY` di `includes/config.php`.
  Kunci salah/kosong → **401**; seluruh rantai model tidak siap → **503** (atau fallback
  deterministik bila `AI_DEGRADE_DETERMINISTIC=true`).
* Pengiriman surel/WhatsApp dari AI **hanya berupa usulan** (`approve` +
  konfirmasi), bukan tindakan nyata.
* Pemulihan basis data dari AI memerlukan persetujuan teks `"PULIHKAN"`.
