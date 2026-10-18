<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Penjadwalan (Laravel Scheduler)
|--------------------------------------------------------------------------
|
| Dijalankan oleh satu tugas sistem tiap menit (Windows Task Scheduler):
|
|   php C:\xampp\htdocs\pelaporan\artisan schedule:run
|
| Lihat docs/GO_LIVE.md bagian "Penjadwal & antrean" dan
| scripts/jalankan_penjadwal.cmd untuk pemasangan.
|
*/

/* Pengingat laporan harian: karyawan yang belum mengisi laporan pada
   hari berjalan diberi notifikasi. Idempoten (aman dijalankan ulang). */
Schedule::command('pengingat:harian')
    ->dailyAt('08:00')
    ->withoutOverlapping();
