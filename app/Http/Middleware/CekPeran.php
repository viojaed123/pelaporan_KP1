<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga peran: hanya izinkan peran tertentu, selain wajib sudah masuk.
 *
 * Porting dari includes/auth.php bagian wajib_role(). Sistem lama dipanggil
 * di puncak setiap halaman yang terlindung:
 *
 *   wajib_role(['mentor', 'admin']);   // 403 bila bukan peran tersebut
 *
 * Di Laravel, pola itu menjadi penjaga rute dengan parameter:
 *
 *   Route::get(...)->middleware('auth')->middleware('role:mentor,admin');
 *
 * ATAU digabung satu penjaga bawaan 'auth' lalu 'role' (urutan penting:
 * 'auth' harus lebih dulu agar pengguna tak masuk diperiksa perannya).
 *
 * PERBEDAAN IMPLEMENTASI (sengaja dan lebih aman):
 *   Sistem lama perlu MENANYA ULANG peran ke database untuk akses tulis,
 *   karena nilai peran disimpan di $_SESSION yang bisa basi setelah admin
 *   mengubah peran. Di Laravel, objek User dimuat dari database pada SETIAP
 *   request lewat guard sesi, sehingga peran yang baru saja diubah admin
 *   langsung terbaca tanpa query tambahan. Tidak ada cache peran yang perlu
 *   disinkronkan.
 */
class CekPeran
{
    /**
     * Tangani request.
     *
     * PENTING -- parameter variadik (...$peran):
     *   Laravel MEMECAH parameter middleware yang dipisah koma menjadi
     *   argumen TERPISAH. Jadi middleware('role:mentor,admin') memanggil
     *   handle($request, $next, 'mentor', 'admin') -- BUKAN dengan satu
     *   string 'mentor,admin'. Tanda tangan yang hanya menerima satu
     *   string akan diam-diam mengabaikan seluruh peran setelah yang
     *   pertama, sehingga admin tidak pernah bisa mengakses halaman yang
     *   hanya mencantumkan mentor lebih dulu. Itulah sebabnya di sini
     *   memakai ...$peran.
     *
     * @param  string  ...$peran  Peran yang diizinkan, satu per argumen.
     */
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        /*
         * Bila pengguna belum terautentikasi, serahkan pada penjaga 'auth'
         * (yang harus dipasang lebih dulu). Jika 'auth' terlewat, tetap
         * aman: tolak, bukan izinkan.
         */
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        /*
         * Buang spasi berlebih dan entri kosong agar 'role:mentor, admin'
         * (dengan spasi setelah koma) tetap bekerja sama seperti
         * 'role:mentor,admin'.
         */
        $daftarPeran = array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            $peran
        )));

        /*
         * Metode punyaPeran() ada pada model User dan membandingkan role
         * secara ketat (strict). Dengan begitu peran yang tidak dikenal pada
         * database (nilai enum di luar admin/karyawan/mentor) tidak akan pernah
         * cocok secara tidak sengaja.
         */
        if ($daftarPeran === [] || ! $user->punyaPeran($daftarPeran)) {
            /*
             * Pesan 403 disamakan dengan sistem lama
             * ("Anda tidak memiliki akses ke halaman ini.") agar pengguna
             * lama mengenali hal yang sama.
             */
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
