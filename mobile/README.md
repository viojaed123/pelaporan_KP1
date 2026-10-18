# APK Android — Sistem Pelaporan Kegiatan Harian (PHR)

Aplikasi Android **native** (Kotlin + Jetpack Compose) untuk petugas lapangan
PHR. Aplikasi ini adalah klien tipis dari API Laravel `/api/v1/*` (Fase A),
memakai **Laravel Sanctum** dengan token Bearer — persis sama dengan React SPA,
sehingga autentikasi, aturan peran, dan data identik di web maupun mobile.

## Ringkasan teknologi

| Bagian | Pilihan |
|---|---|
| Bahasa | Kotlin 2.1.21 |
| UI | Jetpack Compose (Material 3) |
| Arsitektur | Satu `MainActivity` + `NavHost` + laci menu (padanan sidebar web) |
| Jaringan | Retrofit 2.11 + OkHttp + Gson |
| Penyimpanan sesi | DataStore Preferences (token, alamat server, ringkasan pengguna) |
| Build | Android Gradle Plugin 8.11.1 / Gradle 8.14.3, `compileSdk 36`, `minSdk 24` |

Warna korporat PHR dipakai konsisten: `#003D7D` (biru tua), `#005EB8` (biru),
`#EB1C24` (merah), `#159447` (hijau), latar `#F5F5F5`.

## Layar yang tersedia

1. **Masuk** — surel + kata sandi, mendukung **2FA** (kode OTP dikirim surel).
   Tersedia kolom **alamat server** agar APK dapat diarahkan ke server mana pun.
2. **Dasbor** — kartu ringkasan (laporan bulan ini, absensi hari ini, tugas
   aktif, tugas terlambat) + daftar tugas terbaru.
3. **Buat Laporan** — formulir laporan kegiatan harian.
4. **Riwayat Laporan** — daftar laporan dengan penomoran halaman.
5. **Absensi** — tombol check-in / check-out + riwayat kehadiran.
6. **Tugas** — daftar tugas dengan aksi ubah status (Kerjakan / Selesai).
7. **Kasus** — daftar kasus + panel detail (anggota tim & riwayat status).
8. **Buat Kasus** — khusus mentor/admin; PIC & anggota **diketik bebas**
   (dicocokkan otomatis ke pengguna terdaftar oleh server).
9. **Asisten AI** — obrolan tanya-jawab berbasis data internal.
10. **Notifikasi** — daftar notifikasi + tandai dibaca.
11. **Profil** — data akun, ganti kata sandi, dan keluar.

## Menyiapkan alamat server

Alamat server bawaan ada di `app/build.gradle.kts` (`BuildConfig.BASE_URL`) dan
**dapat diubah langsung dari layar masuk** (tersimpan di perangkat):

* **Emulator Android** — host komputer adalah `10.0.2.2` → `http://10.0.2.2/`
* **Perangkat fisik (satu jaringan/LAN)** — IP komputer, mis. `http://192.168.56.1/`

> Catatan: aplikasi memakai HTTP polos untuk jaringan internal
> (`android:usesCleartextTraffic="true"`). Untuk Go-Live **wajib** beralih ke
> HTTPS (lihat checklist Go-Live) dan menghapus setelan cleartext.

## Membangun APK

Prasyarat: JDK 17+, Android SDK (`platforms;android-36`, `build-tools;36.x`).

```powershell
# dari folder mobile/
.\gradlew.bat assembleDebug
```

Hasil: **`mobile/app/build/outputs/apk/debug/app-debug.apk`**

Pasang ke perangkat/emulator:

```powershell
adb install -r mobile\app\build\outputs\apk\debug\app-debug.apk
```

`local.properties` (berisi `sdk.dir`) bersifat lokal dan tidak di-commit.

## Tanda tangan rilis (nanti, saat Go-Live)

Build rilis perlu keystore. Contoh:

```powershell
keytool -genkeypair -v -keystore phr-release.jks -alias phr -keyalg RSA -keysize 2048 -validity 10000
```

Lalu isi `signingConfigs` pada `app/build.gradle.kts` dan jalankan
`.\gradlew.bat assembleRelease`. Simpan keystore **di luar** repositori
(sudah dikecualikan oleh `.gitignore`).

## Peta endpoint yang dipakai

| Layar | Endpoint |
|---|---|
| Masuk | `POST /api/v1/auth/login`, `POST /api/v1/auth/verifikasi` |
| Dasbor | `GET /api/v1/ringkasan`, `GET /api/v1/tugas` |
| Laporan | `GET /api/v1/laporan/riwayat`, `POST /api/v1/laporan` |
| Absensi | `GET /api/v1/absensi/riwayat`, `POST /api/v1/absensi/check-in`, `/check-out` |
| Tugas | `GET /api/v1/tugas`, `PATCH /api/v1/tugas/{id}/status` |
| Kasus | `GET /api/v1/kasus`, `GET /api/v1/kasus/{id}`, `POST /api/v1/kasus`, `PUT /api/v1/kasus/{id}` |
| Notifikasi | `GET /api/v1/notifikasi`, `POST /api/v1/notifikasi/{id}/dibaca`, `POST /api/v1/notifikasi/dibaca-semua` |
| Asisten | `POST /api/v1/asisten/kirim` |
| Profil | `GET /api/v1/auth/profil`, `POST /api/v1/auth/ganti-password`, `POST /api/v1/auth/logout` |

## Struktur sumber

```
mobile/app/src/main/java/com/phr/pelaporan/
├── MainActivity.kt              # Activity tunggal + pemuatan sesi
├── data/
│   ├── Dto.kt                   # model data API (Gson)
│   ├── Jaringan.kt              # Retrofit + Sesi + pesanGalat()
│   └── Pref.kt                  # DataStore: token, alamat, pengguna
└── ui/
    ├── Tema.kt                  # warna & tema korporat PHR
    ├── Nav.kt                   # laci menu + NavHost
    ├── komponen/Komponen.kt     # kartu, lencana, baris, dsb.
    └── layar/                   # satu berkas per layar
```
