# Panduan Android Studio — APK PHR

Panduan langkah-demi-langkah untuk membuka, menjalankan, dan membangun
aplikasi Android **Sistem Pelaporan Kegiatan Harian** (`mobile/`) dengan
Android Studio. Aplikasi ini klien tipis dari REST API Laravel
(`/api/v1/*`) dengan autentikasi Bearer (Sanctum) — identik dengan React SPA.

> Ringkasan cepat (tanpa Android Studio): lihat `mobile/README.md` —
> `gradlew.bat assembleDebug` menghasilkan
> `mobile/app/build/outputs/apk/debug/app-debug.apk`.

---

## 1. Prasyarat

| Komponen | Versi teruji | Catatan |
|---|---|---|
| Android Studio | Ladybug / Koala (2024+) | Bundel sudah termasuk JDK 17 |
| JDK | 17+ | `JAVA_HOME` menunjuk JDK 17 bila build via terminal |
| Android SDK | `platforms;android-36`, `build-tools;36.x` | Instal lewat SDK Manager |
| Gradle | 8.14.3 (otomatis via wrapper) | Tidak perlu instal manual |
| RAM | ≥ 8 GB | Emulator + Android Studio |

Bila build dari **terminal** (tanpa Studio), pastikan `JAVA_HOME` menunjuk
JDK 17 dan variabel `ANDROID_HOME`/`ANDROID_SDK_ROOT` valid — atau cukup
biarkan `mobile/local.properties` menunjuk lokasi SDK (sudah terisi:
`sdk.dir=C:/Users/USER/AppData/Local/Android/Sdk`).

## 2. Membuka proyek

1. Buka Android Studio → **Open** (buka folder, **bukan** file).
2. Pilih folder **`mobile/`** (yang berisi `gradlew.bat`, `settings.gradle.kts`,
   `app/`). Jangan pilih root repositori — Android Studio harus membuka
   folder modul Android-nya sendiri.
3. Tunggu **Gradle sync** selesai (banner bawah). Wrapper otomatis mengunduh
   Gradle 8.14.3 pada sinkronisasi pertama (±5 menit, sekali saja).
4. Bila diminta "Trust Gradle Project?" → pilih **Trust Project**.

> `mobile/local.properties` bersifat lokal dan tidak di-commit — bila hilang,
> buat ulang berisi `sdk.dir=` dengan lokasi Android SDK Anda
> (mis. `sdk.dir=C\:/Users/NAMA/AppData/Local/Android/Sdk`).

## 3. Menyiapkan backend (wajib sebelum menjalankan APK)

APK membutuhkan Laravel berjalan. Dua cara:

### 3a. Emulator Android (disarankan untuk pengembangan)

1. XAMPP: jalankan **Apache** dan **MySQL**.
2. Pastikan aplikasi web Laravel bisa diakses lewat browser komputer:
   `http://localhost/pelaporan/public/` (atau virtual host yang mengarah ke
   folder `public/`).
3. Host komputer dapat dicapai dari emulator lewat alamat khusus
   **`10.0.2.2`** — jadi base URL di APK: **`http://10.0.2.2/pelaporan/public/`**
   (sesuaikan bila memakai virtual host port 8080, dst.).

### 3b. Perangkat fisik (satu jaringan/Wi-Fi)

1. Cari IP komputer: `ipconfig` → mis. `192.168.56.1`.
2. Base URL di APK: **`http://192.168.56.1/pelaporan/public/`**.
3. Pastikan firewall Windows mengizinkan Apache pada jaringan privat.

> Base URL **dapat diubah langsung dari layar masuk** APK (kolom "Alamat
> server") dan tersimpan di perangkat via DataStore — tidak perlu rebuild
> untuk mengganti alamat.

## 4. Menjalankan di emulator

1. **Device Manager** (kanan atas) → buat perangkat: **Pixel 7 / API 34**
   (sistem gambar apa pun yang terpasang; `minSdk` aplikasi 24).
2. Nyalakan emulator, lalu tekan **Shift+F10** (Run 'app') atau klik tombol
   hijau ▶.
3. Pada layar masuk: isi surel + kata sandi akun Laravel, dan isi **alamat
   server** `http://10.0.2.2/pelaporan/public/` bila berbeda dari bawaan.
4. Bila akun memakai 2FA, masukkan kode OTP yang dikirim ke surel.

## 5. Build APK (tanpa menjalankan aplikasi)

Melalui Android Studio:

1. Menu **Build → Build App Bundle(s) / APK(s) → Build APK(s)**.
2. Tunggu hingga notifikasi **"Build Successful"** muncul → klik **locate**
   untuk membuka folder hasil.

Melalui terminal:

```powershell
cd C:\xampp\htdocs\pelaporan\mobile
.\gradlew.bat assembleDebug
```

Hasil build:

```
mobile\app\build\outputs\apk\debug\app-debug.apk
```

Pasang langsung ke perangkat/emulator yang sedang terhubung:

```powershell
adb install -r mobile\app\build\outputs\apk\debug\app-debug.apk
```

Atau salin berkas `.apk` ke perangkat fisik lalu ketuk berkasnya
(izinkan "instal dari sumber tidak dikenal" bila diminta).

## 6. Build rilis (Go-Live nanti)

Build rilis memerlukan keystore sendiri:

```powershell
keytool -genkeypair -v -keystore phr-release.jks -alias phr -keyalg RSA -keysize 2048 -validity 10000
```

1. Isi `signingConfigs` pada `app/build.gradle.kts` (arahkan ke keystore).
2. `.\gradlew.bat assembleRelease` → hasil di
   `app/build/outputs/apk/release/`.
3. Simpan keystore **di luar** repositori (`.gitignore` sudah mengecualikan
   `*.jks`/`*.keystore`).
4. Saat Go-Live: wajib beralih ke **HTTPS**, lalu hapus
   `android:usesCleartextTraffic="true"` dari `AndroidManifest.xml`.

## 7. Masalah yang sering muncul

| Gejala | Penyebab & solusi |
|---|---|
| Gradle sync gagal: "JAVA_HOME not set" | Setel `JAVA_HOME` ke JDK 17, atau jalankan build dari Android Studio (punya JBR 17 bawaan). |
| Aplikasi terbuka tetapi dasbor kosong / "Gagal terhubung" | Base URL salah atau backend mati. Dari emulator gunakan `10.0.2.2`, bukan `localhost`. Periksa juga `php artisan serve`/Apache & MySQL hidup. |
| HTTP 401 di semua layar | Token kedaluwarsa — keluar lalu masuk ulang. |
| Emulator tidak tampil | Aktifkan **Virtualization (VT-x)** di BIOS, atau gunakan perangkat fisik via USB + "Install via USB" di opsi developer. |
| APK lama tidak terganti | `adb install -r` (replace) atau uninstall dulu aplikasi lama — catatan: data sesi/token akan hilang. |
| Gambar/unggahan tidak tampil | Pastikan folder `uploads/` dapat diakses publik lewat alamat yang sama dengan base URL. |

## 8. Struktur proyek (peta cepat)

```
mobile/
├─ app/src/main/java/com/phr/pelaporan/
│  ├─ MainActivity.kt          # titik masuk + NavHost + laci menu
│  ├─ data/Dto.kt              # model JSON (Gson)
│  ├─ data/Jaringan.kt         # Retrofit + Sanctum bearer
│  ├─ data/Pref.kt             # DataStore (token, alamat server)
│  └─ ui/
│     ├─ Nav.kt / Tema.kt      # navigasi & warna korporat
│     ├─ komponen/Komponen.kt  # kartu, chip status, tombol
│     └─ layar/Layar*.kt       # 11 layar (masuk, dasbor, laporan, …)
└─ app/build.gradle.kts        # SDK & dependensi (AGP 8.11.1)
```
