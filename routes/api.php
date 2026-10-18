<?php

declare(strict_types=1);

use App\Http\Controllers\Api\LaporanApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute API (Power BI / external BI)
|--------------------------------------------------------------------------
|
| Endpoint JSON untuk konektor "Web" Power BI (atau tools BI lain).
| Autentikasi token statis via header X-Api-Token / Bearer, atau ?token=.
| Token diatur di .env: POWERBI_API_TOKEN.
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/laporan', [LaporanApiController::class, 'laporan']);
    Route::get('/absensi', [LaporanApiController::class, 'absensi']);
    Route::get('/rekap/harian', [LaporanApiController::class, 'rekapHarian']);
    Route::get('/rekap/pengguna', [LaporanApiController::class, 'rekapPengguna']);
});
