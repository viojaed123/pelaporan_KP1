9. Peningkatan keamanan, akun & pemantauan (Oktober 2026)

- [x] Migrasi `013_peningkatan_akun.sql` terpakai: menambah `users.telepon`, `users.status`, `users.email_verified_at`, indeks hilang (`laporan_harian` status/tanggal, `kasus` status/urgensi), tabel `user_tokens` (hash SHA-256, sekali pakai, kedaluwarsa).
- [x] Pemisahan rahasia: `config_lokal.php` (lokal, .gitignore) diutamakan; `config.php` memakai pola `cfg()` dan nilai bawaan aman. Contoh `config_lokal.example.php`. `.htaccess` melindungi `includes/`.
- [x] Akun diperbarui: pendaftaran baru status `menunggu`, kirim tautan verifikasi surel (`verifikasi_email.php`). Login memblokir akun `menunggu`/`nonaktif`. Profil admin dapat mengubah status & memverifikasi manual.
- [x] Lupa/reset kata sandi: `lupa_sandi.php`, `reset_sandi.php`, token reset sekali pakai (1 jam), pesan umum anti-enumerasi, sesi lama dicabut.
- [x] Pemberitahuan status laporan: `includes/pemberitahuan.php` mengirim notifikasi in-app + surel + WhatsApp (bila nomor ada) saat mentor menetapkan `diperiksa`/`perlu_revisi`.
- [x] Template surel diperbarui ke palet Pertamina (#003D7D/#005EB8), bug header "hex sebagai teks" diperbaiki.
- [x] Halaman pemantauan: `status.php` (khusus admin) — ringkasan PHP/DB/penyimpanan/AI/surel, output JSON (`?format=json`), ditambah ke menu Administrasi.
- [x] `.gitignore` ditambahkan; berkas junk `0` dihapus.
- [x] PHPUnit dikonfigurasi (`phpunit.xml`, `tests/`) + composer.json dilengkapi.
- [x] Public pages baru: `verifikasi_email.php`, `lupa_sandi.php`, `reset_sandi.php` lulus lint & render (HTTP 200). `status.php` memerlukan login (HTTP 302).
- [x] Login e2e: admin login berhasil → mentor_dashboard.php; `status.php` & manajemen pengguna di profil dapat diakses setelah login.
- [x] Seluruh 71 berkas PHP lulus `php -l`; PHPUnit ter-install dan **35 uji, 113 asersi lulus**.

## 10. Diskusi laporan, ekspor & pemeliharaan (Oktober 2026)

- [x] Migrasi `014_komentar_laporan.sql` terpakai: tabel `komentar_laporan` (FK laporan & pengguna, ON DELETE CASCADE, indeks gabungan).
- [x] Fitur diskusi: `includes/komentar.php` (`komentar_ambil`, `komentar_boleh`, `komentar_tampil`) + `komentar_proses.php`. Muncul di `detail_laporan.php` (karyawan) dan `mentor_detail.php` (mentor/admin).
- [x] Hak akses komentar: pemilik laporan, mentor, dan admin. Komentar memberi notifikasi otomatis ke pihak lain (karyawan ↔ mentor).
- [x] E2E komentar (admin → laporan #9): tersimpan di DB, notifikasi ke pemilik dibuat, tercatat di audit, tampil kembali di halaman (uji dibersihkan setelahnya).
- [x] CSS diskusi ditambahkan ke `assets/css/app.css` (tanpa BOM, UTF-8 bersih).
- [x] Ekspor CSV jejak audit: `riwayat_audit.php?ekspor=csv` (mengikuti penyaring aktif, maks 50.000 baris, BOM Excel).
- [x] Ekspor CSV daftar pengguna: `profil.php?ekspor=pengguna` (khusus admin).
- [x] Perbaikan urutan kebijakan kata sandi: daftar kata sandi umum & satu karakter berulang diperiksa sebelum aturan keragaman sehingga kedua cabang tercapai dan pesannya lebih akurat.
- [x] Skrip operasional: `tools/pemeliharaan.php` (bersih token basi, pangkas audit/notifikasi lama, ringkasan tabel) dan `tools/backup_db.php` + `tools/backup_db.bat` (mysqldump + rotasi, kredensial via `MYSQL_PWD`). Direktori `backups/` terlindungi `.htaccess` (HTTP 403).
- [x] README diperluas: bagian pemeliharaan/ops (Task Scheduler/cron, cadangan, layanan AI, tabel pemecahan masalah).
- [x] Unit + integrasi test diperluas: `HelpersTest`, `SecurityTest`, `StrukturTest`, `IntegrasiTest` (token sekali pakai, indeks, struktur, hak komentar) — **35 uji, 113 asersi lulus**.

## 11. Absensi (kegiatan & kesehatan), aksi massal & foto profil (Oktober 2026)

- [x] Migrasi `015_absensi_kegiatan_kesehatan.sql` terpakai: menambah `absensi.kegiatan_hari_ini` (TEXT) dan `absensi.kondisi_kesehatan` (ENUM: sehat/kurang_sehat/sakit/pemulihan, default `sehat`).
- [x] Absensi karyawan (`absensi.php`): formulir kini memuat "Kegiatan hari ini" dan "Kondisi kesehatan"; aksi baru `ubah_kegiatan` untuk memperbarui tanpa mengubah jam. Riwayat menampilkan kolom Kesehatan & Kegiatan.
- [x] Helper baru: `daftar_kondisi_kesehatan`, `label_kondisi_kesehatan`, `badge_kondisi_kesehatan`, `kondisi_kesehatan_valid` (validasi nilai enum + default aman).
- [x] Pemantauan mentor (`mentor_absensi.php`): kolom Kesehatan & Kegiatan + statistik "Perlu Perhatian" (kondisi kurang_sehat/sakit).
- [x] Rekap (`rekap_absensi.php`): kolom baru pada tabel, kartu "Ringkasan Kondisi Kesehatan", serta kolom tambahan pada ekspor CSV & XLSX.
- [x] Perbaikan bug laten: `mentor_absensi.php` memakai `status` tanpa kualifikasi pada query ringkasan yang JOIN ke `users`; sejak migrasi 013 (`users.status`) kolom menjadi ambigu dan `prepare()` gagal (HTTP 500). Kini dikualifikasi `a.status`.
- [x] E2E absensi (akun uji sementara): absen masuk menyimpan kegiatan+kondisi; `ubah_kegiatan` memperbarui + tercatat audit; absen pulang tetap berfungsi; kolom baru tampil di halaman karyawan. Data uji dihapus.
- [x] Aksi massal laporan: `includes/keputusan_laporan.php` (`laporan_putuskan`) dipakai bersama oleh `mentor_proses.php` (tunggal) dan `mentor_bulk.php` (massal: periksa / minta revisi). Audit + notifikasi tetap per laporan; revisi wajib beri catatan; laporan yang statusnya sudah berubah dilewati dan dilaporkan.
- [x] `mentor_dashboard.php`: checkbox per baris (hanya status `dikirim`), "pilih semua", pilih keputusan, catatan massal, dan penghitung terpilih (vanilla JS). CSS `.baris-massal` ditambahkan ke `assets/css/app.css`.
- [x] E2E massal (admin): 2 laporan -> `diperiksa` (notifikasi + audit dibuat). Revisi tanpa catatan ditolak; dengan catatan diterapkan; campuran valid+tidak ada -> "1 berhasil, 1 dilewati". Alur tunggal `mentor_proses.php` tetap berfungsi setelah refactor.
- [x] Migrasi `016_foto_profil.sql` terpakai: menambah `users.foto_profil` (VARCHAR 255, NULL = pakai avatar inisial).
- [x] Foto profil opsional (`profil.php`): unggah JPG/PNG/WEBP maks 2 MB, MIME dideteksi dari isi (`getimagesize`), nama berkas acak, berkas lama dihapus, tombol Hapus Foto. Hanya nama berkas disimpan (anti path traversal).
- [x] Helper avatar: `sesi_foto`, `user_foto`, `foto_profil_url`, `avatar_tampil` (dipakai topbar `layout.php` dan kartu profil). `uploads/avatar/.htaccess` melarang eksekusi skrip di folder unggahan.
- [x] E2E foto profil (akun uji sementara): unggah -> DB tersimpan, berkas ada (HTTP 200 bila diakses langsung), avatar tampil sebagai `img`; hapus -> DB NULL + berkas terhapus. Data & berkas uji dibersihkan.
- [x] PHPUnit diperluas (kondisi kesehatan, pesan galat unggah, keamanan `foto_profil_url`, fallback `avatar_tampil`, kolom absensi & foto profil, validasi `laporan_putuskan`) - 45 uji, 159 asersi lulus. 73 berkas PHP lulus `php -l`.

---

## 12. Impor Excel, jadwal, ekspor Power BI & alat AI baru (Oktober 2026)

### Impor laporan Excel/CSV (`impor_laporan.php`, mentor/admin)
- [x] Migrasi `017_impor_laporan.sql` terpakai: tabel `impor_profil` (profil pemetaan, unik per user+jenis+nama) dan `impor_sejarah` (riwayat tiap impor).
- [x] Mesin `includes/impor_excel.php`: baca CSV/TXT (`fgetcsv`, deteksi pemisah otomatis) dan XLSX/XLS (PhpSpreadsheet), pembersihan header, tebak pemetaan otomatis, normalisasi tanggal (termasuk serial Excel), jam, status absensi, dan kondisi kesehatan, pencocokan pengguna via `email`/`nama`/`id`, validasi baris, dan commit transaksional.
- [x] Keamanan berkas: berkas sementara di `backups/impor/` (diblokir HTTP 403), nama token `^[a-f0-9]{32}$`, ekstensi diizinkan (`xlsx,xls,csv,txt`), maks 8 MB, janitor menghapus berkas > 1 hari.
- [x] E2E impor laporan (admin, CSV `;`): unggah (302 -> `langkah=pemetaan`), pratinjau (1 sah, 1 gagal "tidak ditemukan"), simpan -> baris masuk dengan `status=diperiksa` dan deskripsi diisi dari kegiatan; riwayat `impor_sejarah` tercatat. Data uji dihapus.
- [x] E2E impor absensi (admin): status/kondisi/jam tersimpan; mode duplikat `perbarui` berfungsi. Data uji dihapus.
- [x] **Perbaikan bug**: jumlah gagal pada `impor_sejarah` dihitung ganda (`impor_commit` sudah menghitung baris tidak sah, lalu `impor_laporan.php` menambahkannya lagi). Kini hanya memakai hitungan commit -> 2 baris / 1 masuk / 1 gagal (sebelumnya 2 gagal).

### Jadwal & pengingat (`jadwal.php`, semua peran)
- [x] Migrasi `018_jadwal.sql` terpakai: tabel `jadwal` (judul, mulai, selesai, lokasi, `pengingat_menit`, `pengingat_terkirim`, status `rencana/selesai/batal`).
- [x] `includes/jadwal.php`: validasi murni, CRUD, `jadwal_mendatang`, dan mesin pengingat (`jadwal_pengingat_jatuh_tempo`, `jadwal_tandai_pengingat`, `jadwal_kirim_pengingat`, `jadwal_jalankan_pengingat`).
- [x] Halaman `jadwal.php`: kalender bulanan (memakai gaya `kalender-*` yang ada) + tambah/ubah/hapus/tandai selesai, navigasi bulan, dan daftar agenda mendatang.
- [x] E2E jadwal (admin): simpan -> baris DB benar (`mulai`/`selesai`/`pengingat_menit`/`status`), tampil di kalender bulan ybs, hapus -> baris hilang.
- [x] `scripts/pengingat_jadwal.php`: CLI (`--kering`, `--maks`) untuk pengingat otomatis (notifikasi aplikasi + surel + WhatsApp bila terisi); idempoten via `pengingat_terkirim`.

### Ekspor Power BI (`powerbi_ekspor.php`, admin) & view
- [x] `powerbi/sql/02_views.sql` diperbarui: `vw_absensi` (+kegiatan/kondisi), `vw_kehadiran_bulanan` (+hitungan kesehatan), dan view baru `vw_jadwal`, `vw_komentar`, `vw_pengingat`, `vw_kepatuhan_harian` — total **15 view** + GRANT untuk dua host. Diterapkan sebagai `root`; diverifikasi dapat dibaca `bi_reader`.
- [x] `powerbi_ekspor.php`: unduh CSV per view (GET), workbook `.xlsx` multi-lembar, arsip `.zip` berisi CSV, dan `.pbids`; whitelist nama view; CSRF per-aksi `bi_ekspor`.
- [x] E2E ekspor: CSV 200 berisi header+data; `.xlsx`/`.zip` 200 dengan magic `PK`; `.pbids` memuat `mysql`; tanpa dataset -> 302 (flash "pilih minimal satu"); nama view liar (`users`) -> 404 (aman).
- [x] Migrasi `019_akses_baca_ai.sql` terpakai: memberi `SELECT` pada tabel inti yang dibaca layanan AI (`users`, `laporan_harian`, `absensi`, `kasus`, `kasus_pihak`, `jadwal`) agar jalur baca-saja `bi_reader` tidak jatuh ke akun aplikasi.

### Alat AI baru (agen di `asisten.php`)
- [x] `ai-service/app/tools/search.py` (`web_search`) dan `app/tools/jadwal.py` (`buat_jadwal`, `daftar_jadwal`, `ubah_jadwal`, `hapus_jadwal`, `ingatkan_jadwal`); registri kini **21 alat**.
- [x] Operasi tulis *approval-gated*: tanpa `konfirmasi` hanya pratinjau (`status: menunggu_persetujuan`); alat jadwal hanya menyentuh agenda milik pemanggil.
- [x] E2E langsung (DB nyata, akun admin): pratinjau -> simpan (id baru) -> daftar (baris tampil) -> tolak akses lintas pengguna (`Jadwal #… bukan milik Anda`) -> ubah -> hapus. Data uji dihapus.
- [x] **Perbaikan bug**: `daftar_jadwal` menyaring `mulai <= tanggal_sampai`; masukan tanggal polos sebelumnya menjadi `08:00:00` sehingga agenda siang hari terlewat. Ditambahkan `_batas_waktu` (batas awal `00:00:00`, batas akhir `23:59:59` untuk tanggal polos).
- [x] **Ketahanan**: `web_search` memakai `_klien()` terpusat; `AI_HTTP_CA_BUNDLE` (opsional) memakai CA kustom untuk TLS di balik proxy korporat. Bila SearXNG/DuckDuckGo tak terjangkau, alat mengembalikan `ok:false` dengan pesan jelas (tanpa melempar).
- [x] Dokumentasi skill AI: `skills/pencarian/SKILL.md`, `skills/jadwal/SKILL.md`, dan pembaruan `skills/laporan-harian/SKILL.md` (skema terkini + tabel alat).
- [x] Prompt sistem/domain diperbarui (aturan tulis+persetujuan, pencarian web, agenda); `.env.example` diperluas; `asisten.php` menampilkan kartu kemampuan & saran baru.

### Pengujian otomatis
- [x] PHPUnit: **75 uji, 304 asersi** lulus (bertambah `tests/ImporJadwalTest.php` untuk fungsi murni impor & jadwal, plus `StrukturTest` mencakup berkas baru).
- [x] pytest `ai-service`: **62 uji** lulus (termasuk penguraian SearXNG/DuckDuckGo, batas tanggal `daftar_jadwal`, dan frontmatter skill baru).
- [x] Seluruh berkas PHP lulus `php -l`; semua akun & data uji dikembalikan/dibersihkan (sandi admin dikembalikan ke hash semula).

---

## 13. Penyederhanaan akun, OTP lupa sandi & data sosial SRM/CID (Oktober 2026)

### Akun & kata sandi
- [x] Migrasi `020_otp_jenis.sql` terpakai: `user_otp.jenis` (`login`/`reset_sandi`) + indeks `idx_otp_jenis`; kode OTP dapat dipisah per keperluan.
- [x] Kebijakan kata sandi disederhanakan: **minimal 8 karakter**, tanpa aturan "3 dari 4 jenis karakter" (tetap menolak kata sandi umum, terlalu panjang, berulang, dan yang memuat email/nama pengguna).
- [x] Pendaftaran mandiri kini **langsung aktif** (`users.status='aktif'`, `email_verified_at=NOW()`); blokir verifikasi surel dihapus dari `register.php`. Gerbang login tetap memakai `users.status`.
- [x] `lupa_sandi.php` ditulis ulang menjadi alur **kode OTP** 3 langkah (isi surel -> masukkan kode -> kata sandi baru), dengan kirim ulang, ganti surel, pembatasan laju (`reset-otp`, maks 5/jam), pencabutan seluruh sesi, dan penandaan OTP terpakai. Tautan lama di `login.php` diganti "Lupa kata sandi? Gunakan kode OTP".
- [x] `scripts\buat_admin.php` (CLI): membuat atau menyetel ulang akun admin (`--email`, `--nama`, `--sandi`; permintaan sandi tersembunyi di Windows). Dipakai untuk memulihkan akses admin di lingkungan ini.
- [x] E2E (HTTP, akun uji sementara): daftar -> `aktif`/`karyawan`, redirect `login.php`; lupa sandi langkah 1 -> formulir OTP + baris `user_otp` (`jenis=reset_sandi`); langkah 2 -> formulir kata sandi; langkah 3 -> sukses; login dengan kata sandi baru -> `dashboard.php`. Akun & jejak uji dibersihkan.

### Data sosial SRM & CID
- [x] Migrasi `021_srm_cid.sql`: tabel `srm_kegiatan` (~21 kolom) & `cid_program` (~34 kolom) beserta indeks. Migrasi `022_cid_bigint.sql` melebarkan kolom hitungan ke `BIGINT`.
- [x] `includes/srm_cid.php`: fungsi murni (`srm_cid_bersih`, `srm_cid_angka` format Indonesia, `srm_cid_tanggal` serial Excel + teks, `cid_bulan_angka`, `srm_cid_norm_header`, peta kolom, deteksi lembar & baris header) dan mesin impor (`srm_cid_impor_srm`/`_cid`/`srm_cid_impor`, ganti-semua per jenis dalam transaksi).
- [x] **Perbaikan bug**: sel berisi **rumus** (mis. `=R2+S2+T2+U2+V2`, `=AJ7+5000000`, `=AE85-2`) sebelumnya terbaca sebagai teks lalu salah diubah menjadi angka raksasa (terpotong pada INT maksimum). Kini `srm_cid_nilai_sel` membaca nilai cache rumus (`getOldCalculatedValue`), dan `total_penerima_manfaat` dihitung dari komponennya bila cache tak ada. Setelah perbaikan: penerima 0..78 (total 2.308), anggaran 0..333 jt (total 12,92 M).
- [x] E2E impor (CLI & halaman): `Dummy Data (1).xlsx` -> SRM **100** baris, CID **205** baris, 0 gagal; header terdeteksi pada baris 1.
- [x] Halaman baca `srm.php` & `cid.php` (semua peran): kartu ringkasan, penyaring (tahun/area/pilar/status/kabupaten/pencarian), tabel berhalaman 25 baris, penanda status SRM, dan format rupiah/angka Indonesia.
- [x] Halaman admin `impor_srm_cid.php` (unggah Excel, pilih jenis, ringkasan jumlah, keterangan lembar) dan skrip `scripts\impor_srm_cid.php`. Menu "Data Sosial" (SRM/CID) untuk semua peran + "Impor SRM & CID" pada Administrasi.

### Power BI
- [x] `powerbi/sql/02_views.sql` diperluas menjadi **21 view**: `vw_srm`, `vw_cid` (dengan `persen_selesai` ternormalisasi 0..100), dan agregat `vw_srm_per_area`, `vw_srm_per_stakeholder`, `vw_cid_per_pilar`, `vw_cid_per_wilayah` + GRANT dua host. Diterapkan sebagai `root`; diverifikasi dapat dibaca `bi_reader`.
- [x] `powerbi_ekspor.php`: enam dataset baru terdaftar dengan kategori **Data Sosial**; `powerbi/README.md` diperbarui (jumlah view, relasi `vw_srm[tanggal] -> vw_dim_tanggal`, contoh DAX SRM/CID, halaman "Data Sosial", palet warna korporat yang benar).

### Pengujian otomatis
- [x] PHPUnit: **87 uji, 357 asersi** lulus (bertambah `tests/SrmCidTest.php` — 12 uji; `SecurityTest` disesuaikan dengan kebijakan sandi baru).
- [x] Seluruh berkas PHP baru/berubah lulus `php -l`; semua akun & data uji dikembalikan/dibersihkan.

---

## 14. Pemetaan lokasi, peta gabungan & Power BI lewat ODBC (Oktober 2026)

### Basis data & geokode
- [x] Migrasi `023_wilayah_lokasi.sql` terpakai: tabel `wilayah` (master kabupaten/kecamatan/desa + koordinat) dan `wilayah_alias`; kolom lokasi (`lokasi_teks`, `garis_lintang`, `garis_bujur`, `wilayah_id`) pada `laporan_harian` & `absensi`, serta (`garis_lintang`, `garis_bujur`, `wilayah_id`) pada `jadwal`, `srm_kegiatan`, `cid_program`.
- [x] `includes/wilayah.php`: normalisasi nama, pemetaan URL Google Maps (`wilayah_peta_url`, `wilayah_titik_url`), validasi koordinat, geokode Nominatim (1 permintaan/detik + User-Agent), penyimpanan & alias, pencarian wilayah, pencarian dari **teks alamat bebas** (`wilayah_cari_teks`), dan resolusi massal tabel.
- [x] `scripts/geokode_wilayah.php`: mengisi koordinat master wilayah. Hasil: kabupaten 7/8 (1 alias "Rokin Hilir"), kecamatan 54/64, desa 126/161; total baris `wilayah` = 8 + 64 + 161; 10 alias bawaan (mis. `rontau kopar` -> Rantau Kopar).
- [x] `scripts/resolusi_lokasi.php`: memetakan SRM **100/100 (100%)** dan CID **202/205 (98,5%)**; 3 sisa = baris tanpa lokasi sama sekali. Koordinat dalam rentang Riau (lat 0,32..2,55; lng 100,24..102,25).

### Peta & formulir
- [x] `peta.php` (semua peran): peta Leaflet gabungan seluruh data (kasus, SRM, CID, laporan, absensi, jadwal) dengan kartu ringkasan, tombol lapisan, penyaring tahun/kabupaten/pencarian, popup + tautan Google Maps & Street View. Menu "Peta Lokasi" ditambahkan ke `includes/layout.php` untuk ketiga peran.
- [x] `buat_laporan.php` & `absensi.php`: kolom lokasi (teks + GPS "Ambil lokasi saya" + klik peta mini + tautan Cek Lokasi); teks alamat otomatis dicocokkan ke master wilayah. `absensi.php` juga menampilkan kolom Lokasi pada riwayat.
- [x] Tautan **Cek Lokasi** ditambahkan pada `srm.php`, `cid.php`, `dashboard_kasus.php`, `jadwal.php`, dan riwayat `absensi.php`. Gaya `.tautan-lokasi` ditambahkan ke `assets/css/app.css` (global).
- [x] `includes/jadwal.php`: `jadwal_simpan` kini menyelesaikan koordinat dari teks `lokasi` secara otomatis, sehingga agenda tampil di peta.
- [x] `includes/impor_excel.php`: kolom **Lokasi** baru pada impor laporan & absensi; koordinat diselesaikan saat commit.
- [x] **Perbaikan bug**: string tipe `bind_param` pada INSERT impor laporan & absensi memiliki satu tipe lebih banyak daripada jumlah variabel (`issssssssssssss`/`issssssssssss`), sehingga impor gagal dengan *"number of elements in the type definition string must match the number of bind variables"*. Dikoreksi menjadi 14 & 12 tipe. E2E impor (laporan + absensi, kolom Lokasi) kini menyimpan koordinat dengan benar; data uji dibersihkan.
- [x] E2E (akun uji sementara): absensi dengan teks "Desa Buluh Manis, Bathin Solapan" -> tersimpan dengan koordinat (1,2787247; 101,0852849) dan `wilayah_id=80`. Semua akun/absensi/kata-sandi-riwayat uji dibersihkan.

### Power BI (ODBC)
- [x] `powerbi/sql/02_views.sql` kini **22 view**: `vw_wilayah` baru (master wilayah) + kolom lokasi (`lokasi_teks`, `garis_lintang`, `garis_bujur`, `wilayah_id`, `peta_url`) pada `vw_laporan_harian`, `vw_absensi`, `vw_jadwal`, `vw_srm`, `vw_cid`. Diterapkan sebagai `root`; `bi_reader` terverifikasi dapat membaca (187 / 100 / 202 baris berkoordinat).
- [x] Rute **ODBC**: `scripts/buat_dsn_powerbi.ps1` mendaftarkan *User DSN* `PelaporanBI` (driver `MySQL ODBC 26.7 Unicode Driver`, 64-bit). Diverifikasi end-to-end lewat `System.Data.Odbc` (187/100/202 baris).
- [x] `powerbi/pelaporan-odbc.pbids` (rute ODBC) + unduhan `.pbids` ODBC pada `powerbi_ekspor.php`; dataset `vw_wilayah` didaftarkan pada whitelist.
- [x] Halaman panduan `panduan_powerbi.php` (admin) + `powerbi/README.md` diperbarui (bagian ODBC, kolom lokasi, halaman "Peta Lokasi").

### Pengujian otomatis
- [x] `tests/WilayahTest.php` baru (7 uji: normalisasi, URL peta/titik, validasi koordinat, pembersihan nama) dan `ImporJadwalTest` ditambah pemeriksaan medan `lokasi_teks`. PHPUnit total **94 uji, 382 asersi** lulus.
- [x] Seluruh berkas PHP baru/berubah lulus `php -l`; halaman baru/terubah diuji HTTP 200 (peta, srm, cid, absensi, buat_laporan, panduan_powerbi, powerbi_ekspor).

---

## 15. Penyempurnaan UI/UX: konsolidasi CSS, halaman pembuka & dasbor (Oktober 2026)

### Fase 1 — Konsolidasi `assets/css/app.css`
- [x] Alias selaput-lapis (appended layer) digabung ke aturan asli: `.kartu-kepala`/`.kartu-header`, `.kartu-badan`/`.kartu-tubuh` (termasuk perbaikan bug `.padat { padding: 0; }`), serta `.stat`/`.kartu-stat` (dasar, hover, `::after`, `.ikon`, `.nilai`, `.keterangan`, `.tambahan`, varian `biru/hijau/merah/kuning/navy`).
- [x] `.form-grup` dijadikan alias `.grup-form` pada aturan asli (keduanya terpakai: 73 vs 10 kemunculan) sehingga label formulir kini konsisten.
- [x] Efek berharga disatukan ke definisi asli lalu blok selaput-lapis dibuang: angkat-tombol `.tombol:hover`, buram `.topbar`, bayangan `.badge`. `.sidebar` tetap memakai gradien asli (gradien selaput-lapis redundan dihapus).
- [x] Kode mati dihapus (0 kemunculan di seluruh PHP): `.timeline*`, `.pencarian`, `.sidebar-menu`, `.form-kontrol`, `.kartu-judul`, `.baris-antar`, `.baris-tengah`, `.galeri-kartu/.galeri-img/.galeri-aksi`. Yang dipertahankan: `.tabel`, `.empty`, `.galeri`, blok komentar, `baris-massal`, serta gaya `.peta-hero`/`.statistik-kartu` (dipakai `peta.php`).
- [x] Selektor ganda celaka `.stat .tambahan, .stat .tambahan,` diperbaiki.
- [x] Aksesibilitas baru: `:focus-visible` (cincin fokus biru + varian tombol/nav putih) dan blok `@media (prefers-reduced-motion: reduce)`.
- [x] Neraca CSS tetap sah (kurung buka/tutup sama banyak) setiap setelah suntingan.

### Fase 2 — Halaman pembuka `index.php`
- [x] Gaya sebaris dipindahkan dari `<style>` ke `assets/css/app.css` (bagian "Halaman pembuka").
- [x] Hero baru: `.logo-bingkai` (PNG logo PHR + efek melayang, pita gradien conic berputar, cincin), lencana judul, dan `.papan-plang` statistik.
- [x] Bagian baru: **Cara Kerja** (4 langkah bernomor otomatis), **Tanya Jawab** (5 `<details>` tanpa JS), **Kontak** (surel `viojaed123@gmail.com` dan telepon `085349351900` dari konstanta), serta footer berisi tautan bagian.
- [x] `includes/config.php` menambah `APP_KONTAK_EMAIL` & `APP_KONTAK_TELP`.
- [x] Warna korporat tidak berubah; `index.php` HTTP 200 dan seluruh penanda bagian (fitur/cara-kerja/faq/kontak) terverifikasi pada HTML.

### Fase 3 — Dasbor karyawan `dashboard.php`
- [x] 8 kartu statistik (ringkasan + absensi) dikonversi ke markup terpadu `.stat` + varian warna (`.stat navy/kuning/hijau/biru/merah`), inline `style="padding:18px"` dihapus (padding berasal dari aturan terpadu).
- [x] Bagian **Aksi Cepat** baru (`.aksi-cepat`): pintasan Buat Laporan, Riwayat, Absensi, Asisten AI.

### Fase 4 — Dasbor mentor `mentor_dashboard.php`
- [x] **Paginasi** 25 baris/halaman: query COUNT terpisah + `LIMIT ? OFFSET ?` (tautan `?hal=`, `halaman X dari Y`, tombol Sebelumnya/Berikutnya nonaktif di ujung). Nomor baris lanjut (`$nomorAwal`). Kolom badge kini menampilkan `$totalBaris` (seluruh baris cocok), bukan hanya baris halaman ini.
- [x] **Kepala tabel lengket** (`.tabel-lengket thead th { position: sticky }`).
- [x] **Tampilan kartu di layar kecil** (`@media max-width:720px`): `thead` disembunyikan, tiap baris jadi kartu, isi sel memakai `data-label`; sel centang kosong disembunyikan via `.sel-kosong`.
- [x] **Chip filter cepat** (`.chip-filter`): Semua / Menunggu / Diperiksa / Perlu Revisi (mempertahankan `q`). Form filter asli & aksi massal tetap ada.
- [x] SQL paginasi diuji terpisah (6 baris → 6 halaman, halaman 2 mengembalikan 1 baris benar).

### Fase 5 — Dasbor admin baru `admin_dashboard.php`
- [x] Berkas `admin_dashboard.php` baru (khusus `admin`): sambutan, peringatan verifikasi akun, 8 kartu `.stat` (pengguna, laporan per status, SRM/CID/wilayah, notifikasi), **Pintasan Administrasi**, dan tabel **Aktivitas Terbaru** dari `audit_log`.
- [x] `halaman_default_peran('admin')` kini `admin_dashboard.php` (semua pengalaman `index`/`login`/`dashboard` mengikuti otomatis).
- [x] Menu admin: "Dashboard Admin" -> `admin_dashboard.php`, entri baru "Periksa Laporan" -> `mentor_dashboard.php`.
- [x] `menu_aktif_otomatis()`: `admin_dashboard.php` -> `dashboard`; `mentor_dashboard.php` menyorot `periksa` khusus admin.

### Fase 6 — Sidebar: mode mini, grup terlipat, pencarian menu
- [x] `mulai_halaman()` mengelompokkan menu menjadi `$grupMenu`; tiap grup dibungkus `.nav-grup` (`data-grup`) dengan judul berupa `<button class="nav-judul" aria-expanded>`.
- [x] Mode mini (`--sidebar-w-mini: 78px`): tombol `#tombolMini` di `sidebar-brand`, label/judul/grup/pencarian/footer disembunyikan, ikon ditengahkan, preferensi disimpan di `localStorage` (`phr_sidebar_mini`). Dinonaktifkan otomatis di `@media (max-width:900px)` (sidebar off-canvas).
- [x] Grup dapat dilipat (rotasi chevron `::after`, status `aria-expanded`), status per grup disimpan di `localStorage` (`phr_sidebar_grup`).
- [x] Pencarian menu `#cariMenu` menyaring item per teks dan menampilkan grup yang cocok saja (keluar dari mode mini saat mengetik).
- [x] Skip-link `.lewati-ke-konten` ke `<main id="konten-utama">`, `aria-current="page"` + `title` pada item aktif, `aria-label` navigasi sidebar.
- [x] `assets/js/app.js` hanya ditambah IIFE terpisah (satu blok baru di akhir berkas) sehingga perilaku dropdown/modal/chat/notifikasi lama tidak tersentuh; `node --check` lulus.

### Fase 7 — Verifikasi
- [x] `php -l` lulus untuk `index.php`, `includes/config.php`, `includes/layout.php`, `dashboard.php`, `mentor_dashboard.php`, `admin_dashboard.php`.
- [x] PHPUnit: **94 uji, 382 asersi lulus** (tanpa regresi).
- [x] Uji HTTP semua halaman: 200 untuk halaman publik, 302 (ke login) untuk halaman terlindungi — tanpa 5xx/fatal.
- [x] Uji masuk admin: `admin_dashboard.php` 200 (25,8 KB), `mentor_dashboard.php` 200 (32,5 KB); `dashboard.php` & `index.php` 302 -> `admin_dashboard.php` (rute admin baru benar).
- [x] Uji masuk karyawan: `index.php` 302 -> `dashboard.php` (200), `mentor_dashboard.php` 403 (guard peran utuh).
- [x] Halaman pembuka segar (tanpa sesi) HTTP 200 dengan seluruh bagian baru; aset `app.css` (68,9 KB) & `app.js` (26,1 KB) HTTP 200.
- [x] Power BI/ODBC tak terpengaruh: DSN `PelaporanBI` (MySQL ODBC 26.7 Unicode Driver) ada; **22 view** utuh; `peta.php` 302/normal.

---

## 16. Unggah data terpadu, geokode otomatis & visualisasi peta (Oktober 2026)

### Fase 0 — Pemulihan akses admin
- [x] `admin_dashboard.php` dibuat ulang (berkas hilang -> login admin menabrak 404). Kini: sambutan, peringatan verifikasi akun, 8 kartu `.stat`, Pintasan Administrasi, dan tabel Aktivitas Terbaru dari `audit_log`. Admin masuk -> 302 `admin_dashboard.php` -> 200.

### Fase 1 — Geokode bercache & koordinat dari berkas
- [x] Migrasi `024_geokode_cache.sql` terpakai: tabel `geokode_cache` (`kueri`, `kueri_norm` unik, `garis_lintang`/`garis_bujur`, `tampilan`, `sumber`, `dibuat_pada`).
- [x] `includes/wilayah.php`: `wilayah_geokode_norm`, `wilayah_geokode_teks` (urutan **cache -> master wilayah -> Nominatim**), `wilayah_tabel_lokasi`, `wilayah_menunggu_geokode`, `wilayah_batch_koordinat` (maks 15 baris/permintaan, jeda 1 detik, di luar transaksi impor). Teks tunggal (mis. "Bengkalis") kini juga dicocokkan sebagai kabupaten/kecamatan/desa.
- [x] `includes/impor_excel.php`: medan **Garis Lintang**/**Garis Bujur** untuk laporan & absensi, `impor_norm_koordinat()` (terima koma desimal, tolak nilai bukan angka/0,0), prioritas koordinat **berkas -> master wilayah -> kosong**, dan `impor_commit()` mengembalikan `id_semua` (semua id tersentuh) untuk geokode pasca-impor.
- [x] Uji: cache master -> hit kedua dari cache -> kueri asing (tanpa jaringan) = null; `bengkalis` tersimpan `sumber=master`.

### Fase 2 — Unggah terbuka untuk semua peran
- [x] `impor_laporan.php` kini `wajib_login()` saja. **Karyawan** hanya melihat jenis laporan/absensi, kolom "Pengguna" disembunyikan, dan pemilik data **dipaksa** ke akun sendiri (`impor_validasi(..., ['paksa_user_id' => uid])`). **Mentor/admin** tetap dapat mengunggah data semua karyawan.
- [x] `impor_jenis_untuk_role()`: dasar (laporan, absensi) untuk semua; `srm_cid` hanya mentor/admin.
- [x] Menu **Unggah Data** (`impor`) ditambahkan untuk peran karyawan di `includes/layout.php`.
- [x] E2E (karyawan uji sementara): unggah CSV 3 baris -> pratinjau -> simpan -> 3 baris milik karyawan; opsi SRM/CID tidak muncul; catatan "milik akun Anda" tampil; `admin_dashboard.php` 403.

### Fase 3 — SRM & CID lewat pintu unggah yang sama
- [x] Jenis **SRM & CID (Excel)** pada halaman yang sama (mentor/admin). Tanpa pemetaan kolom: lembar terdeteksi + peringatan **ganti-semua** + tombol "Impor SRM & CID sekarang" (`aksi=simpan_srmcid` -> `srm_cid_impor`).
- [x] Koordinat SRM/CID diselesaikan lewat master wilayah; hasil disimpan di sesi `unggahan_terakhir`.
- [x] E2E (admin, fixture kecil 2+2 baris, tabel dicadangkan lalu dipulihkan): SRM 2 & CID 2 tersimpan, koordinat benar, redirect `peta.php?sorot=1`.

### Fase 4 — Sorotan data baru di peta
- [x] `peta.php`: sesi `unggahan_terakhir` (`sorot_kunci`, `sorot_sumber`) menandai titik: cincin **berdenyut** (`@keyframes denyut-sorot`), radius/warna lebih besar, lencana "Baru diunggah" pada popup, `fitBounds` ke titik sorot, banner ringkasan + tombol **Hapus sorotan** (`?hapus_sorot=1`).

### Fase 5 — Visualisasi di peta
- [x] Panel **Chart.js** pada `peta.php`: donat **titik per sumber**, batang **10 kabupaten/kota terbanyak** (SRM+CID), dan batang **laporan 12 bulan terakhir**. Data disuntik sebagai JSON (`#dataGrafik`), warna memakai palet korporat.

### Fase 6 — Verifikasi
- [x] `php -l` lulus untuk seluruh berkas baru/berubah.
- [x] PHPUnit: **103 uji, 423 asersi** lulus (+`tests/ImporGeokodeTest.php` 9 uji; `StrukturTest` mencakup migrasi 019–024).
- [x] E2E HTTP per peran (karyawan/mentor/admin) tanpa 5xx; data & akun uji dibersihkan; tabel SRM/CID dikembalikan 100/205.
- [x] **Perbaikan**: `impor_norm_koordinat` kini menolak nilai bukan angka (sebelumnya "abc" menjadi 0).

### Penyempurnaan tampilan (emoji -> ikon garis)
- [x] Helper `ikon_sp()` menambahkan ikon garis SVG (monokrom, `currentColor`) pengganti emoji berwarna. Diterapkan pada **seluruh halaman** — termasuk dasbor (`dashboard.php`, `mentor_dashboard.php`, `dashboard_kasus.php`, `admin_dashboard.php`), halaman masuk & autentikasi (`login.php`, `register.php`, `lupa_sandi.php`, `reset_sandi.php`, `verifikasi_email.php`, `dua_faktor.php`), kerja harian (`absensi.php`, `buat_laporan.php`, `asisten.php`, `notifikasi.php`, `profil.php`, `riwayat*.php`, `rekap_*.php`, `backup.php`, `status.php`, `index.php`, `peta.php`, `powerbi_ekspor.php`, serta tautan "Cek Lokasi" pada `srm.php`/`cid.php`/`jadwal.php`), menu sidebar `layout.php`, lonceng topbar, dropdown pengguna, dan panel chat.
- [x] Emoji berupa **entitas HTML numerik** (mis. `&#128205;`, `&#128273;`, `&#128065;`, `&#9989;`) ikut diganti; tombol mata sandi di `app.js` kini menukar SVG (mata/mata-silang) alih-alih teks emoji.
- [x] Simpanan data ikon (`$kemampuan` di `asisten.php`, `$ikonStatus` di `status.php`) kini menyimpan nama ikon dan memanggil `ikon_sp()` saat render.
- [x] Ukuran ikon diatur per kontainer lewat `assets/css/app.css` (`.ikon > .sp-ikon`, `.tombol`, `.cek`, `.tautan-lokasi`, `.mata`, judul `h3`/`h4`, dst.); warna korporat tidak berubah.
- [x] Bagian **Absensi Bulan Ini** pada dasbor karyawan menjadi **grid-3: Sakit / Izin / Alpha** (jumlah hadir dipindah ke keterangan; nilai enum basis data tetap `alpa`).
- [x] Judul bagian memakai `.bagian-judul`; gaya ikon/panel grafik/sorotan ditambahkan ke `assets/css/app.css`.
- [x] Simbol geometris `&#9776;` (menu) dan `&#9662;` (chevron) dipertahankan (bukan emoji).

---

## 17. Migrasi bertahap ke Laravel 12 + React SPA + APK Android (Oktober 2026)

Strategi *strangler-fig*: aplikasi lama di `localhost` tetap hidup; Laravel
dipasang berdampingan (`192.168.56.1` / `pelaporan.local`) tanpa
`migrate:fresh` (basis data produksi berisi 44 tabel + view lama).

### Fondasi (Fase 0-F)
- [x] Laravel 12.70.0 di PHP 8.2.12 (XAMPP), `phpoffice/phpspreadsheet ^5.10`, `laravel/sanctum ^4.3`.
- [x] Model Eloquent untuk seluruh tabel warisan + trait/peran (`role:mentor,admin` via `CekPeran`).
- [x] API Power BI 4 endpoint statis-token (`POWERBI_API_TOKEN`) di `/api/v1`.
- [x] Autentikasi Sanctum (token Bearer) untuk SPA & APK, memakai `personal_access_tokens`.

### Fase A - API mobile & autentikasi
- [x] `/api/v1/auth/login|verifikasi|logout|profil|ganti-password`; laporan, absensi, tugas, kasus, notifikasi, ringkasan.
- [x] Uji `ApiMobileTest` (peran, 401/403, penguncian, 2FA).

### Fase B - Kasus dengan PIC/anggota teks bebas
- [x] PIC & anggota DIKETIK BEBAS lalu dicocokkan otomatis ke pengguna (`App\Services\CocokPengguna`).
- [x] Formulir legacy `buat_kasus.php` & `edit_kasus.php` ikut dikonversi ke ketik bebas
      (isian teks PIC + textarea satu-nama-per-baris), memakai aturan pencocokan
      IDENTIK di `includes/kasus.php` (`kasus_cocok_pengguna`, `kasus_tim_teks_cocok`,
      `kasus_sinkron_tim_teks`) -- tanpa dropdown, tanpa memilih dari daftar.
- [x] Tampilan `detail_kasus.php` & `dashboard_kasus.php` memakai `COALESCE(nama pengguna, teks ketikan)`.
- [x] Migrasi `2026_10_19_100000` -- `kasus_penugasan.user_id` menjadi NULLable agar
      anggota non-pengguna benar-benar tersimpan (lihat kotak "Bug" di bawah).
- [x] Uji `KasusTeksTest` (PHPUnit) + uji E2E `scripts/uji_e2e_kasus_teks.php --yakin`
      (36 pemeriksaan: login -> buat -> cek DB -> detail/dashboard -> edit -> cek DB;
      akun & kasus uji dibersihkan otomatis).

### Fase C/D - React SPA + analitik
- [x] Cangkang SPA di `/app` (rute Blade lama dipertahankan agar uji tak rusak).
- [x] Halaman SPA: Dasbor, Buat Laporan, Riwayat, Absensi, Tugas, Kasus, Asisten AI, Notifikasi, Profil.
- [x] Analitik `AnalitikController`: rekap laporan & rekap absensi (mentor/admin), ringkasan kegiatan & kalender (semua peran, karyawan dipersempit ke dirinya).
- [x] Halaman React `RekapLaporan`, `RekapAbsensi`, `RingkasanKegiatan`, `KalenderAktivitas` + rute/menu berperan.
- [x] Blok CSS SPA dipetakan ke token korporat (#003D7D/#005EB8/#EB1C24/#159447, bg #F5F5F5).
- [x] Uji `SpaTest`, `ApiAnalitikTest`.

### Fase E - APK Android native
- [x] Proyek Kotlin/Compose di `mobile/` (package `com.phr.pelaporan`, compileSdk 36, minSdk 24).
- [x] `BASE_URL` dapat diubah di layar masuk (DataStore), default `http://10.0.2.2/`; `usesCleartextTraffic` untuk uji lokal.
- [x] Build sukses: `mobile/app/build/outputs/apk/debug/app-debug.apk`.

### Go-Live (lihat `docs/GO_LIVE.md`)
- [x] Perintah `pengingat:harian` (Artisan) + Laravel Scheduler; `pengguna:audit` (read-only).
- [x] `pengguna:hapus {id} --yakin [--paksakan]` (transaksi + catat `audit_log`):
      akun ganda #46/#47/#48 dihapus, #1 dipertahankan -> `pengguna:audit`
      "Nama karyawan duplikat: **0**" (basis data produksi, disertai cadangan
      `backups/pelaporan-20261019-062821.sql`).
- [x] `scripts/backup_db.ps1`/`.cmd` (mysqldump + rotasi) dan `scripts/jalankan_penjadwal.cmd`.
- [x] Template vhost HTTPS `scripts/vhost_https.conf.example`.
- [ ] **UAT (tanda tangan pemilik proses):** jalankan skenario harian karyawan di web `/app` **dan** APK; skenario mentor/admin (rekap, verifikasi, kasus PIC bebas).
- [ ] HTTPS, SMTP organisasi, cadangan terjadwal, dan pembersihan akun uji — lihat `docs/GO_LIVE.md`.

### Verifikasi
- [x] PHPUnit **146 uji / 496 asersi** lulus; `npm run build` sukses (Vite).
- [x] Tidak ada `migrate:fresh`; seluruh migrasi bersifat tambahan.
- [x] Uji E2E legacy `C:\xampp\php\php.exe scripts\uji_e2e_kasus_teks.php --yakin`
      terhadap `http://localhost/pelaporan`: **36 pemeriksaan lulus**
      (akun & kasus uji dibuat lalu dihapus otomatis, termasuk jejak auditnya).

> **Dua bug nyata yang ditangkap uji E2E di atas** (segera diperbaiki):
> 1. `kasus_perbarui()` mengikat `penanggung_jawab_teks` sebagai *integer*
>    (urutan tipe `bind_param` meleset satu), sehingga PIC yang diketik tersimpan "0".
> 2. `kasus_penugasan.user_id` masih NOT NULL di produksi walau desainnya
>    "user_id diisi bila cocok" -- INSERT anggota non-pengguna ditolak MySQL
>    (pelanggaran integritas) dan namanya hilang diam-diam. Uji PHPUnit tidak
>    melihatnya karena skema uji memakai `user_id` nullable.

