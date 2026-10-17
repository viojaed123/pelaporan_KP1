<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batas waktu sesi: keluar otomatis bila diam terlalu lama atau terlalu lama.
 *
 * Porting dari includes/session.php bagian "Batas waktu sesi". Sistem lama
 * memeriksa dua hal sekaligus pada SETIAP request:
 *
 *   1. Idle (diam)   : SESI_IDLE_DETIK  = 30 menit tanpa aktivitas.
 *   2. Usia mutlak   : SESI_ABSOLUT_DETIK = 8 jam sejak login, apa pun
 *                      aktivitasnya. Mencegah sesi yang dibiarkan terbuka
 *                      semalaman tetap sah keesokan harinya.
 *
 * Bila salah satu terlampaui, sesi dibersihkan dan pengguna diarahkan ke
 * halaman login dengan penanda ?alasan=sesi_habis -- persis perilaku lama,
 * sehingga halaman login dapat menampilkan pesan "Sesi Anda telah berakhir".
 *
 * PERBEDAAN IMPLEMENTASI (sengaja):
 *   Sistem lama mengecualikan halaman publik dengan MEMBACA NAMA BERKAS
 *   (basename($_SERVER['SCRIPT_NAME'])). Cara itu rapuh: menambah halaman
 *   publik berarti mengedit daftar. Di sini middleware ini HANYA berlaku pada
 *   rute yang sudah terautentikasi (dipasang pada grup auth), sehingga halaman
 *   login/register/reset tidak pernah memicu pengalihan -- tanpa daftar
 *   pengecualian yang harus dijaga.
 */
class BatasSesi
{
    /** Alasan yang dikirim ke halaman login agar pesan bisa ditampilkan. */
    public const ALASAN_SESI_HABIS = 'sesi_habis';

    /**
     * Tangani request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        /* Pengguna belum masuk: biarkan penjaga 'auth' yang menentukan.
         * Halaman publik tidak pernah terkena batas sesi di sini. */
        if (! Auth::check()) {
            return $next($request);
        }

        $sekarang = time();

        /*
         * Simpan/ambil penanda waktu. Kunci sesi ini sama persis dengan nama
         * yang dipakai sistem lama (_sesi_mulai, _sesi_terakhir) agar
         * perpindahan pengguna dari aplikasi lama ke Laravel tidak kehilangan
         * konteks -- sesi lama yang masih hidup tetap terbaca.
         */
        $mulai    = (int) $session->get('_sesi_mulai', $sekarang);
        $terakhir = (int) $session->get('_sesi_terakhir', $sekarang);

        $idle = $sekarang - $terakhir;
        $usia = $sekarang - $mulai;

        $batasIdle   = (int) config('keamanan.sesi_idle_detik');
        $batasAbsolut = (int) config('keamanan.sesi_absolut_detik');

        if ($idle > $batasIdle || $usia > $batasAbsolut) {
            /*
             * Sesi kedaluwarsa. flush() menghapus seluruh isi sesi, lalu
             * invalidate() menandai sesi untuk dibuang DAN meregenerasi id
             * sesi sehingga sesi lama tidak bisa dipakai ulang (mencegah
             * pengambilalihan sesi). Penghapusan cookie sesi sudah ditangani
             * StartSession; tidak perlu --withCookies() yang justru
             * membutuhkan argumen array pada Laravel.
             */
            $session->flush();
            $session->invalidate();

            return redirect()
                ->route('login', ['alasan' => self::ALASAN_SESI_HABIS]);
        }

        /*
         * Sesi masih sah: perbarui penanda aktivitas. Hanya dicatat ketika
         * ada perubahan nyata agar tidak menulis sesi (dan mengunci baris
         * penyimpanan sesi) pada setiap request.
         */
        if ($sekarang - $terakhir >= 1) {
            $session->put('_sesi_terakhir', $sekarang);
        }

        return $next($request);
    }
}
