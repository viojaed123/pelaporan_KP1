<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute Web
|--------------------------------------------------------------------------
|
| RUTE PENTING -- PEMBATAS STRANGLER-FIG
|--------------------------------------------------------------------------
| Aplikasi flat-PHP LAMA masih menjadi layanan utama dan diakses lewat
| DocumentRoot berbeda (localhost/pelaporation/). Aplikasi Laravel ini
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
| Rute login dinamai 'login' karena middleware (BatasSesi, CekPeran) dan
| penjaga bawaan Laravel 'auth' merujuknya untuk mengalihkan pengguna yang
| belum masuk atau sesi yang habis.
|
*/

Route::get('/login', [AuthController::class, 'tampilkanLogin'])->name('login');
