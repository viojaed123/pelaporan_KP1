<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute Web
|--------------------------------------------------------------------------
|
| RUTE PENTING -- PEMBATAS STRANGLER-FIG
|--------------------------------------------------------------------------
| Aplikasi flat-PHP LAMA masih menjadi layanan utama dan diakses lewat
| DocumentRoot berbeda (localhost/pelaporan/). Aplikasi Laravel ini
| diakses melalui VirtualHost khusus (192.168.56.1 / pelaporan.local).
|
| Selama migrasi, setiap modul dipindahkan satu per satu: rute Laravel di
| bawah menambah cakupan, sementara halaman lama yang belum dipindah tetap
| dilayani aplikasi lama. Keduanya berbagi database yang sama, sehingga data
| konsisten di kedua sisi.
|
*/

/*
|--------------------------------------------------------------------------
| Halaman publik (tanpa autentikasi)
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
})->name('beranda');

/*
|--------------------------------------------------------------------------
| Autentikasi
|--------------------------------------------------------------------------
|
| Rute GET /login dinamai 'login' karena middleware (BatasSesi, CekPeran)
| dan penjaga bawaan Laravel 'auth' merujuknya untuk mengalihkan pengguna
| yang belum masuk atau sesi yang habis.
|
| Rute POST dipisah namanya agar `route('login')` tetap menghasilkan URL
| halaman (GET), bukan aksi pengiriman formulir.
|
*/

Route::get('/login', [AuthController::class, 'tampilkanLogin'])->name('login');
Route::post('/login', [AuthController::class, 'masuk'])->name('login.masuk');

/*
| Verifikasi dua faktor. Halaman ini dapat diakses TANPA 'auth' karena
| sesi penuh memang belum dibuat pada tahap ini -- pengguna baru memiliki
| penanda sementara (tfa_uid). Penjaga sebenarnya adalah keberadaan penanda
| itu sendiri, yang diperiksa di dalam kontroler.
*/
Route::get('/dua-faktor', [AuthController::class, 'tampilkanDuaFaktor'])->name('dua.faktor');
Route::post('/dua-faktor', [AuthController::class, 'verifikasiDuaFaktor'])->name('dua.faktor.verifikasi');
Route::post('/dua-faktor/kirim-ulang', [AuthController::class, 'kirimUlangDuaFaktor'])->name('dua.faktor.kirim-ulang');

/*
|--------------------------------------------------------------------------
| Halaman terautentikasi
|--------------------------------------------------------------------------
|
| BatasSesi sudah dipasang pada grup web (bootstrap/app.php), sehingga
| pemeriksaan idle & usia sesi berlaku otomatis pada semua rute di bawah.
|
| Penjaga peran memakai middleware 'role:' (alias CekPeran). 'auth'
| didaftarkan lebih dulu agar pengguna yang belum masuk diarahkan ke login,
| bukan langsung menerima 403.
|
*/

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [AuthController::class, 'keluar'])->name('keluar');

    /*
     * Halaman utama per peran. Ketiganya masih menampilkan tampilan sambutan
     * yang sama sampai fase modul dashboard; penamaan rutenya sudah final
     * sehingga pengalihan setelah login tidak perlu diubah lagi nanti.
     */
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/mentor', [DashboardController::class, 'index'])
        ->middleware('role:mentor,admin')
        ->name('mentor.dashboard');
    Route::get('/admin', [DashboardController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.dashboard');
        
    // Modul Laporan (migrasi bertahap)
    Route::get('/laporan/buat', [App\Http\Controllers\LaporanController::class, 'buat'])->name('laporan.buat');
    Route::get('/laporan/riwayat', [App\Http\Controllers\LaporanController::class, 'riwayat'])->name('laporan.riwayat');
    Route::get('/absensi', [App\Http\Controllers\AbsensiController::class, 'index'])->name('absensi.index');
    Route::get('/notifikasi', [App\Http\Controllers\NotifikasiController::class, 'index'])->name('notifikasi.index');
    Route::get('/profil', [App\Http\Controllers\ProfilController::class, 'index'])->name('profil.index');
    Route::post('/profil/ganti-password', [App\Http\Controllers\ProfilController::class, 'gantiPassword'])->name('profil.gantiPassword');
    Route::get('/ringkasan', [App\Http\Controllers\RingkasanController::class, 'index'])->name('ringkasan.index');
    Route::get('/kalender', [App\Http\Controllers\KalenderController::class, 'index'])->name('kalender.index');
    Route::get('/kasus', [App\Http\Controllers\KasusController::class, 'index'])->name('kasus.index');

    Route::get('/rekap/laporan', [App\Http\Controllers\RekapController::class, 'laporan'])->name('rekap.laporan');
    Route::get('/rekap/absensi', [App\Http\Controllers\RekapController::class, 'absensi'])->name('rekap.absensi');
    Route::get('/asisten', [App\Http\Controllers\AsistenController::class, 'index'])->name('asisten.index');
    Route::post('/asisten/kirim', [App\Http\Controllers\AsistenController::class, 'kirim'])->name('asisten.kirim');

    Route::get('/impor', [App\Http\Controllers\ImporController::class, 'index'])->name('impor.index');
    Route::post('/impor/unggah', [App\Http\Controllers\ImporController::class, 'unggah'])->name('impor.unggah');

    Route::get('/impor/pemetaan', [App\Http\Controllers\ImporController::class, 'pemetaan'])->name('impor.pemetaan');

    Route::post('/impor/pratinjau', [App\Http\Controllers\ImporController::class, 'pratinjau'])->name('impor.pratinjau');

    Route::post('/impor/simpan', [App\Http\Controllers\ImporController::class, 'simpan'])->name('impor.simpan');
    Route::post('/impor/profil', [App\Http\Controllers\ImporController::class, 'simpanProfil'])->name('impor.profil.simpan');


    // Modul Penugasan Tugas (mentor/admin -> karyawan)
    Route::get('/tugas', [App\Http\Controllers\TugasController::class, 'index'])->name('tugas.index');
    Route::get('/tugas/buat', [App\Http\Controllers\TugasController::class, 'buat'])->middleware('role:mentor,admin')->name('tugas.buat');
    Route::post('/tugas/simpan', [App\Http\Controllers\TugasController::class, 'simpan'])->middleware('role:mentor,admin')->name('tugas.simpan');
    Route::post('/tugas/{id}/status', [App\Http\Controllers\TugasController::class, 'perbaruiStatus'])->name('tugas.status');
});

/*
|--------------------------------------------------------------------------
| Cangkang SPA React (/app)
|--------------------------------------------------------------------------
| Seluruh antarmuka pengguna berbasis token (web React) dirender di sini.
| Autentikasi TIDAK memakai sesi web melainkan token API (Sanctum) yang
| dikelola React -- sama seperti APK mobile, sehingga satu set endpoint
| /api/v1/* melayani kedua klien. Rute terbuka; penjagaan ada di dalam
| aplikasi (lihat resources/js/react/App.jsx).
*/
Route::view('/app', 'app')->name('spa');
Route::view('/app/{any}', 'app')->where('any', '.*')->name('spa.any');
