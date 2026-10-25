# Kerangka Rinci BAB III — Metode Penelitian

> Dokumen pendamping dari [`docs/KERANGKA_TA.md`](KERANGKA_TA.md).
> Semua tabel di dokumen ini **siap disalin ke Word** (Markdown table → Word
> dengan *Paste Special > Unformatted Text*, atau tempel apa adanya ke
> dokumen `.md` lalu konversi).
>
> Isi di sini diturunkan langsung dari kode proyek yang ada, sehingga dapat
> dipertanggungjawabkan saat Sidang.

---

## Daftar Isi Bab III

```
3.1  Jenis dan Lokasi Penelitian
3.2  Teknik Pengumpulan Data
3.3  Analisis Kebutuhan
     3.3.1  Kebutuhan Fungsional
     3.3.2  Kebutuhan Non-Fungsional
     3.3.3  Matriks Modul dan Peran
     3.3.4  Rincian Modul Inti dan Pemetaan Kode
3.4  Perancangan Sistem
     3.4.1  Diagram Use Case
     3.4.2  Diagram Activity
     3.4.3  Diagram Sequence
     3.4.4  Diagram Component/Deployment
     3.4.5  ERD
     3.4.6  Diagram Arsitektur Microservice
     3.4.7  Rancangan Antarmuka
3.5  Rancangan Antarmuka API
3.6  Rancangan Keamanan
     3.6.1  Matriks Hak Akses (RBAC)
     3.6.2  Autentikasi dan 2FA
     3.6.3  Jejak Audit
     3.6.4  Approval Gate untuk Tindakan AI Berisiko
3.7  Lingkungan Implementasi
3.8  Metode Pengujian
```

---

# 3.1 Jenis dan Lokasi Penelitian

| Aspek | Keterangan |
|---|---|
| Jenis penelitian | *Development Research* (penelitian pengembangan sistem) |
| Pendekatan | Kualitatif (analisis kebutuhan, wawancara, observasi) + kuantitatif (UAT, uji performa, uji akurasi AI) |
| Lokasi studi kasus | PT Pertamina Hulu Rokan (PHR), Region Blok Rokan, Riau |
| Lokasi pengembangan & uji | stasiun kerja lokal ‹deskripsi›: XAMPP (Apache/MariaDB/PHP), emulator Android (API 36), Ollama lokal |
| Periode penelitian | ‹bulan/tahun› |
| Objek yang diteliti | Sistem Pelaporan Kegiatan Harian PHR beserta 5 subsistemnya |

---

# 3.2 Teknik Pengumpulan Data

| No. | Teknik | Instrumen | Sumber Data | Data yang Didapat |
|---|---|---|---|---|
| 1 | Observasi | Field notes ‹nama› | Aktivitas lapangan & kerja mentor | Alur kerja pelaporan, titik bottleneck |
| 2 | Wawancara | Pedoman semi-terstruktur | ‹nama, jabatan› | Kebutuhan per peran, keluhan proses lama |
| 3 | Studi dokumen | SOP, form Excel, arsip rekap | ‹unit› | Proses *existing*, baseline waktu rekap |
| 4 | Audit kode & basis data | Pemeriksaan berkas PHP/JS/Python, skema SQL | Repositori & basis data | Gap arsitektur, keamanan, duplikasi |
| 5 | Observasi non-partisipatif | Rekaman layar (opsional) | ‹nama› | Alur antarmuka aktual |

**Tabel 3.1 — Rekapitulasi Teknik Pengumpulan Data**

---

# 3.3 Analisis Kebutuhan

## 3.3.1 Kebutuhan Fungsional

### Tabel 3.2 — Ringkasan Use Case

| ID | Nama Use Case | Pemangku | Aktor | Deskripsi Singkat | Prioritas |
|---|---|---|---|---|---|
| UC-01 | Membuat dan Mengirim Laporan Harian | Karyawan | Karyawan | Mengisi kegiatan harian, lampiran bukti, kirim untuk ditinjau | Tinggi |
| UC-02 | Absensi dan Kondisi Kesehatan | Karyawan | Karyawan | Check-in/check-out, mencatat kondisi kesehatan harian | Tinggi |
| UC-03 | Meninjau dan Menyetujui Laporan | Mentor | Mentor | Memeriksa laporan staf, setujui atau minta revisi | Tinggi |
| UC-04 | Mengelola Kasus dan Tim Penugasan | Mentor, Admin | Mentor, Admin | Buat kasus, tentukan PIC, tambah anggota tim, ubah status | Tinggi |
| UC-05 | Rekap Laporan | Mentor, Admin | Mentor, Admin | Rekap per periode, filter, ekspor `.xlsx`/`.docx`/`.pdf` | Tinggi |
| UC-06 | Rekap Absensi | Mentor, Admin | Mentor, Admin | Rekap kehadiran, durasi, keterlambatan | Tinggi |
| UC-07 | Impor Data | Semua | Karyawan/ Mentor/ Admin | Unggah Excel/CSV laporan & absensi; SRM–CID khusus mentor/admin | Sedang |
| UC-08 | Analisis Dataset dan Visualisasi | Mentor, Admin | Mentor, Admin | Unggah dataset, simpan profil kolom, visualisasi chart | Sedang |
| UC-09 | Bertanya-jawab dengan Asisten AI | Semua | Semua | Ngobrol dengan agen AI berbasis data internal | Tinggi |
| UC-10 | Mengelola Jadwal dan Pengingat | Semua | Semua | CRUD agenda, pengingat otomatis, integrasi ke agen AI | Sedang |
| UC-11 | Melihat Data Sosial (SRM/CID) dan Peta | Semua | Semua | Browsing SRM/CID, geokode, peta lokasi | Sedang |
| UC-12 | Mengelola Pengguna dan Peran | Admin | Admin | Tambah/ubah/hapus pengguna, ubah peran, reset kata sandi | Tinggi |
| UC-13 | Melihat Riwayat Perubahan (Audit) | Admin | Admin | Menelusuri siapa mengubah apa dan kapan | Tinggi |
| UC-14 | Cadangan dan Pemulihan Basis Data | Admin | Admin | Buat cadangan, jalankan skrip pemulihan, unduh riwayat | Sedang |
| UC-15 | Ekspor Dataset untuk Power BI | Admin | Admin | Siapkan data/view untuk lapisan analitik | Sedang |
| UC-16 | Memantau Status Sistem | Admin | Admin | Cek kesehatan layanan AI, antrean, basis data | Rendah |
| UC-17 | Mengelola Akun dan Keamanan | Semua | Semua | Ganti kata sandi, aktifkan 2FA, atur profil & foto | Tinggi |
| UC-18 | Notifikasi | Semua | Semua | Terima dan tandai notifikasi (approval laporan, tugas, jadwal) | Sedang |

**Ketertelusuran modul → use case.** Empat modul pada Tabel 3.4 sengaja tidak
menjadi use case tersendiri karena merupakan **varian atau penunjang** dari use
case yang sudah ada, bukan fungsi bisnis baru:

| Modul (Tabel 3.4) | Menempel pada |
|---|---|
| Analisis Sosial Terpadu (#34) | UC-11 — memperluas visualisasi SRM/CID menjadi 4 tab |
| Panduan Power BI (#35) | UC-15 — dokumentasi pemakaian lapisan analitik |
| Detail Laporan + Komentar (#36) | UC-01 / UC-03 — tahap baca dan revisi |
| Keputusan Massal & Tunggal (#37, #38) | UC-03 — dua jalur pemrosesan keputusan |
| Bukti Gambar (#39) | UC-01 — lampiran bukti kegiatan |

Dengan demikian jumlah use case tetap **18**, sementara jumlah modul yang
dijaga hak aksesnya menjadi **39**.

### Spesifikasi Use Case (format siap tempel ke Word)

> Gunakan blok di bawah ini untuk Lampiran atau Subbab 3.4.1. Setiap kasus memuat:
> *Aktor Utama*, *Aktor Pendukung*, *Prasyarat*, *Alur Utama*,
> *Alur Alternatif*, *Postkondisi*, *Aturan Bisnis*.

---

#### UC-01 — Membuat dan Mengirim Laporan Harian

| Komponen | Uraian |
|---|---|
| ID | UC-01 |
| Nama | Membuat dan Mengirim Laporan Harian |
| Aktor utama | Karyawan |
| Aktor pendukung | Mentor (periksa), Microservice AI (validasi kelengkapan) |
| Prasyarat | Pengguna sudah login dan berstatus peran `karyawan` |
| Alur utama | 1. Karyawan membuka menu **Buat Laporan**.<br>2. Mengisi tanggal, kegiatan, deskripsi, dan catatan kondisi.<br>3. Melampirkan bukti gambar (opsional).<br>4. Menyimpan sebagai **draft** atau langsung **mengirim**.<br>5. Status berubah menjadi *menunggu pemeriksaan* dan mentor menerima notifikasi. |
| Alur alternatif | a. Menyimpan sebagai draft lalu menyunting kembali.<br>b. Meminta bantuan AI memvalidasi kelengkapan sebelum mengirim.<br>c. Menolak pengiriman bila field wajib kosong — sistem menampilkan pesan galat. |
| Postkondisi | Data tersimpan pada `laporan_harian`; riwayat tercatat; notifikasi terkirim ke mentor. |
| Aturan bisnis | Laporan yang sudah dikirim hanya dapat diubah bila berstatus `draft` atau `perlu_revisi`. Perubahan oleh AI tercatat pada `audit_log`. |

---

#### UC-03 — Meninjau dan Menyetujui Laporan

| Komponen | Uraian |
|---|---|
| ID | UC-03 |
| Nama | Meninjau dan Menyetujui Laporan |
| Aktor utama | Mentor |
| Aktor pendukung | Admin, Microservice AI (ringkasan & validasi) |
| Prasyarat | Pengguna login dengan peran `mentor` atau `admin`; tersedia laporan berstatus *menunggu pemeriksaan* |
| Alur utama | 1. Mentor membuka **Periksa Laporan**.<br>2. Menyorot laporan (massal tersedia untuk beberapa karyawan).<br>3. Membaca rincian, komentar, dan ringkasan otomatis dari AI.<br>4. Memilih **Setujui** atau **Perlu Revisi** + catatan komentar.<br>5. Karyawan menerima notifikasi keputusan. |
| Alur alternatif | a. Meminta ringkasan mingguan/bulanan dari AI untuk memutuskan.<br>b. Menunda keputusan tanpa mengubah status. |
| Postkondisi | Status laporan berubah; komentar tersimpan; notifikasi terkirim; jejak audit tercatat. |
| Aturan bisnis | Hanya peran `mentor`/`admin`; persetujuan massal dibatasi agar tidak salah sasaran. |

---

#### UC-09 — Bertanya-jawab dengan Asisten AI

| Komponen | Uraian |
|---|---|
| ID | UC-09 |
| Nama | Bertanya-jawab dengan Asisten AI |
| Aktor utama | Semua pengguna yang sudah login |
| Aktor pendukung | Microservice AI (`ai-service`), Gemini/Ollama, basis data |
| Prasyarat | Sesi valid; layanan AI dapat dijangkau (`GET /health` mengembalikan 2xx) |
| Alur utama | 1. Pengguna menulis pertanyaan pada halaman **Asisten AI**.<br>2. PHP meneruskan pesan ke `ai-service` dengan header `X-Service-Key`.<br>3. `ai-service` meneruskan ke rantai model (Gemini → Ollama).<br>4. Model meminta pemanggilan alat; `ai-service` menjalankan alat dengan pembatas peran.<br>5. Jawaban beserta sumber dikembalikan ke antarmuka. |
| Alur alternatif | a. Gemini gagal → otomatis pindah ke Ollama.<br>b. Seluruh model mati → HTTP 503 dengan pesan jelas (atau hasil deterministik bila `AI_DEGRADE_DETERMINISTIC=true`).<br>c. Alat berisiko dipanggil → hanya menampilkan **usulan**; menunggu persetujuan pengguna. |
| Postkondisi | Percakapan tersimpan pada `chat_sessions`/`chat_messages`; setiap penggunaan alat tercatat pada `audit_log`. |
| Aturan bisnis | Percakapan dibatasi 20 permintaan/menit; data yang boleh diakses mengikuti peran; alat jadwal hanya menyentuh agenda pemanggil. |

---

> Lengkapi UC-02, UC-04, UC-05, UC-06, UC-07, UC-08, UC-10, UC-11, UC-12,
> UC-13, UC-14, UC-15, UC-16, UC-17, UC-18 dengan pola yang sama.

---

## 3.3.2 Kebutuhan Non-Fungsional

### Tabel 3.3 — Kebutuhan Non-Fungsional

| No. | Kategori | Indikator | Target | Cara Pengukuran | Sumber |
|---|---|---|---|---|---|
| NFR-01 | Performa | Waktu muat halaman dashboard | ≤ 2 detik | DevTools / Network | `dashboard.php`, SPA `Dashboard.jsx` |
| NFR-02 | Performa | Waktu respons endpoint API | ≤ 500 ms (p95) | Apache Bench / k6 | `routes/api.php` |
| NFR-03 | Performa | Waktu respons fungsi AI | ≤ 5 detik (Gemini) | Pengujian manual 30 skenario | `ai-service` |
| NFR-04 | Performa | Ukuran bundel SPA | ≤ 600 kB ter-compress | `npm run build` | `public/build/manifest.json` |
| NFR-05 | Keamanan | Autentikasi | 2FA/OTP wajib tersedia | Uji skenario login | `user_otp`, `backup_codes` |
| NFR-06 | Keamanan | Batas sesi | idle ≤ 30 menit; usia maks 8 jam | Uji `BatasSesi` | `app/Http/Middleware/BatasSesi.php` |
| NFR-07 | Keamanan | Jejak audit | 100% perubahan penting tercatat | Uji audit | `audit_log` |
| NFR-08 | Keamanan | Query basis data | 100% ber-parameter | Review kode | `ai-service/app/db.py`, PHP PDO |
| NFR-09 | Keamanan | Akses analitik | `bi_reader` hanya `SELECT` | Uji `SHOW GRANTS` | `powerbi/sql/02_views.sql` |
| NFR-10 | Skalabilitas | Pengguna aktif | 100 pengguna; 50 konkuren | Uji beban | — |
| NFR-11 | Usability | Responsif | Mendukung lebar ≥ 360 px | Uji perangkat | CSS + Compose |
| NFR-12 | Usability | Mode gelap | Tersedia di web | Uji visual | `assets/css/app.css` |
| NFR-13 | Kompatibilitas | Peramban | Chrome/Edge/Firefox terkini | Uji manual | — |
| NFR-14 | Operasional | Pemulihan data | Backup harian otomatis 01:30 | Uji skrip | `tools/backup_db.php` |
| NFR-15 | Operasional | Penjadwalan | `schedule:run` tiap menit | Uji Task Scheduler | `scripts/jalankan_penjadwal.cmd` |

---

## 3.3.3 Matriks Modul dan Peran

Diturunkan dari `menu_tersedia()` (`includes/layout.php`), `peta_akses()`
(`includes/auth.php`), dan pemeriksaan `wajib_role()` pada halaman yang tidak
tampil di sidebar (baris 34–39).

### Tabel 3.4 — Ketersediaan Modul per Peran

| No. | Modul / Halaman | Karyawan | Mentor | Admin | Mekanisme Penjaga |
|---|---|:---:|:---:|:---:|---|
| 1 | Dashboard | ✔ | ✔ | ✔ | Menu per peran (dasbor berbeda per peran) |
| 2 | Buat Laporan | ✔ | — | — | Menu per peran |
| 3 | Riwayat Laporan | ✔ | — | — | Menu per peran |
| 4 | Absensi (milik sendiri) | ✔ | — | — | Menu per peran |
| 5 | Unggah / Impor Data | ✔ (data sendiri) | ✔ (semua karyawan) | ✔ (semua + SRM/CID) | `impor_jenis_untuk_role()`; karyawan dipaksa `user_id` sendiri |
| 6 | Rekap Laporan | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` |
| 7 | Rekap Absensi | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` |
| 8 | Absensi Karyawan | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` |
| 9 | Periksa Laporan | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` |
| 10 | Pengiriman Laporan | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` |
| 11 | Ekspor (Excel/Word/PDF) | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` |
| 12 | Dataset (analisis & chart) | — | ✔ | ✔ | Menu per peran |
| 13 | SRM — lihat | ✔ | ✔ | ✔ | `peta_akses()['srm.lihat']` |
| 14 | SRM — impor | — | — | ✔ | `peta_akses()['srm.impor']` |
| 15 | SRM — hapus | — | — | ✔ | `peta_akses()['srm.hapus']` |
| 16 | CID — lihat | ✔ | ✔ | ✔ | `peta_akses()['cid.lihat']` |
| 17 | CID — impor | — | — | ✔ | `peta_akses()['cid.impor']` |
| 18 | CID — hapus | — | — | ✔ | `peta_akses()['cid.hapus']` |
| 19 | Peta Lokasi | ✔ | ✔ | ✔ | Menu per peran |
| 20 | Pelacakan Kasus | ✔ | ✔ | ✔ | Menu per peran |
| 21 | Notifikasi | ✔ | ✔ | ✔ | Menu per peran |
| 22 | Asisten AI | ✔ | ✔ | ✔ | Menu per peran |
| 23 | Jadwal & Pengingat | ✔ | ✔ | ✔ | Menu per peran |
| 24 | Ringkasan | ✔ | ✔ | ✔ | Menu per peran |
| 25 | Kalender Aktivitas | ✔ | ✔ | ✔ | Menu per peran |
| 26 | Akun & Keamanan | ✔ | ✔ | ✔ | Menu per peran |
| 27 | Kelola Pengguna | — | — | ✔ | `wajib_role(['admin'])` |
| 28 | Riwayat Perubahan (audit) | — | — | ✔ | `wajib_role(['admin'])` |
| 29 | Cadangan Data | — | — | ✔ | `wajib_role(['admin'])` |
| 30 | Impor SRM & CID | — | — | ✔ | `wajib_role(['admin'])` |
| 31 | Ekspor Dataset (Power BI) | — | — | ✔ | `wajib_role(['admin'])` |
| 32 | Status Sistem | — | — | ✔ | `wajib_role(['admin'])` |
| 33 | Pengingat Harian | — | — | ✔ | `wajib_role(['admin'])` |
| 34 | Analisis Sosial Terpadu | ✔ | ✔ | ✔ | `wajib_login()`; halaman berdiri sendiri, ditautkan dari SRM & CID (tidak tampil di menu) |
| 35 | Panduan Power BI (ODBC) | — | — | ✔ | `wajib_role(['admin'])`; halaman berdiri sendiri, tidak tampil di menu |
| 36 | Detail Laporan + Komentar | ✔ (milik sendiri) | ✔ | ✔ | Kepemilikan laporan + peran mentor/admin (cegah IDOR) |
| 37 | Keputusan Massal Laporan | — | ✔ | ✔ | `wajib_role(['mentor','admin'])`; tiap laporan tetap lewat `laporan_putuskan()` |
| 38 | Proses Keputusan Tunggal | — | ✔ | ✔ | `wajib_role(['mentor','admin'])` + CSRF per aksi |
| 39 | Bukti Gambar (`lihat_foto.php`) | ✔ (milik sendiri) | ✔ | ✔ | `wajib_login()`; izin = pemilik **atau** mentor/admin; MIME diperiksa `finfo` |

> **Catatan analisis (bahan diskusi Bab IV):** `peta_akses()` mendefinisikan
> kemampuan `impor.data` → `['mentor','admin']`, namun pada halaman
> `impor_laporan.php` sebenarnya batasan diterapkan lewat
> `impor_jenis_untuk_role()` (karyawan dibatasi ke data sendiri) dan bukan lewat
> `boleh_akses('impor.data')`. Artinya karyawan **boleh mengimpor** tetapi
> hanya data miliknya sendiri. Perbedaan konvensi ini layak dibahas
> sebagai temuan arsitektur dan menjadi bahan rekomendasi.

## 3.3.4 Rincian Modul Inti dan Pemetaan Kode

Tabel 3.4 memetakan modul **sebagaimana terlihat pengguna**. Subbab ini
menurunkan modul tersebut menjadi berkas kode yang menjalankannya, agar setiap
klaim pada Bab IV dapat ditelusuri ke satu berkas konkret. Pemetaan ini juga
menjadi bukti bahwa ruang lingkup penelitian tidak melompati lapisan legacy.

### Tabel 3.4A — Modul Inti Lapisan Legacy (`includes/`, 27 berkas)

| No. | Berkas | Fungsi utama (ekspor) | Peran dalam arsitektur |
|---|---|---|---|
| 1 | `bootstrap.php` | — (urutan pemuatan) | Menetapkan urutan wajib: config → session → koneksi → helpers → csrf → auth → helper lain → layout |
| 2 | `config.php` | `cfg()` | Pembacaan konfigurasi aplikasi dan penggabungan `config_lokal` |
| 3 | `session.php` | — | Pengaturan sesi `PHRSESS`, save path, `use_strict_mode` |
| 4 | `csrf.php` | `csrf_token()`, `csrf_field()`, `csrf_cek()`, `csrf_cek_atau_matikan()` | Token CSRF per aksi (`global`, `hapus`, `impor`, dst.) |
| 5 | `auth.php` | `wajib_login()`, `wajib_role()`, `peta_akses()`, `boleh_akses()`, `login_tuntaskan()` | Autentikasi sesi + RBAC berbasis peta kemampuan (default-deny) |
| 6 | `security.php` | `login_*()`, `password_*()`, `otp_*()`, `backup_codes_*()`, `sesi_*()` | Penguncian login, kebijakan sandi, OTP 2FA, kode cadangan, sesi aktif |
| 7 | `akun.php` | `token_buat()`, `token_ambil()`, `token_pakai()`, `email_*_kirim()` | Token satu-pakai (hash SHA-256) untuk verifikasi surel & reset sandi |
| 8 | `mailer.php` | `kirim_email()`, `template_surat()` | Surel OTP/notifikasi; SMTP kosong → mode log `backups/mail-log/` |
| 9 | `whatsapp.php` | `wa_normalkan_nomor()`, `wa_aktif()`, `kirim_whatsapp()` | WhatsApp via gateway Fonnte/Wablas; nonaktif tanpa galat bila `WA_GATEWAY` kosong |
| 10 | `validators.php` | `validasi_laporan()`, `validasi_revisi()`, `val_*()` | Validasi isian laporan (tanggal, jam, panjang teks) |
| 11 | `keputusan_laporan.php` | `laporan_putuskan()` | **Satu-satunya** pintu transisi status laporan (dipakai aksi tunggal & massal) |
| 12 | `komentar.php` | `komentar_ambil()`, `komentar_boleh()`, `komentar_tampil()` | Komentar/revisi beserta pemeriksaan hak |
| 13 | `notifikasi.php` | `buat_notifikasi()`, `notifikasi_ke_mentor()`, `notifikasi_ke_karyawan()`, `jumlah_notifikasi_belum_dibaca()` | Satu sumber pembuatan notifikasi (menghapus duplikasi antarhalaman) |
| 14 | `pemberitahuan.php` | `kabari_status_laporan()` | Pemberitahuan perubahan status laporan |
| 15 | `badge_helper.php` | `badge_status()` | Lencana status seragam |
| 16 | `audit.php` | `catat_audit()`, `audit_baris()` | Jejak audit terpusat |
| 17 | `layout.php` | `menu_tersedia()`, `mulai_halaman()`, `akhiri_halaman()`, `render_panel_chat()` | Layout + sidebar per peran (sumber Tabel 3.4) |
| 18 | `jadwal.php` | `jadwal_*()` (21 fungsi) | Agenda, validasi, transisi status, pengingat jatuh tempo |
| 19 | `kasus.php` | `kasus_izin_*()`, `kasus_simpan()`, `kasus_perbarui()`, `kasus_sinkron_tim()` | Kasus + tim penugasan + aturan visibilitas (cegah IDOR) |
| 20 | `srm_cid.php` | `srm_cid_impor()`, `srm_peta_kolom()`, `srm_cid_deteksi_header()` | Impor SRM/CID, pembersihan angka/tanggal/rupiah |
| 21 | `wilayah.php` | `wilayah_norm()`, `wilayah_geokode()`, `wilayah_cari()`, `wilayah_resolusi_tabel()` | Normalisasi nama wilayah + geokode Nominatim + resolusi koordinat |
| 22 | `impor_excel.php` | `impor_baca()`, `impor_tebak_pemetaan()`, `impor_normalisasi_pemetaan()`, `impor_commit()` (40+ fungsi) | Impor Excel/CSV jenis tetap + pemetaan kolom |
| 23 | `impor_dataset.php` | `dataset_simpan()`, `dataset_profil_kolom()`, `dataset_ambil()` | Impor "dataset generik": header berkas menjadi kolom `dataset` |
| 24 | `ai_client.php` | `ai_panggil()`, `ai_validasi()`, `ai_narasi()`, `ai_antrian_*()` | **Satu-satunya** jalur PHP → `ai-service` (`X-Service-Key`); tidak pernah memanggil LLM langsung |
| 25 | `helpers.php` | `e()`, `flash_*()`, `label_status()`, `tanggal_id()`, `ip_klien()` | Utilitas tampilan, pelabelan status, dan metadata klien |
| 26 | `config_lokal.example.php` | — (contoh) | Templat rahasia: kredensial DB, SMTP, gateway WA |
| 27 | `config_lokal.php` | — (rahasia) | Berkas nyata; diabaikan Git dan dilindungi `.htaccess` |

### Tabel 3.4B — Halaman Legacy di Akar (61 berkas) menurut Domain

| No. | Kelompok | Berkas | Jumlah |
|---|---|---|---|
| 1 | Landing & autentikasi | `index.php`, `login.php`, `logout.php`, `register.php`, `dua_faktor.php`, `verifikasi_email.php`, `lupa_sandi.php`, `reset_sandi.php` | 8 |
| 2 | Dasbor per peran | `dashboard.php`, `mentor_dashboard.php`, `admin_dashboard.php`, `ringkasan.php` | 4 |
| 3 | Laporan harian | `buat_laporan.php`, `riwayat.php`, `detail_laporan.php`, `edit_riwayat.php`, `edit_revisi.php`, `komentar_proses.php`, `lihat_foto.php`, `hapus_riwayat.php`, `export_pdf.php` | 9 |
| 4 | Persetujuan & rekap laporan | `mentor_detail.php`, `mentor_proses.php`, `mentor_bulk.php`, `distribusi_laporan.php`, `rekap_laporan.php` | 5 |
| 5 | Absensi | `absensi.php`, `mentor_absensi.php`, `rekap_absensi.php` | 3 |
| 6 | Ekspor dokumen | `export_excel.php`, `export_word.php` | 2 |
| 7 | Impor & visualisasi data | `impor_laporan.php`, `impor_srm_cid.php`, `impor_dataset.php`, `analisis_dataset.php`, `analisis_sosial.php` | 5 |
| 8 | SRM & CID | `srm.php`, `cid.php` | 2 |
| 9 | Kasus | `dashboard_kasus.php`, `buat_kasus.php`, `detail_kasus.php`, `edit_kasus.php`, `hapus_kasus.php` | 5 |
| 10 | Jadwal & pengingat | `jadwal.php`, `kalender.php`, `pengingat.php` | 3 |
| 11 | Peta & geokode | `peta.php` | 1 |
| 12 | Notifikasi & asisten | `notifikasi.php`, `notifikasi_helper.php`, `asisten.php`, `api_notifikasi_jumlah.php` | 4 |
| 13 | Endpoint AI legacy | `api_chat.php`, `api_validasi.php` | 2 |
| 14 | Administrasi | `pengguna.php`, `riwayat_audit.php`, `backup.php`, `status.php`, `powerbi_ekspor.php`, `panduan_powerbi.php` | 6 |
| 15 | Akun | `profil.php` | 1 |
| 16 | Internal | `koneksi.php` | 1 |
| | **Total** | | **61** |

> `analisis_sosial.php` dan `panduan_powerbi.php` sengaja **tidak** muncul di
> sidebar: keduanya halaman berdiri sendiri yang ditautkan dari halaman lain
> (SRM/CID dan administrasi Power BI). Keduanya tetap wajib login.

### Tabel 3.4C — Pemetaan Controller Laravel ke Endpoint dan Model

| No. | Controller | Lapisan | Endpoint / halaman | Model & service utama |
|---|---|---|---|---|
| 1 | `AuthController` (web) | Web | `login`, `dua-faktor`, `register`, `lupa-sandi` | `User`, `UserSecurity`, `KeamananLogin`, `Otp`, `KodeCadangan`, `AuditLogger` |
| 2 | `DashboardController` | Web | Dasbor per peran | `LaporanHarian`, `Absensi`, `Kasus` |
| 3 | `LaporanController` | Web | Halaman laporan | `LaporanHarian`, `BuktiGambar`, `KomentarLaporan` |
| 4 | `AbsensiController` | Web | Halaman absensi | `Absensi` |
| 5 | `RekapController` | Web | Rekap laporan & absensi | `LaporanHarian`, `Absensi` |
| 6 | `ImporController` | Web | Halaman impor | `ImporProfil`, `ImporSejarah` |
| 7 | `KalenderController` | Web | Kalender aktivitas | `Jadwal`, `LaporanHarian` |
| 8 | `KasusController` | Web | Halaman kasus | `Kasus`, `KasusPenugasan`, `KasusStatusLog` |
| 9 | `NotifikasiController` | Web | Halaman notifikasi | `Notifikasi` |
| 10 | `RingkasanController` | Web | Halaman ringkasan | `LaporanHarian`, `Absensi` |
| 11 | `TugasController` | Web | Halaman tugas | `Tugas` |
| 12 | `ProfilController` | Web | Halaman akun & keamanan | `User`, `SesiAktif`, `PasswordHistory` |
| 13 | `AsistenController` | Web (dipakai API) | `POST /api/v1/asisten/kirim` | `ChatSession`, `ChatMessage`, `AiJob` |
| 14 | `Controller` | Basis | — | Kelas dasar |
| 15 | `Api\AuthController` | API | `/auth/login`, `/auth/verifikasi`, `/auth/logout`, `/auth/profil`, `/auth/ganti-password` | `User`, `AuditLogger`, `KeamananLogin` |
| 16 | `Api\LaporanController` | API | `GET /laporan/riwayat`, `POST /laporan` | `LaporanHarian`, `BuktiGambar` |
| 17 | `Api\LaporanApiController` | API | `GET /laporan`, `/absensi`, `/rekap/harian`, `/rekap/pengguna`, `/bi/kasus` | `LaporanHarian`, `Absensi`, `Kasus` |
| 18 | `Api\AbsensiController` | API | `GET /absensi/riwayat`, `POST /absensi/check-in`, `/check-out` | `Absensi` |
| 19 | `Api\TugasController` | API | `GET /tugas`, `PATCH /tugas/{id}/status` | `Tugas` |
| 20 | `Api\KasusController` | API | `GET/POST /kasus`, `GET/PUT /kasus/{id}` | `Kasus`, `KasusPenugasan`, `KasusStatusLog`, `Notifikasi` |
| 21 | `Api\NotifikasiController` | API | `GET /notifikasi`, `POST /notifikasi/{id}/dibaca`, `/dibaca-semua` | `Notifikasi` |
| 22 | `Api\RingkasanController` | API | `GET /ringkasan` | `LaporanHarian`, `Absensi` |
| 23 | `Api\AnalitikController` | API | `GET /dasbor`, `/rekap/*`, `/ringkasan-kegiatan`, `/kalender`, `/analitik/srm`, `/analitik/cid` | `LaporanHarian`, `SrmKegiatan`, `CidProgram` |
| 24 | `Api\EksporController` | API | `GET /ekspor/laporan`, `/ekspor/absensi`, `/ekspor/kasus` | `LaporanHarian`, `Absensi`, `Kasus` + PhpSpreadsheet |

Dua hal yang perlu dicatat dari pemetaan ini dan dibahas pada Bab IV:

1. **`AsistenController` bukan controller API**, melainkan controller web yang
   dipakai langsung oleh rute `/api/v1/asisten/kirim` agar logika obrolan tidak
   terduplikasi antara halaman web dan aplikasi Android.
2. **`Api\LaporanApiController` dan `Api\LaporanController` berdampingan**
   dengan pemisahan tegas: yang pertama untuk pembaca *read-only* (Power BI,
   token statis), yang kedua untuk pengguna ber-Sanctum. Pemisahan ini mencegah
   satu titik masuk melayani dua model autentikasi sekaligus.

---

# 3.4 Perancangan Sistem

## 3.4.1 Diagram Use Case

Tiga diagram (satu per peran) — **Tabel 3.5**.

| Diagram | Aktor | Use Case yang Dimuat | Jumlah |
|---|---|---|---|
| Gambar 3.1 | Karyawan | UC-01, UC-02, UC-07, UC-09, UC-10, UC-11, UC-17, UC-18 | 8 |
| Gambar 3.2 | Mentor | UC-03, UC-04, UC-05, UC-06, UC-07, UC-08, UC-09, UC-10, UC-11, UC-18 | 10 |
| Gambar 3.3 | Admin | UC-04, UC-05, UC-06, UC-07, UC-08, UC-12, UC-13, UC-14, UC-15, UC-16, UC-09, UC-17 | 12 |

> Aktor eksternal yang muncul: **Sistem LLM (Gemini/Ollama)** dan
> **Mesin Power BI** (khusus admin).

## 3.4.2 Diagram Activity

| Gambar | Judul | Alur yang Digambarkan |
|---|---|---|
| Gambar 3.4 | Siklus persetujuan laporan | draft → kirim → mentor periksa → setujui / perlu revisi → (notifikasi) |
| Gambar 3.5 | Rantai fallback model AI | coba Gemini → gagal? → coba Gemini lite → gagal? → coba Ollama → gagal? → 503 / deterministik |
| Gambar 3.6 | Approval gate tindakan AI | permintaan alat berisiko → cek peran → cek `konfirmasi` → usulan / eksekusi → audit |
| Gambar 3.7 | Antrean `ai_jobs` | enqueue → klaim atomik → proses → selesai / gagal → pemulihan job macet |
| Gambar 3.8 | Login dan 2FA | input → verifikasi → OTP → backup code → sesi aktif → batas idle |

## 3.4.3 Diagram Sequence

| Gambar | Judul | Alur yang Digambarkan |
|---|---|---|
| Gambar 3.9 | Login + OTP + penerbitan token | Browser → Laravel → `users` → `user_otp` → mailer → token Sanctum |
| Gambar 3.10 | Akses API terproteksi | Klien (SPA/APK) → `Authorization: Bearer` → `auth:sanctum` → `CekPeran` → controller → basis data |
| Gambar 3.11 | Percakapan function calling | Pengguna → PHP → `ai-service` → Gemini → function call → alat → basis data → balasan + sumber |
| Gambar 3.12 | Pemulihan basis data (approval) | Admin → AI → backup otomatis → konfirmasi `PULIHKAN` → restore → audit |

> **Gambar 3.11 adalah gambar paling penting** — inilah bukti arsitektur
> microservice + function calling yang menopang judul.

## 3.4.4 Diagram Component / Deployment

| Simpul | Teknologi | Peran |
|---|---|---|
| Klien Web (React SPA) | Vite + React 19 | Antarmuka 16 halaman |
| Halaman Legacy (flat PHP) | Apache + PHP 8.2 | Halaman legacy yang belum dimigrasikan |
| API Laravel | Laravel 12 | 37 endpoint `/api/v1`, Sanctum |
| Klien Android | Kotlin + Compose | 11 layar |
| Microservice AI | Python + FastAPI | Gateway LLM, 21 alat, antrean job |
| Model LLM | Gemini / Ollama | Inferensi primer & cadangan |
| Basis Data | MariaDB 10.4 | 47 tabel bersama |
| Lapisan Analitik | 22 VIEW + `bi_reader` | Power BI (mode Import, read-only) |
| Antrian | Tabel `ai_jobs` | Job asinkron, worker penjadwal |

## 3.4.5 ERD

- **Gambar 3.13 — ERD ringkas (isi laporan):** `users`, `laporan_harian`,
  `absensi`, `kasus`, `kasus_penugasan`, `kasus_status_log`, `tugas`,
  `notifikasi`, `audit_log`, `jadwal`.
- **Lampiran A — ERD lengkap:** seluruh **47 tabel** (37 tabel pada
  `database/schema/legacy/` + 11 tabel yang dibuat oleh 10 migrasi Laravel),
  beserta kamus data.

## 3.4.6 Diagram Arsitektur Microservice

**Gambar 3.14 — Arsitektur Mikroservice** (gambar utama Bab IV). Akan dipakai
ulang di Subbab 4.3.1.

```
┌───────────────┐  HTTP + Bearer   ┌──────────────┐
│  SPA React    │───────────────► │              │      ┌──────────────┐
│  16 halaman   │                   │   Laravel    │      │  MariaDB     │
└───────────────┘                   │   12         │─────►│  47 tabel    │
┌───────────────┐  HTTP + Bearer   │   37 API     │      │  (shared)    │
│  APK Android  │───────────────► │              │      └──────┬───────┘
│  11 layar     │                   └──────┬───────┘             │
└───────────────┘                          │                     │
                                    X-Service-Key                 │
┌───────────────┐   HTTP + JSON           ▼                     │
│  Legacy PHP   │────────────────►┌──────────────┐              │
│  ~20 halaman  │                  │  ai-service  │              │
└───────────────┘                  │  (FastAPI)   │              │
                                   │  21 alat     │              │
                                   └──┬────────┬──┘              │
                              REST     │        │                 │
                          ┌───────────▼──┐  ┌──▼────────┐        │
                          │   Gemini     │  │  Ollama   │        │
                          │ gemini-3.8-  │  │ qwen2.5:  │        │
                          │ flash (utama)│  │ 7b (cadang)│       │
                          └──────────────┘  └───────────┘        │
                                                              ▼
                                            ┌───────────────────────────┐
                                            │  22 VIEW (SQL SECURITY    │
                                            │  DEFINER) + akun bi_reader│
                                            │        → Power BI        │
                                            └───────────────────────────┘
```

## 3.4.7 Rancangan Antarmuka

- Tema korporat PHR: `#003D7D` (biru tua), `#005EB8` (biru), `#EB1C24` (merah),
  `#159447` (hijau), latar `#F5F5F5`.
- Mode terang & gelap; responsif mulai 360 px.
- **Tabel 3.6 — Daftar layar kunci untuk wireframe:**

| No. | Layar | Peran | Keterangan |
|---|---|---|---|
| 1 | Masuk + 2FA | Semua | Alur autentikasi dua tahap |
| 2 | Dashboard karyawan | Karyawan | Kartu ringkasan + grafik |
| 3 | Buat Laporan | Karyawan | Formulir kegiatan harian |
| 4 | Absensi | Karyawan | Check-in/out + kondisi kesehatan |
| 5 | Periksa Laporan | Mentor | Antrean persetujuan + aksi massal |
| 6 | Rekap Absensi | Mentor/Admin | Tabel + filter + ekspor |
| 7 | Analisis Dataset | Mentor/Admin | Chart + profil kolom |
| 8 | Asisten AI | Semua | Obrolan + sumber jawaban |
| 9 | Kelola Pengguna | Admin | Tabel pengguna + ubah peran |
| 10 | Riwayat Perubahan | Admin | Jejak audit |

---

# 3.5 Rancangan Antarmuka API

Basis: `routes/api.php` (37 endpoint) **ditambah 3 endpoint AI pada lapisan
legacy PHP** (Tabel 3.10A). Konvensi: prefix `/api/v1`, JSON,
`Authorization: Bearer <token Sanctum>` untuk endpoint terproteksi,
header `X-Service-Key` untuk klien layanan (PHP → ai-service).

### Tabel 3.7 — Layer Power BI (token statis, read-only)

| Metode | Endpoint | Autentikasi | Peran | Deskripsi |
|---|---|---|---|---|
| GET | `/api/v1/laporan` | Token statis | bi_reader | Data laporan harian untuk analitik |
| GET | `/api/v1/absensi` | Token statis | bi_reader | Data absensi untuk analitik |
| GET | `/api/v1/rekap/harian` | Token statis | bi_reader | Rekap harian |
| GET | `/api/v1/rekap/pengguna` | Token statis | bi_reader | Rekap per pengguna |
| GET | `/api/v1/bi/kasus` | Token statis | bi_reader | Data kasus untuk analitik |

### Tabel 3.8 — Endpoint Autentikasi

| Metode | Endpoint | Autentikasi | Peran | Deskripsi |
|---|---|---|---|---|
| POST | `/api/v1/auth/login` | Terbuka (throttle 10/mnt) | Publik | Masuk; mengembalikan token + status 2FA |
| POST | `/api/v1/auth/verifikasi` | Terbuka (throttle 10/mnt) | Publik | Verifikasi kode OTP / kode cadangan |
| POST | `/api/v1/auth/logout` | Bearer | Semua | Cabut token |
| GET | `/api/v1/auth/profil` | Bearer | Semua | Data profil pengguna |
| POST | `/api/v1/auth/ganti-password` | Bearer | Semua | Ganti kata sandi |

### Tabel 3.9 — Endpoint Modul Operasional

| Metode | Endpoint | Autentikasi | Peran | Deskripsi |
|---|---|---|---|---|
| GET | `/api/v1/ringkasan` | Bearer | Semua | Kartu ringkasan dasbor |
| GET | `/api/v1/dasbor` | Bearer | Semua (diskalakan per peran) | Kartu + tren + donat |
| GET | `/api/v1/laporan/riwayat` | Bearer | Semua | Riwayat laporan (dibatasi pemilik) |
| POST | `/api/v1/laporan` | Bearer | Karyawan+ | Simpan/kirim laporan |
| GET | `/api/v1/absensi/riwayat` | Bearer | Semua | Riwayat kehadiran sendiri |
| POST | `/api/v1/absensi/check-in` | Bearer | Karyawan+ | Check-in |
| POST | `/api/v1/absensi/check-out` | Bearer | Karyawan+ | Check-out |
| GET | `/api/v1/tugas` | Bearer | Semua | Daftar tugas |
| PATCH | `/api/v1/tugas/{id}/status` | Bearer | Semua | Ubah status tugas |
| GET | `/api/v1/kasus` | Bearer | Semua | Daftar kasus |
| POST | `/api/v1/kasus` | Bearer | **Mentor, Admin** | Buat kasus |
| GET | `/api/v1/kasus/{id}` | Bearer | Semua | Detail kasus + tim + riwayat status |
| PUT | `/api/v1/kasus/{id}` | Bearer | **Mentor, Admin** | Perbarui kasus |
| GET | `/api/v1/notifikasi` | Bearer | Semua | Daftar notifikasi |
| POST | `/api/v1/notifikasi/{id}/dibaca` | Bearer | Semua | Tandai satu notifikasi dibaca |
| POST | `/api/v1/notifikasi/dibaca-semua` | Bearer | Semua | Tandai semua dibaca |

### Tabel 3.10 — Endpoint Analitik, Ekspor, dan Asisten

| Metode | Endpoint | Autentikasi | Peran | Deskripsi |
|---|---|---|---|---|
| GET | `/api/v1/rekap/laporan` | Bearer | **Mentor, Admin** | Rekap laporan |
| GET | `/api/v1/rekap/absensi` | Bearer | **Mentor, Admin** | Rekap absensi |
| GET | `/api/v1/rekap/kasus` | Bearer | **Mentor, Admin** | Rekap kasus |
| GET | `/api/v1/ekspor/laporan` | Bearer | **Mentor, Admin** | Ekspor `.xlsx` (PhpSpreadsheet) |
| GET | `/api/v1/ekspor/absensi` | Bearer | **Mentor, Admin** | Ekspor `.xlsx` |
| GET | `/api/v1/ekspor/kasus` | Bearer | **Mentor, Admin** | Ekspor `.xlsx` |
| GET | `/api/v1/ringkasan-kegiatan` | Bearer | Semua (diskalakan) | Ringkasan kegiatan |
| GET | `/api/v1/kalender` | Bearer | Semua (diskalakan) | Kalender aktivitas |
| GET | `/api/v1/analitik/srm` | Bearer | Semua | Analitik SRM |
| GET | `/api/v1/analitik/cid` | Bearer | Semua | Analitik CID |
| POST | `/api/v1/asisten/kirim` | Bearer + throttle 20/mnt | Semua | Kirim pesan ke asisten AI |

### Tabel 3.10A — Endpoint AI pada Lapisan Legacy PHP (3 endpoint)

Ketiga berkas ini berada di akar proyek (bukan di bawah `/api/v1`) dan melayani
halaman legacy. Ketiganya memakai sesi `PHRSESS`, bukan token Sanctum.

| Metode | Endpoint | Autentikasi | Pembatas laju | Deskripsi |
|---|---|---|---|---|
| POST | `/api_chat.php` | Sesi + CSRF `global` | 30 pesan/menit per sesi | Panel asisten AI; meneruskan ke `ai_panggil('/chat')` dan menyimpan riwayat percakapan |
| POST | `/api_validasi.php` | Sesi + CSRF `global` | 30 pemeriksaan/menit per sesi | Pemeriksaan kelengkapan laporan oleh AI (`ai_validasi()`); panjang isian dibatasi |
| GET | `/api_notifikasi_jumlah.php` | Sesi | — | Jumlah notifikasi belum dibaca untuk lencana sidebar |

> **Kaidah konsistensi:** `api_validasi.php` tetap membalas struktur JSON yang
> sama (`ok=false` + pesan) ketika `ai-service` tidak aktif, sehingga klien web
> tidak perlu menangani dua bentuk balasan. Kaidah ini sama dengan yang dipakai
> `/api/v1/asisten/kirim` (HTTP 503 + pesan berbahasa Indonesia).

**Kaidah API yang ditetapkan (Subbab 3.5):**

1. Format permintaan & balasan: `application/json`.
2. Autentikasi: `Authorization: Bearer <token>` (Sanctum); kunci token statis
   khusus lapisan Power BI.
3. Status kode: `200` sukses, `201` dibuat, `400` permintaan buruk, `401`
   belum terautentikasi, `403` tidak berwenang, `404` tidak ditemukan,
   `422` validasi gagal, `429` rate limit, `500` galat server, `503` layanan
   AI tidak terjangkau.
4. Pesan galat berbahasa Indonesia dan menyebut nama sumber galat.
5. Paginasi pada endpoint daftar.
6. `X-Service-Key` pada semua panggilan dari PHP ke `ai-service`.

---

# 3.6 Rancangan Keamanan

## 3.6.1 Matriks Hak Akses (RBAC)

### Tabel 3.11 — Peta Kemampuan (`peta_akses()`)

| Kemampuan | Karyawan | Mentor | Admin | Default (bawaan) |
|---|:---:|:---:|:---:|---|
| `srm.lihat` | ✔ | ✔ | ✔ | admin |
| `cid.lihat` | ✔ | ✔ | ✔ | admin |
| `srm.impor` | — | — | ✔ | admin |
| `cid.impor` | — | — | ✔ | admin |
| `srm.hapus` | — | — | ✔ | admin |
| `cid.hapus` | — | — | ✔ | admin |
| `laporan.periksa` | — | ✔ | ✔ | admin |
| `laporan.hapus` | — | — | ✔ | admin |
| `impor.data` | — | ✔ | ✔ | admin |
| `audit.lihat` | — | — | ✔ | admin |
| `backup.kelola` | — | — | ✔ | admin |

> **Prinsip *default deny*:** kemampuan yang tidak terdaftar pada peta hanya
> boleh dilakukan admin. Ini terlihat pada
> `includes/auth.php` → `boleh_akses()`:
> `$peran = $peta[$kemampuan] ?? ['admin'];`

### Tabel 3.12 — Otorisasi pada Lapisan Alat AI (function calling)

| Kelompok Alat | Alat | Peran yang Boleh | Pembatasan Tambahan |
|---|---|---|---|
| Pencarian & laporan | `search_notes`, `validate_report`, `statistics`, `trend_analysis`, `weekly_summary`, `monthly_summary`, `narrative_report` | Semua | Data dibatasi: `mentor`/`admin` boleh memilih `user_id` lain |
| Tulis laporan | `update_note` | Pemilik, atau admin | Hanya status `draft`/`perlu_revisi`; default *dry-run*; tercatat di `audit_log` |
| Dokumen | `export_excel`, `export_word` | Semua | Berkas dilindungi `X-Service-Key` saat diunduh |
| Pencarian web | `web_search` | Semua | Dapat dinonaktifkan admin (`AI_WEB_SEARCH_AKTIF=false`); 3 penyedia berurutan |
| Jadwal | `daftar_jadwal`, `buat_jadwal`, `ubah_jadwal`, `hapus_jadwal`, `ingatkan_jadwal` | Semua | Hanya agenda **milik pemanggil**; tulis memerlukan `konfirmasi=true` |
| Pemeliharaan | `backup_database`, `list_backups`, `restore_database` | **Admin** | `restore_database` memerlukan `konfirmasi="PULIHKAN"` + backup pengaman otomatis |

### Tabel 3.13 — Matriks Rute Web (Laravel)

| Rute | Middleware | Peran yang Diizinkan |
|---|---|---|
| `/login`, `/dua-faktor` | — (publik) | Publik |
| Rute dasbor & modul karyawan | `auth` | Semua pengguna masuk |
| Rute rekap/ekspor/analitik | `auth` → `role:mentor,admin` | Mentor, Admin |
| Rute pengguna/audit/backup/status | `auth` → `role:admin` | Admin |
| Rute `/api/v1/*` (kelompok proteksi) | `auth:sanctum` | Semua pengguna masuk |
| `POST /api/v1/kasus` | `auth:sanctum` → `role:mentor,admin` | Mentor, Admin |
| `POST /api/v1/auth/login`, `/auth/verifikasi` | `throttle:10,1` | Publik |
| `POST /api/v1/asisten/kirim` | `auth:sanctum` → `throttle:20,1` | Semua pengguna masuk |

> **Catatan penting untuk Bab IV:** urutan middleware wajib `auth` lebih dulu,
> baru `role:*`. Bila `auth` dilewati, pengguna yang belum login diarahkan ke
> `/login` (bukan 403). Hal ini sudah bukan asumsi — sudah diuji pada
> `tests/Feature/Middleware/CekPeranTest.php`.

## 3.6.2 Autentikasi dan 2FA

### Tabel 3.14 — Komponen Keamanan Autentikasi

| Komponen | Tabel / Mekanisme | Fungsi |
|---|---|---|
| Kata sandi | `users.password` (hash) | Kredensial utama |
| Riwayat kata sandi | `password_history` | Mencegah pemakaian ulang kata sandi lama |
| Upaya login | `login_attempts` | Rate limit & deteksi brute force |
| Kunci akun | `login_locks` | Kunci sementara setelah percobaan berulang |
| Status 2FA | `user_security` | Flag 2FA aktif per pengguna |
| Kode OTP | `user_otp` | Kode sekali pakai untuk verifikasi 2FA |
| Kode cadangan | `backup_codes` | Pemulihan bila surel tidak diterima |
| Token & sesi | `user_tokens`, `sesi_aktif` | Token Bearer + daftar sesi aktif |
| Sesi web | `BatasSesi` middleware | Idle 30 menit; usia mutlak 8 jam |
| Sesi API | Sanctum | Token Bearer untuk SPA & APK |
| Otorisasi | `CekPeran` / `wajib_role()` | Penjaga peran |

### Tabel 3.15 — Tabel `audit_log` (ringkasan kolom)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT | Kunci utama |
| `user_id` | INT | Pelaku perubahan |
| `aksi` | VARCHAR | Jenis aksi (mis. `update_laporan`) |
| `tabel_ref` | VARCHAR | Tabel/entitas yang terpengaruh |
| `id_ref` | VARCHAR | Kunci baris yang terpengaruh |
| `detail` | TEXT | Nilai sebelum–sesudah / catatan |
| `ip` | VARCHAR | Alamat IP pelaku |
| `waktu` | DATETIME | Waktu pencatatan |

> Verifikasi kolom aktual: `database/schema/legacy/003_audit_log.sql`.

## 3.6.3 Jejak Audit

Audit dicatat pada tiga titik:

1. **Perubahan data oleh pengguna** — hapus laporan, hapus kasus, ubah peran,
   impor batch, hapus riwayat.
2. **Tindakan oleh AI** — `update_note` dan alat tulis jadwal (setiap
   penerapan tercatat).
3. **Tindakan berisiko** — pemulihan basis data dan pencadangan.

## 3.6.4 Approval Gate untuk Tindakan AI Berisiko

### Tabel 3.16 — Aturan Approval Gate

| Alat | Peran | Syarat Persetujuan | Efek Samping | Audit |
|---|---|---|---|---|
| `restore_database` | Admin | `konfirmasi="PULIHKAN"` | Menimpa basis data | Ya |
| `backup_database` | Admin | Tidak ada (aman) | Membuat berkas `.sql` | Ya |
| `send_email` | Semua | Persetujuan pengguna; default hanya usulan | Kirim surel | Ya |
| `send_whatsapp` | Semua | Persetujuan pengguna; default hanya usulan | Kirim pesan WA | Ya |
| `update_note` | Pemilik/Admin | `konfirmasi=true`; default *dry-run* | Ubah `laporan_harian` | Ya |
| `buat_jadwal` / `ubah_jadwal` / `hapus_jadwal` | Semua | `konfirmasi=true` | Tulis tabel `jadwal` | Ya |
| `ingatkan_jadwal` | Semua | `konfirmasi=true` | Kirim pengingat segera | Ya |

---

# 3.7 Lingkungan Implementasi

### Tabel 3.17 — Perangkat Keras

| Komponen | Spesifikasi | Peran |
|---|---|---|
| Server pengembangan | ‹CPU/RAM› | Menjalankan Apache, MariaDB, PHP, Node, Python |
| Perangkat uji Android | Emulator API 36 / perangkat fisik | Menjalankan APK |
| Jaringan | LAN internal ‹alamat› | Akses aplikasi mobile & Power BI |
| Penyimpanan | ≥ 20 GB | Basis data, backup, model Ollama |

### Tabel 3.18 — Perangkat Lunak

| Lapisan | Perangkat Lunak | Versi |
|---|---|---|
| Server | XAMPP (Apache, MariaDB, PHP) | Apache 2.4 / MariaDB 10.4 / PHP 8.2 |
| Aplikasi legacy | PHP + ekstensi `mysqli`, `phpmailer`, `dompdf`, `phpspreadsheet` | — |
| Framework | Laravel | 12 |
| Frontend | Node.js + Vite + Tailwind CSS | Node 20 / Vite 7 / Tailwind 4 |
| UI library | React / React DOM / React Router | 19.3 / 7.18 |
| Chart | Chart.js / react-chartjs-2 | 4.5 / 5.3 |
| AI service | Python + FastAPI + Uvicorn | 3.13 / 0.115.6 / 0.34 |
| Validasi AI | Pydantic / pydantic-settings | 2.10 / 2.7 |
| Klien HTTP AI | httpx | 0.28.1 |
| Basis data AI | PyMySQL | 1.1.1 |
| Dokumen AI | openpyxl / python-docx | 3.1.5 / 1.1.2 |
| YAML AI | PyYAML (validasi konfigurasi Hermes & frontmatter skill) | 6.0.2 |
| LLM utama | Google Gemini | `gemini-3.8-flash` (cadangan `gemini-3.5-flash-lite`) |
| LLM cadangan | Ollama | `qwen2.5:7b` (cadangan `qwen2.5:3b`) |
| Mobile | Android SDK + Gradle + JDK | compileSdk 36, minSdk 24, AGP 8.11.1, JDK 17 |
| Mobile UI | Kotlin + Jetpack Compose | 2.1.21 / Material 3 |
| Mobile jaringan | Retrofit + OkHttp + Gson | 2.11 / — / — |
| Mobile sesi | DataStore Preferences | — |
| Analitik | Power BI Desktop | ‹versi› |

### Tabel 3.19 — Perangkat Bantu

| Alat | Kegunaan |
|---|---|
| Git | Version control |
| VS Code | Editor |
| Postman | Uji API |
| phpMyAdmin | Administrasi basis data |
| Swagger UI (`/docs`) | Dokumentasi API `ai-service` |
| Apache Bench / k6 | Uji beban |
| Task Scheduler (Windows) | Penjadwalan `schedule:run`, worker `ai_jobs`, backup, pemeliharaan |
| Android Studio / ADB | Build & instalasi APK |

### Tabel 3.19A — Perkakas Operasional (`scripts/` dan `tools/`)

Perkakas ini **bukan bagian aplikasi yang dilayani pengguna**, tetapi dipakai
untuk memasang, menjadwalkan, memelihara, dan memverifikasi sistem. Keberadaannya
dilaporkan agar prosedur operasional pada Bab IV dapat direproduksi.

| No. | Kelompok | Berkas | Kegunaan |
|---|---|---|---|
| 1 | Penjadwalan | `jalankan_penjadwal.cmd` | Pembungkus `artisan schedule:run` untuk Task Scheduler tiap menit |
| 2 | Penjadwalan | `pengingat_jadwal.php` | Mengirim pengingat agenda yang jatuh tempo (tiap 5 menit) |
| 3 | Penjadwalan | `pengingat_harian.php` | Pengingat harian laporan/absensi |
| 4 | Pemeliharaan | `tools/backup_db.php`, `tools/backup_db.bat` | Cadangan basis data + retensi (`--simpan=30`) |
| 5 | Pemeliharaan | `tools/pemeliharaan.php` | Membersihkan token kedaluwarsa dan audit lama |
| 6 | Integrasi Power BI | `buat_dsn_powerbi.ps1` | Membuat DSN ODBC untuk akun `bi_reader` |
| 7 | Integrasi Power BI | `ekspor_skema.php` | Mengekspor skema basis data untuk dokumentasi ERD |
| 8 | Wilayah | `geokode_wilayah.php` | Mengisi koordinat massal dari nama wilayah |
| 9 | Wilayah | `resolusi_lokasi.php` | Menyelesaikan baris yang koordinatnya belum terisi |
| 10 | Pemasangan | `pasang_vhost.php`, `tambah_host_pelaporan.cmd` | Memasang VirtualHost Laravel + entri `hosts` |
| 11 | Pemasangan | `vhost_https.conf.example` | Contoh konfigurasi HTTPS (bahan rekomendasi Bab V) |
| 12 | Pemasangan | `buat_env_laravel.php`, `tambah_env_keamanan.php` | Menyiapkan `.env` Laravel dan kunci keamanan |
| 13 | Data awal | `buat_admin.php`, `impor_srm_cid.php`, `periksa_users.php` | Membuat akun admin, mengimpor SRM/CID, memeriksa pengguna |
| 14 | Kualitas | `sinkron_aset.php` | Menyelaraskan `assets/` ↔ `public/assets/` dan melaporkan selisih selector CSS |
| 15 | Kualitas | `uji_e2e_kasus_teks.php` | Uji ujung-ke-ujung modul kasus |
| 16 | Kualitas | `verifikasi_403.php`, `verifikasi_koneksi.php`, `verifikasi_lang.php`, `verifikasi_model.php` | Pemeriksaan penjaga peran, koneksi DB, kelengkapan terjemahan, dan kelengkapan model |
| 17 | Kualitas | `survei_skema.php` | Membandingkan skema nyata dengan berkas migrasi |
| 18 | Utilitas | `buat_aset.php`, `buat_model.php`, `perbaiki_env_warna.php`, `bersihkan-folder-lama.ps1`, `buat_konfig_uji_8081.php` | Generator aset/model, perbaikan konfigurasi, pembersihan folder lama |

---

# 3.8 Metode Pengujian

### Tabel 3.20 — Ringkasan Metode Pengujian

| No. | Jenis Uji | Level | Metode | Instrumen | Volume | Kriteria Lulus |
|---|---|---|---|---|---|---|
| 1 | Fungsional API & modul | Feature | Black-box | PHPUnit 11 (Laravel) | 166 uji | Semua assertions lulus |
| 2 | Fungsional legacy | Feature | Black-box | PHPUnit-legacy | 148 uji | Semua assertions lulus |
| 3 | Microservice AI | Unit + integration (mock LLM) | White-box | pytest 8 | 78 uji | Semua lulus tanpa model hidup |
| 4 | Struktur & validasi HTML | Static | Analisis berkas | PHPUnit legacy `StrukturTest` | ‹n› | 0 ketidaksesuaian struktur |
| 5 | Keamanan | Manual | Checklist OWASP 10 butir | Form review | 10 butir | Tidak ada temuan kritis/tinggi |
| 6 | Performa | Beban | Beban ringan | Apache Bench / k6 | ‹50 rps› | p95 ≤ target NFR-02 |
| 7 | Efektivitas AI | Manual | Rubrik + stopwatch | 30 skenario prompt | 30 skenario | Ketepatan ≥ ‹…›% |
| 8 | Fallback AI | Manual | Simulasi gangguan | Matriks skenario | ‹4› skenario | 100% terlayani |
| 9 | UAT | Black-box | Kuesioner + observasi | Form UAT | ≥ 15 responden | Rata-rata ≥ ‹4,0› |

### Tabel 3.21 — Matriks Skenario Uji Fallback AI

| No. | Skenario Gangguan | Provider Aktif Diharapkan | Respons Sistem | Status |
|---|---|---|---|---|
| 1 | Normal (jaringan baik) | `gemini-3.8-flash` | Jawaban normal + sumber | ‹…› |
| 2 | Gemini tidak dapat dijangkau | `gemini-3.5-flash-lite` → `qwen2.5:7b` | Jawaban normal, penyebut model berbeda | ‹…› |
| 3 | Seluruh model cloud mati | `qwen2.5:7b` (lokal) | Jawaban normal, latensi lebih tinggi | ‹…› |
| 4 | Seluruh model mati | — | HTTP **503** dengan pesan jelas, atau hasil deterministik bila `AI_DEGRADE_DETERMINISTIC=true` | ‹…› |

### Tabel 3.22 — Komponen Pengujian Otomatis yang Sudah Ada

| Komponen | Lokasi | Cakupan |
|---|---|---|
| Uji Laravel Feature | `tests/Feature/` | API analitik/dasbor/laporan/mobile, asisten, autentikasi, audit pengguna, kasus teks, konfigurasi, layanan keamanan, pengguna hapus, pengingat harian, SPA, tugas, middleware `BatasSesi` & `CekPeran` |
| Uji Laravel Unit | `tests/Unit/` | Kebijakan kata sandi |
| Uji Legacy | `tests/legacy/` | Struktur, keamanan, kasus, SRM/CID, impor, geokode, dataset, helper, integrasi, wilayah, jadwal |
| Uji AI service | `ai-service/tests/` | Autentikasi `X-Service-Key`, klien Gemini, kemasan (Hermes/skill/`.env.example`), pemuatan prompt, skema, ringkasan, alat jadwal & pencarian, validasi |

> **Catatan reproduksibilitas.** `tests/test_packaging.py` membaca
> `ai-service/hermes/hermes.config.yaml` tanpa penjaga keberadaan berkas,
> sementara direktori `ai-service/hermes/` terdaftar pada `.gitignore`. Pada
> mesin pengembang seluruh 78 uji lulus; pada **klon bersih** satu uji
> (`test_hermes_config_valid`) akan gagal. Syarat lingkungan ini wajib
> dituliskan pada Bab IV sebelum mengklaim angka kelulusan, dan perbaikannya
> diusulkan pada Bab V.

> **Bahan uji manual yang sudah tersedia.** `docs/UJI_COBA.md` memuat skenario
> uji per modul, daftar periksa keamanan, dan prosedur simulasi gangguan AI.
> Dokumen tersebut menjadi lampiran prosedur; Bab IV cukup mengisi kolom hasil
> sebenarnya.

---

# 📋 DAFTAR TABEL & GAMBAR UNTUK DAFTAR ISI

| Nomor | Judul | Sumber |
|---|---|---|
| **Tabel 3.1** | Rekapitulasi Teknik Pengumpulan Data | Pedoman wawancara, catatan observasi |
| **Tabel 3.2** | Ringkasan Use Case | `includes/layout.php` (menu per peran), `routes/api.php` |
| **Tabel 3.3** | Kebutuhan Non-Fungsional | Analisis kebutuhan, hasil pengujian awal |
| **Tabel 3.4** | Ketersediaan Modul per Peran | `includes/layout.php`, `includes/auth.php` |
| **Tabel 3.4A** | Modul Inti Lapisan Legacy (`includes/`) | `includes/*.php` |
| **Tabel 3.4B** | Halaman Legacy di Akar menurut Domain | `/*.php` |
| **Tabel 3.4C** | Pemetaan Controller Laravel | `app/Http/Controllers/`, `routes/api.php` |
| **Tabel 3.5** | Daftar Diagram Use Case | Diagram use case yang digambar |
| **Tabel 3.6** | Daftar Layar Kunci untuk Wireframe | `includes/layout.php`, `resources/js/react/pages/` |
| **Tabel 3.7** | Endpoint Layer Power BI | `routes/api.php` |
| **Tabel 3.8** | Endpoint Autentikasi | `routes/api.php` |
| **Tabel 3.9** | Endpoint Modul Operasional | `routes/api.php` |
| **Tabel 3.10** | Endpoint Analitik, Ekspor, dan Asisten | `routes/api.php` |
| **Tabel 3.10A** | Endpoint AI pada Lapisan Legacy PHP | `api_chat.php`, `api_validasi.php`, `api_notifikasi_jumlah.php` |
| **Tabel 3.11** | Peta Kemampuan RBAC | `includes/auth.php` |
| **Tabel 3.12** | Otorisasi pada Lapisan Alat AI | `ai-service/app/tools/` |
| **Tabel 3.13** | Matriks Rute Web | `routes/web.php`, `bootstrap/app.php` |
| **Tabel 3.14** | Komponen Keamanan Autentikasi | `database/schema/legacy/002_keamanan_login.sql` |
| **Tabel 3.15** | Struktur Tabel `audit_log` | `database/schema/legacy/003_audit_log.sql` |
| **Tabel 3.16** | Aturan Approval Gate | `ai-service/app/tools/` |
| **Tabel 3.17** | Perangkat Keras | Lokasi kerja penelitian |
| **Tabel 3.18** | Perangkat Lunak | `composer.json`, `package.json`, `ai-service/requirements.txt` |
| **Tabel 3.19** | Perangkat Bantu | Lokasi kerja penelitian |
| **Tabel 3.19A** | Perkakas Operasional | `scripts/`, `tools/` |
| **Tabel 3.20** | Ringkasan Metode Pengujian | Perancangan uji |
| **Tabel 3.21** | Matriks Skenario Uji Fallback AI | `ai-service/app/llm.py` |
| **Tabel 3.22** | Komponen Pengujian Otomatis | `tests/`, `ai-service/tests/` |

---

# 💡 CATATAN UNTUK MENGISI BAGIAN YANG MASIH KOSONG

Semua sel bertanda `‹…›` memerlukan data yang **hanya bisa diperoleh dari
lapangan**. Daftar lengkap beserta cara memperolehnya ada di bagian
"✅ CHECKLIST DATA YANG HARUS DIKUMPULKAN DARI LAPANGAN" pada
[`docs/KERANGKA_TA.md`](KERANGKA_TA.md).
