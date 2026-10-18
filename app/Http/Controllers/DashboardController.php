<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman utama setelah masuk.
 *
 * CATATAN STATUS: ini PENGGANTI SEMENTARA untuk dashboard.php,
 * mentor_dashboard.php, dan admin_dashboard.php sistem lama. Ketiganya belum
 * dipindahkan; untuk saat ini semuanya menampilkan satu halaman sambutan yang
 * benar secara data (nama, peran, sesi) sehingga alur login dapat diuji
 * menyeluruh. Isi khas tiap peran akan menggantikan halaman ini pada fase
 * modul dashboard.
 */
class DashboardController extends Controller
{
    /**
     * Tampilkan halaman utama.
     */
    public function index(Request $request): View
    {
        $pengguna = $request->user();

        $sisaIdle = max(
            0,
            (int) config('keamanan.sesi_idle_detik')
                - (time() - (int) $request->session()->get('_sesi_terakhir', time()))
        );
        $sisaUsia = max(
            0,
            (int) config('keamanan.sesi_absolut_detik')
                - (time() - (int) $request->session()->get('_sesi_mulai', time()))
        );

        $role = $pengguna?->role ?? $request->session()->get('role') ?? 'karyawan';
        $view = match ($role) {
            'admin'  => 'dashboard.admin',
            'mentor' => 'dashboard.mentor',
            default  => 'dashboard.index',
        };

        return view($view, [
            'pengguna' => $pengguna,
            'sisaIdle' => $sisaIdle,
            'sisaUsia' => $sisaUsia,
            'menuAktif' => 'dashboard',
        ]);
    }
}
