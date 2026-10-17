<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KeamananLogin;
use App\Services\KodeCadangan;
use App\Services\Otp;
use App\Services\PendaftaranSesi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Alur otentikasi: masuk, verifikasi dua faktor, dan keluar.
 *
 * Porting dari login.php, dua_faktor.php, logout.php, dan login_tuntaskan()
 * (includes/auth.php) sistem lama.
 *
 * TIGA PRINSIP YANG DIPERTAHANKAN DARI SISTEM LAMA:
 *
 *   1. Pesan kesalahan TIDAK membedakan "surel tidak terdaftar" dari "sandi
 *      salah". Keduanya berbunyi sama, sehingga penyerang tidak bisa memakai
 *      halaman ini untuk memetakan surel mana yang terdaftar.
 *
 *   2. Waktu respons DISAMAKAN. Saat surel tidak ditemukan, tetap dilakukan
 *      satu pemeriksaan bcrypt terhadap hash dummy. Tanpa ini, permintaan
 *      untuk surel yang tidak ada akan kembali jauh lebih cepat dan tetap
 *      membocorkan hal yang sama lewat selisih waktu.
 *
 *   3. 2FA TIDAK membuat sesi penuh lebih dulu. Sebelum kode terbukti benar,
 *      pengguna hanya mendapat penanda sementara (tfa_uid) -- bukan login.
 */
class AuthController extends Controller
{
    /**
     * Hash bcrypt dummy (biaya 12, mengikuti konfigurasi hashing saat ini).
     *
     * Dipakai HANYA untuk menyamakan waktu saat surel tidak ditemukan.
     * Biayanya disamakan dengan konfigurasi aplikasi agar waktu respons
     * "akun tidak ditemukan" dan "akun ditemukan tetapi sandi salah"
     * praktis identik.
     */
    private const HASH_DUMMY = '$2y$12$Zk8nQaBq..XXdZWq/vI5zO0Fb3lUBseQafJxFChyIdUYdEXtKSvQG';

    /** Batas pengiriman ulang kode 2FA per sesi (porting dua_faktor.php). */
    private const MAKS_KIRIM_ULANG = 3;

    public function __construct(
        private readonly KeamananLogin $keamanan,
        private readonly PendaftaranSesi $perangkat,
        private readonly Otp $otp,
        private readonly KodeCadangan $cadangan,
        private readonly AuditLogger $audit,
    ) {
    }

    /*
    |----------------------------------------------------------------------
    | Halaman masuk
    |----------------------------------------------------------------------
    */

    /**
     * Tampilkan halaman login.
     */
    public function tampilkanLogin(Request $request): View|RedirectResponse
    {
        /*
         * Sudah masuk? Jangan tampilkan formulir lagi -- kirim ke halaman
         * peran. Mencegah "jendela login" muncul bagi pengguna yang sesinya
         * masih sah (mis. menekan tombol kembali).
         */
        if (Auth::check()) {
            return redirect()->route(Auth::user()->halamanDefault());
        }

        return view('auth.login', [
            'alasan' => (string) $request->query('alasan', ''),
            'email'  => (string) old('email', ''),
        ]);
    }

    /**
     * Proses permintaan masuk.
     */
    public function masuk(Request $request): RedirectResponse
    {
        /*
         * Aturan 'email' bawaan Laravel memvalidasi format. Pesan kesalahannya
         * sudah diterjemahkan di lang/id/validation.php, sehingga konsisten
         * berbahasa Indonesia tanpa teks manual di sini.
         */
        $data = $request->validate([
            'email'    => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim((string) $data['email']));
        $sandi = (string) $data['password'];
        $ip    = (string) ($request->ip() ?? '0.0.0.0');

        /* 1) Penguncian akibat percobaan berulang. Diperiksa SEBELUM menyentuh
         *    basis data pengguna, sehingga serangan tidak membebani query. */
        $kunci = $this->keamanan->cekTerkunci($ip, $email);

        if ($kunci['terkunci']) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $kunci['pesan']]);
        }

        /* 2) Cari akun. */
        $user = User::where('email', $email)->first();

        $sandiSah = false;

        if ($user !== null) {
            $sandiSah = Hash::check($sandi, (string) $user->password);

            /*
             * Sandi lama ber-hash lemah (atau berbiaya lebih rendah) dinaikkan
             * ke standar sekarang. Cast 'hashed' pada model otomatis
             * menghasilkan hash baru saat properti diset.
             */
            if ($sandiSah && Hash::needsRehash((string) $user->password)) {
                $user->password = $sandi;
                $user->save();
            }
        } else {
            /* 3) Samakan waktu respons agar keberadaan surel tidak bocor. */
            Hash::check($sandi, self::HASH_DUMMY);
        }

        if ($user === null || ! $sandiSah) {
            return $this->tanganiGagalLogin($request, $ip, $email, $user);
        }

        /* 4) Kredensial benar -- tetapi status akun menentukan langkah lanjut. */
        $statusAkun = (string) ($user->status ?? 'aktif');

        if ($statusAkun !== 'aktif') {
            return $statusAkun === 'menunggu'
                ? $this->tanganiAkunMenunggu($request, $user)
                : back()
                    ->withInput($request->only('email'))
                    ->withErrors([
                        'email' => 'Akun Anda sedang dinonaktifkan. Hubungi administrator.',
                    ]);
        }

        /* 5) Bila 2FA aktif, jangan buat sesi penuh dulu. */
        if ($user->duaFaktorAktif()) {
            $this->siapkanDuaFaktor($request, $user);

            return redirect()->route('dua.faktor');
        }

        /* 6) Tanpa 2FA: selesaikan. */
        return $this->tuntaskanLogin($request, $user, 'password');
    }

    /*
    |----------------------------------------------------------------------
    | Verifikasi dua faktor
    |----------------------------------------------------------------------
    */

    /**
     * Tampilkan halaman verifikasi dua faktor.
     */
    public function tampilkanDuaFaktor(Request $request): View|RedirectResponse
    {
        $uid   = (int) $request->session()->get('tfa_uid', 0);
        $email = (string) $request->session()->get('tfa_email', '');

        /*
         * Sesi sementara 2FA tidak ada: pengguna membuka halaman ini langsung
         * (atau sesinya habis). Kembalikan ke halaman masuk.
         */
        if ($uid < 1 || $email === '') {
            return redirect()->route('login');
        }

        if (User::find($uid) === null) {
            $request->session()->forget(['tfa_uid', 'tfa_email', 'tfa_kirim', 'tfa_kirim_ulang']);

            return redirect()->route('login', ['alasan' => 'kadaluarsa']);
        }

        return view('auth.dua-faktor', [
            'email'       => $email,
            'sisaKirim'   => max(
                0,
                self::MAKS_KIRIM_ULANG - (int) $request->session()->get('tfa_kirim_ulang', 0)
            ),
            'menitBerlaku' => (int) ceil(((int) config('keamanan.otp_detik')) / 60),
        ]);
    }

    /**
     * Verifikasi kode OTP (atau kode cadangan) dan selesaikan login.
     */
    public function verifikasiDuaFaktor(Request $request): RedirectResponse
    {
        $uid   = (int) $request->session()->get('tfa_uid', 0);
        $email = (string) $request->session()->get('tfa_email', '');

        if ($uid < 1 || $email === '') {
            return redirect()->route('login');
        }

        $user = User::find($uid);

        if ($user === null) {
            $request->session()->forget(['tfa_uid', 'tfa_email', 'tfa_kirim', 'tfa_kirim_ulang']);

            return redirect()->route('login', ['alasan' => 'kadaluarsa']);
        }

        $kode = trim((string) $request->input('kode', ''));

        if ($kode === '') {
            return back()->withErrors(['kode' => 'Masukkan kode verifikasi.']);
        }

        $hasil = $this->otp->verifikasi($uid, $kode, Otp::JENIS_LOGIN);

        /*
         * Bila bukan kode OTP, coba sebagai kode cadangan. Formatnya
         * XXXXXX-XXXX (ada tanda hubung); panjang 11 diperiksa lebih dulu
         * agar tidak melakukan pemeriksaan bcrypt untuk masukan yang jelas
         * bukan kode cadangan.
         */
        if (! $hasil['ok'] && strlen($kode) === 11 && str_contains($kode, '-')) {
            if ($this->cadangan->pakai($uid, $kode)) {
                $hasil = ['ok' => true, 'pesan' => 'Kode cadangan diterima.', 'sisa' => null];
            }
        }

        if (! $hasil['ok']) {
            $this->audit->catat('login_2fa_gagal', 'users', $uid, 'Verifikasi dua faktor gagal');

            return back()->withErrors(['kode' => $hasil['pesan']]);
        }

        $this->audit->catat('login_2fa_berhasil', 'users', $uid, 'Verifikasi dua faktor berhasil');

        return $this->tuntaskanLogin($request, $user, '2fa');
    }

    /**
     * Kirim ulang kode OTP dua faktor.
     */
    public function kirimUlangDuaFaktor(Request $request): RedirectResponse
    {
        $uid   = (int) $request->session()->get('tfa_uid', 0);
        $email = (string) $request->session()->get('tfa_email', '');

        if ($uid < 1 || $email === '') {
            return redirect()->route('login');
        }

        $user = User::find($uid);

        if ($user === null) {
            $request->session()->forget(['tfa_uid', 'tfa_email', 'tfa_kirim', 'tfa_kirim_ulang']);

            return redirect()->route('login', ['alasan' => 'kadaluarsa']);
        }

        $sudah = (int) $request->session()->get('tfa_kirim_ulang', 0);

        if ($sudah >= self::MAKS_KIRIM_ULANG) {
            return back()->withErrors([
                'kode' => 'Batas pengiriman ulang tercapai. Gunakan kode cadangan atau hubungi admin.',
            ]);
        }

        try {
            $kode  = $this->otp->buat($uid, $email, Otp::JENIS_LOGIN);
            $kirim = $this->otp->kirim($email, $kode, (string) $user->nama);

            $request->session()->put('tfa_kirim_ulang', $sudah + 1);
            $request->session()->put('tfa_kirim', $kirim['ok'] ? 1 : 0);

            if (! $kirim['ok']) {
                return back()->withErrors(['kode' => 'Gagal mengirim kode: ' . $kirim['galat']]);
            }
        } catch (\Throwable $t) {
            report($t);

            return back()->withErrors(['kode' => 'Gagal menyiapkan kode. Coba lagi sebentar lagi.']);
        }

        return back()->with('info', 'Kode baru telah dikirim ke ' . $this->potongSurel($email) . '.');
    }

    /*
    |----------------------------------------------------------------------
    | Keluar
    |----------------------------------------------------------------------
    */

    /**
     * Keluar dari sesi sekarang.
     */
    public function keluar(Request $request): RedirectResponse
    {
        $idUser = Auth::id();

        if ($idUser !== null) {
            $this->audit->catat('keluar', 'users', (int) $idUser, 'Keluar dari sistem');
        }

        Auth::logout();

        /*
         * flush() + invalidate() + regenerateToken(): sesi benar-benar
         * dibuang, bukan sekadar penanda login dihapus. Tanpa token CSRF baru
         * setelah logout, halaman berikutnya bisa memakai token lama.
         */
        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login', ['alasan' => 'keluar']);
    }

    /*
    |----------------------------------------------------------------------
    | Bagian dalam
    |----------------------------------------------------------------------
    */

    /**
     * Catat kegagalan, lalu susun pesan yang TIDAK membocorkan keberadaan akun.
     */
    private function tanganiGagalLogin(Request $request, string $ip, string $email, ?User $user): RedirectResponse
    {
        $this->keamanan->catatGagal($ip, $email, $user !== null ? (int) $user->id : null);

        $gagal = $this->keamanan->hitungGagal($ip, $email);
        $maks  = (int) config('keamanan.login_maks_gagal');
        $sisa  = $maks - $gagal;

        /*
         * Sisa percobaan HANYA ditampilkan setelah beberapa kegagalan. Bila
         * ditampilkan sejak awal, penyerang dapat memakai hitungan ini untuk
         * memastikan percobaannya tercatat -- dan pengguna yang sekadar salah
         * ketik sekali tidak perlu diancam.
         */
        if ($sisa <= 0) {
            $pesan = 'Terlalu banyak percobaan gagal. Akun sementara dikunci.';
        } elseif ($gagal >= 3) {
            $pesan = 'Email atau kata sandi tidak sesuai. Sisa percobaan sebelum terkunci: ' . $sisa . '.';
        } else {
            $pesan = 'Email atau kata sandi tidak sesuai.';
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => $pesan]);
    }

    /**
     * Kredensial benar tetapi surel belum diverifikasi.
     */
    private function tanganiAkunMenunggu(Request $request, User $user): RedirectResponse
    {
        $request->session()->put('verifikasi_email_tujuan', (string) $user->email);

        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'email' => 'Akun Anda belum diverifikasi. Buka tautan verifikasi di surel Anda, '
                    . 'atau minta tautan baru di halaman Verifikasi Surel.',
            ]);
    }

    /**
     * Siapkan sesi sementara 2FA dan kirim kode pertama.
     *
     * TIDAK memanggil Auth::login(): pengguna belum benar-benar masuk sampai
     * kode terbukti benar. Yang disimpan hanya penanda sementara.
     */
    private function siapkanDuaFaktor(Request $request, User $user): void
    {
        $request->session()->regenerate();

        $sesi = $request->session();
        $sesi->put('tfa_uid', (int) $user->id);
        $sesi->put('tfa_email', (string) $user->email);
        $sesi->put('tfa_kirim', 0);
        $sesi->put('tfa_kirim_ulang', 0);

        try {
            $kode  = $this->otp->buat((int) $user->id, (string) $user->email, Otp::JENIS_LOGIN);
            $kirim = $this->otp->kirim((string) $user->email, $kode, (string) $user->nama);

            $sesi->put('tfa_kirim', $kirim['ok'] ? 1 : 0);

            if (! $kirim['ok']) {
                report(new \RuntimeException('OTP gagal dikirim: ' . $kirim['galat']));
            }
        } catch (\Throwable $t) {
            /* Kegagalan pengiriman tidak boleh menjatuhkan halaman; pengguna
             * masih punya tombol "kirim ulang" dan kode cadangan. */
            report($t);
        }

        $this->audit->catat(
            'login_2fa_diminta',
            'users',
            (int) $user->id,
            'Permintaan kode 2FA'
        );
    }

    /**
     * Selesaikan login: regenerasi sesi, isi sesi, catat audit, daftar
     * perangkat, lalu arahkan ke halaman sesuai peran.
     */
    private function tuntaskanLogin(Request $request, User $user, string $metode): RedirectResponse
    {
        /*
         * Regenerasi id sesi SEBELUM menandai masuk: mencegah session fixation
         * (id sesi lama yang diketahui penyerang menjadi sah setelah login).
         * regenerate() mempertahankan isi sesi, jadi penanda tfa_* masih bisa
         * dibersihkan setelahnya.
         */
        $request->session()->regenerate();

        Auth::login($user);

        $sesi = $request->session();
        $sesi->put('_sesi_mulai', time());
        $sesi->put('_sesi_terakhir', time());
        $sesi->forget(['tfa_uid', 'tfa_email', 'tfa_kirim', 'tfa_kirim_ulang']);
        $sesi->forget('verifikasi_email_tujuan');

        $id = (int) $user->id;

        $this->perangkat->pastikanKeamananPengguna($id);
        $this->keamanan->catatSukses(
            (string) ($request->ip() ?? '0.0.0.0'),
            (string) $user->email,
            $id
        );
        $this->perangkat->daftar($id, (string) ($request->ip() ?? '0.0.0.0'), $request->userAgent());

        $this->audit->catat(
            'login',
            'users',
            $id,
            'Masuk sebagai ' . $user->role . ' via ' . $metode,
            null,
            ['metode' => $metode, 'role' => $user->role]
        );

        return redirect()->intended(route($user->halamanDefault()));
    }

    /**
     * Pangkas surel untuk ditampilkan (mis. "nam***@contoh.com").
     *
     * Porting potong() sistem lama: dipakai agar halaman tidak menampilkan
     * alamat lengkap di tempat yang bisa dilihat orang lain.
     */
    private function potongSurel(string $email): string
    {
        $email = trim($email);

        if (strlen($email) <= 30) {
            return $email;
        }

        return substr($email, 0, 27) . '...';
    }
}
