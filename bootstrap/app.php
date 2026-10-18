<?php

use App\Http\Middleware\BatasSesi;
use App\Http\Middleware\CekPeran;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         |------------------------------------------------------------------
         | Grup web: sesi dikeraskan + batas waktu sesi
         |------------------------------------------------------------------
         |
         | BatasSesi menambahkan pemeriksaan idle (30 menit) dan usia mutlak
         | (8 jam) pada SETIAP rute web, melanjuti pola includes/session.php.
         | Ia mengecek Auth::check() lebih dulu, sehingga halaman publik
         | (login, register, lupa sandi) tidak pernah terpicu keluar -- tanpa
         | perlu daftar pengecualian berbasis nama berkas yang rapuh.
         |
         */
        $middleware->web(append: [
            BatasSesi::class,
        ]);

        /*
         |------------------------------------------------------------------
         | Alias penjaga peran
         |------------------------------------------------------------------
         |
         | Dipakai pada rute: ->middleware('role:mentor,admin')
         | Meniru pola wajib_role(['mentor','admin']) pada 40 halaman lama.
         | 'auth' harus didaftarkan lebih dulu pada rute agar pengguna yang
         | belum masuk diarahkan ke login, bukan langsung menerima 403.
         |
         */
        $middleware->alias([
            'role' => CekPeran::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         |------------------------------------------------------------------
         | Halaman 403 berbahasa Indonesia
         |------------------------------------------------------------------
         |
         | Sistem lama mengirim "Anda tidak memiliki akses ke halaman ini."
         | dengan http_response_code(403). Middleware CekPeran mengirim pesan
         | yang sama melalui abort(403, ...); pesan itu tampil di sini.
         |
         */
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return response()->view('errors.403', [
                'pesan' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Anda tidak memiliki akses ke halaman ini.',
            ], 403);
        });
    })->create();
