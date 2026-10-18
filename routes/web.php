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
});
