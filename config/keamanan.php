<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi Keamanan
|--------------------------------------------------------------------------
|
| Nilai-nilai ini dipindahkan dari includes/config.php sistem lama
| (SESI_IDLE_DETIK, LOGIN_MAKS_GAGAL, dst.) ke konfigurasi Laravel agar
| dapat diubah lewat .env tanpa menyentuh kode.
|
| Setiap nilai DAFAR di .env memakai nama huruf kecil dengan garis bawah
| (mis. LOGIN_MAKS_GAGAL). Nilai bawaan di sini disamakan persis dengan
| config.php lama supaya perilaku keamanan tidak berubah setelah migrasi.
|
| Catatan penting: jangan mengubah angka-angka ini tanpa memahami dampaknya.
| Misalnya LOGIN_MAKS_GAGAL = 5 berarti setelah 5 kali salah sandi, kunci
| diaktifkan; menaikkannya melemahkan pertahanan brute force.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Batas waktu sesi
    |----------------------------------------------------------------------
    | idle     : berapa lama sesi boleh diam tanpa aktivitas sebelum keluar.
    | absolut  : berapa lama sesi boleh hidup total sejak login, apa pun
    |            aktivitasnya. Mencegah sesi yang dibiarkan terbuka semalaman
    |            tetap sah keesokan pagi.
    | maks_aktif: jumlah sesi perangkat yang boleh aktif bersamaan untuk
    |             SATU akun. Melebihi ini, sesi tertua dicabut.
    */

    'sesi_idle_detik'    => (int) env('SESI_IDLE_DETIK', 30 * 60),
    'sesi_absolut_detik' => (int) env('SESI_ABSOLUT_DETIK', 8 * 3600),
    'sesi_maks_aktif'    => (int) env('SESI_MAKS_AKTIF', 3),

    /*
    |----------------------------------------------------------------------
    | Pembatas laju login & penguncian akun
    |----------------------------------------------------------------------
    | Diterapkan pada DUA kunci sekaligus: alamat IP dan alamat surel.
    | Dengan begitu, "password spraying" (satu sandi dicoba ke banyak akun
    | dari satu IP) dan brute force klasik (satu akun dari banyak IP)
    | dua-duanya tetap terkena batas.
    |
    | backoff : durasi penguncian dalam detik untuk kegagalan ke-5, ke-6,
    |           ke-7, dan seterusnya (indeks terakhir dipakai untuk semua
    |           kegagalan lanjutan). Pola menaik -- bukan durasi tetap --
    |           agar penyerang yang sabar tetap menanggung biaya waktu yang
    |           semakin besar.
    */

    'login_maks_gagal'    => (int) env('LOGIN_MAKS_GAGAL', 5),
    'login_jendela_detik' => (int) env('LOGIN_JENDELA_DETIK', 15 * 60),
    'login_kunci_detik'   => (int) env('LOGIN_KUNCI_DETIK', 15 * 60),
    'login_backoff_detik' => array_map('intval', array_filter(
        explode(',', (string) env('LOGIN_BACKOFF_DETIK', '300,900,3600'))
    )),

    /*
    |----------------------------------------------------------------------
    | Kebijakan kata sandi
    |----------------------------------------------------------------------
    */

    'password_min'      => (int) env('PASSWORD_MIN', 8),
    'password_riwayat'  => (int) env('PASSWORD_RIWAYAT', 3),

    /*
    |----------------------------------------------------------------------
    | Verifikasi dua faktor (OTP sekali pakai lewat surat)
    |----------------------------------------------------------------------
    */

    'otp_detik'     => (int) env('OTP_DETIK', 300),
    'otp_maks_coba' => (int) env('OTP_MAKS_COBA', 5),
    'otp_panjang'   => (int) env('OTP_PANJANG', 6),
    'ingat_hari'    => (int) env('INGAT_HARI', 30),

    /*
    |----------------------------------------------------------------------
    | Identitas visual korporat PHR
    |----------------------------------------------------------------------
    |
    | WARNA-WARNA INI JANGAN DIUBAH. Merupakan identitas visual resmi
    | Pertamina Hulu Rokan dan dipakai konsisten di seluruh antarmuka,
    | templat surel, dan pesan OTP. Nilai diambil dari .env agar satu
    | sumber, tetapi nilai bawaan di sini adalah warna korporat yang benar.
    |
    | CATATAN: nilai di .env WAJIB dikutip (WARNA_BIRU_TUA="#003D7D").
    | Tanpa tanda kutip, karakter '#' dianggap komentar dan warna terbaca
    | kosong -- identitas visual hilang tanpa satu pun error.
    |
    */

    'warna_biru'     => env('WARNA_BIRU') ?: '#005EB8',
    'warna_biru_tua' => env('WARNA_BIRU_TUA') ?: '#003D7D',
    'warna_merah'    => env('WARNA_MERAH') ?: '#EB1C24',
    'warna_hijau'    => env('WARNA_HIJAU') ?: '#159447',
    'warna_bg'       => env('WARNA_BG') ?: '#F5F5F5',

    /* Nama organisasi & sebutan singkat aplikasi, dipakai pada kop surel
     * dan antarmuka. Disalin dari constants APP_ORGANISASI/APP_SINGKAT
     * sistem lama agar surel OTP tetap dikenali pengguna. */
    'organisasi'        => env('APP_ORGANISASI') ?: 'Pertamina Hulu Rokan',
    'aplikasi_singkat'  => env('APP_SINGKAT') ?: 'Pelaporan Harian',

    /*
    |----------------------------------------------------------------------
    | Token API Power BI
    |----------------------------------------------------------------------
    | Token statis untuk endpoint /api/v1/* (lihat routes/api.php).
    | Kosongkan untuk menonaktifkan API sepenuhnya.
    */
    'powerbi_token' => env('POWERBI_API_TOKEN', ''),

];
