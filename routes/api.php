<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AbsensiController;
use App\Http\Controllers\Api\AnalitikController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CostAvoidanceController;
use App\Http\Controllers\Api\EksporController;
use App\Http\Controllers\Api\KasusController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\LaporanApiController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\RingkasanController;
use App\Http\Controllers\Api\TugasController;
use App\Http\Controllers\AsistenController;
use Illuminate\Support\Facades\Route;



Route::prefix('v1')->group(function () {
    /* --- 1. Power BI (token statis) --- */
    Route::get('/laporan', [LaporanApiController::class, 'laporan']);
    Route::get('/absensi', [LaporanApiController::class, 'absensi']);
    Route::get('/rekap/harian', [LaporanApiController::class, 'rekapHarian']);
    Route::get('/rekap/pengguna', [LaporanApiController::class, 'rekapPengguna']);
    /* Kasus memakai awalan /bi/ agar tidak bentrok dengan rute
       Sanctum GET /kasus (daftar kasus untuk klien mobile). */
    Route::get('/bi/kasus', [LaporanApiController::class, 'kasus']);

    /* --- 2. Aplikasi mobile (token Sanctum per pengguna) --- */

    /* Autentikasi: terbuka (dilindungi penguncian login internal). */
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('/auth/verifikasi', [AuthController::class, 'verifikasi'])
        ->middleware('throttle:10,1');

    /* Terproteksi: butuh `Authorization: Bearer <token>`. */
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/profil', [AuthController::class, 'profil']);
        Route::post('/auth/ganti-password', [AuthController::class, 'gantiPassword']);

        Route::get('/ringkasan', [RingkasanController::class, 'index']);

        Route::get('/laporan/riwayat', [LaporanController::class, 'riwayat']);
        Route::post('/laporan', [LaporanController::class, 'simpan']);

        Route::get('/absensi/riwayat', [AbsensiController::class, 'riwayat']);
        Route::post('/absensi/check-in', [AbsensiController::class, 'checkIn']);
        Route::post('/absensi/check-out', [AbsensiController::class, 'checkOut']);

        Route::get('/tugas', [TugasController::class, 'daftar']);
        Route::patch('/tugas/{id}/status', [TugasController::class, 'perbaruiStatus'])
            ->whereNumber('id');

        Route::get('/kasus', [KasusController::class, 'daftar']);
        Route::post('/kasus', [KasusController::class, 'simpan'])
            ->middleware('role:mentor,admin');
        Route::get('/kasus/{id}', [KasusController::class, 'detail'])
            ->whereNumber('id');
        Route::put('/kasus/{id}', [KasusController::class, 'perbarui'])
            ->whereNumber('id');

        Route::get('/cost-avoidance/ringkasan', [CostAvoidanceController::class, 'ringkasan']);
        Route::get('/cost-avoidance', [CostAvoidanceController::class, 'daftar']);
        Route::post('/cost-avoidance', [CostAvoidanceController::class, 'simpan']);
        Route::get('/cost-avoidance/{id}', [CostAvoidanceController::class, 'detail'])
            ->whereNumber('id');
        Route::put('/cost-avoidance/{id}', [CostAvoidanceController::class, 'perbarui'])
            ->whereNumber('id');
        Route::delete('/cost-avoidance/{id}', [CostAvoidanceController::class, 'hapus'])
            ->whereNumber('id');
        Route::post('/cost-avoidance/{id}/ajukan', [CostAvoidanceController::class, 'ajukan'])
            ->whereNumber('id');
        Route::post('/cost-avoidance/{id}/verifikasi', [CostAvoidanceController::class, 'verifikasi'])
            ->whereNumber('id')
            ->middleware('role:mentor,admin');

        Route::get('/notifikasi', [NotifikasiController::class, 'daftar']);
        Route::get('/notifikasi/jumlah', [NotifikasiController::class, 'jumlah']);
        Route::post('/notifikasi/{id}/dibaca', [NotifikasiController::class, 'tandaiDibaca'])
            ->whereNumber('id');
        Route::post('/notifikasi/dibaca-semua', [NotifikasiController::class, 'tandaiSemuaDibaca']);

        /* Asisten AI: memakai logika yang sama dengan halaman web. */
        Route::post('/asisten/kirim', [AsistenController::class, 'kirim'])
            ->middleware('throttle:20,1');

        /* Dasbor: kartu + tren + donut; penskalaan peran di controller. */
        Route::get('/dasbor', [AnalitikController::class, 'dasbor']);

        /* Analitik (porting rekap/ringkasan/kalender).
           Rekap menyeluruh khusus mentor/admin; ringkasan & kalender
           dapat diakses semua pengguna (penskalaan di dalam controller). */
        Route::middleware('role:mentor,admin')->group(function () {
            Route::get('/rekap/laporan', [AnalitikController::class, 'rekapLaporan']);
            Route::get('/rekap/absensi', [AnalitikController::class, 'rekapAbsensi']);
            Route::get('/rekap/kasus', [AnalitikController::class, 'rekapKasus']);

            /* Ekspor .xlsx nyata (phpspreadsheet) untuk halaman rekap. */
            Route::get('/ekspor/laporan', [EksporController::class, 'laporan']);
            Route::get('/ekspor/absensi', [EksporController::class, 'absensi']);
            Route::get('/ekspor/kasus', [EksporController::class, 'kasus']);
        });

        Route::get('/ringkasan-kegiatan', [AnalitikController::class, 'ringkasan']);
        Route::get('/kalender', [AnalitikController::class, 'kalender']);
        Route::get('/analitik/srm', [AnalitikController::class, 'analitikSrm']);
        Route::get('/analitik/cid', [AnalitikController::class, 'analitikCid']);
    });
});
