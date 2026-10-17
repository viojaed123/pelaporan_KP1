<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Kontroler otentikasi.
 *
 * CATATAN STATUS -- belum lengkap:
 *   Metode tampilkanLogin() saat ini hanya merender kerangka halaman login
 *   agar rute 'login' (dirujuk middleware BatasSesi dan CekPeran) sah dan
 *   tidak membuat RouteNotFoundException selama migrasi berjalan.
 *
 *   Proses login SEBENARNYA (verifikasi kata sandi, pembatas laju login,
 *   penguncian akun, 2FA OTP, dan penetapan sesi) akan dipindahkan pada
 *   fase modul autentikasi. Saat itu metode ini diganti dengan alur penuh
 *   yang memanggil KeamananLoginService dan guard bawaan Laravel.
 */
class AuthController extends Controller
{
    /**
     * Tampilkan halaman login.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function tampilkanLogin(Request $request)
    {
        /*
         * Bila pengguna sudah terautentikasi, jangan tampilkan login lagi --
         * kirim ke beranda. Mencegah "jendela login" tampil kepada pengguna
         * yang sesinya masih sah (mis. menekan tombol kembali).
         */
        if (Auth::check()) {
            return redirect()->route('beranda');
        }

        /*
         * Alasan pengalihan, dipakai halaman login untuk menampilkan pesan
         * yang akurat: 'sesi_habis' (dihasilkan middleware BatasSesi) atau
         * nilai lain yang mungkin ditambahkan rute.
         */
        $alasan = (string) $request->query('alasan', '');

        return view('auth.login', [
            'alasan' => $alasan,
        ]);
    }
}
