<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KeamananLogin;
use App\Services\Otp;
use App\Services\RiwayatSandi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Autentikasi API untuk aplikasi mobile (APK) & klien token lain.
 *
 * Alur masuk mengikuti peraturan keamanan yang SAMA dengan web:
 *   - penguncian ip+email (KeamananLogin) setelah percobaan gagal;
 *   - akun menunggu/dinonaktifkan ditolak;
 *   - bila 2FA aktif, API tidak langsung mengeluarkan token -- klien
 *     menerima `ticket` sementara lalu meminta OTP (alur dua langkah).
 *
 * Token dikelola Laravel Sanctum (`personal_access_tokens`); klien
 * mengirimkannya sebagai `Authorization: Bearer <token>`.
 */
class AuthController extends Controller
{
    /** Hash palsu untuk menyamakan waktu respons saat surel tak dikenal. */
    private const HASH_DUMMY = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly KeamananLogin $keamanan,
        private readonly Otp $otp,
        private readonly RiwayatSandi $riwayatSandi,
    ) {
    }

    /*
    |----------------------------------------------------------------------
    | Masuk: email + sandi (+ OTP bila 2FA aktif)
    |----------------------------------------------------------------------
    */

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim((string) $data['email']));
        $sandi = (string) $data['password'];
        $ip    = (string) ($request->ip() ?? '0.0.0.0');

        /* 1) Penguncian akibat percobaan berulang (sama seperti web). */
        $kunci = $this->keamanan->cekTerkunci($ip, $email);
        if ($kunci['terkunci']) {
            return response()->json([
                'sukses' => false,
                'pesan'  => $kunci['pesan'],
            ], 429);
        }

        /* 2) Credensial. */
        $user   = User::where('email', $email)->first();
        $sandiSah = $user !== null
            ? Hash::check($sandi, (string) $user->password)
            : Hash::check($sandi, self::HASH_DUMMY);

        if ($user === null || ! $sandiSah) {
            $this->keamanan->catatGagal($ip, $email, $user?->id);

            return response()->json([
                'sukses' => false,
                'pesan'  => 'Surel atau kata sandi tidak sesuai.',
            ], 401);
        }

        /* 3) Status akun. */
        $status = (string) ($user->status ?? 'aktif');

        if ($status === 'menunggu') {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Akun Anda masih menunggu persetujuan administrator.',
            ], 403);
        }
        if ($status !== 'aktif') {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Akun Anda sedang dinonaktifkan. Hubungi administrator.',
            ], 403);
        }

        /* 4) 2FA aktif → jangan keluarkan token dulu. */
        if ($user->duaFaktorAktif()) {
            return $this->mulaiDuaFaktor($request, $user, $ip, $email);
        }

        return $this->keluarkanToken($request, $user, $ip, $email);
    }

    /**
     * Tahap kedua alur 2FA: cocokkan OTP, lalu keluarkan token.
     */
    public function verifikasi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'max:100'],
            'kode'   => ['required', 'string', 'max:12'],
        ]);

        /*
         * Tiket ditarik sekali pakai (pull). Menggambar ulang tiket yang
         * sama kedua kalinya otomatis gagal -- mencegah pemakaian ulang.
         */
        $muat = cache()->pull('api:tfa:' . (string) $data['ticket']);

        if (! is_array($muat) || empty($muat['user_id'])) {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Tiket tidak valid atau kedaluwarsa. Masuk ulang.',
            ], 422);
        }

        $userId = (int) $muat['user_id'];
        $user   = User::find($userId);

        if ($user === null || (string) $user->status !== 'aktif') {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Akun tidak tersedia.',
            ], 403);
        }

        $hasil = $this->otp->verifikasi($userId, (string) $data['kode'], Otp::JENIS_LOGIN);

        if (! $hasil['ok']) {
            /* Tiket dipertahankan agar pengguna bisa mengulang kode. */
            cache()->put('api:tfa:' . (string) $data['ticket'], $muat, 600);

            return response()->json([
                'sukses' => false,
                'pesan'  => $hasil['pesan'],
            ], 422);
        }

        return $this->keluarkanToken($request, $user, (string) $request->ip(), (string) $user->email);
    }

    /*
    |----------------------------------------------------------------------
    | Setelah masuk: profil, keluar, ganti sandi
    |----------------------------------------------------------------------
    */

    public function profil(Request $request): JsonResponse
    {
        return response()->json([
            'sukses'   => true,
            'pengguna' => $this->ringkasPengguna($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /* Hapus token yang sedang dipakai permintaan ini saja. */
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['sukses' => true, 'pesan' => 'Berhasil keluar.']);
    }

    public function gantiPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'password_lama' => ['required', 'string'],
            'password_baru' => ['required', 'string', 'confirmed'],
        ]);

        if (! Hash::check((string) $data['password_lama'], (string) $user->password)) {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Kata sandi lama tidak sesuai.',
            ], 422);
        }

        if ($this->riwayatSandi->terpakaiSebelumnya((int) $user->id, (string) $data['password_baru']) !== null) {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Kata sandi baru pernah dipakai sebelumnya. Pilih kata sandi berbeda.',
            ], 422);
        }

        $user->password = (string) $data['password_baru'];
        $user->save();

        $this->riwayatSandi->simpan((int) $user->id, (string) $user->password);

        return response()->json(['sukses' => true, 'pesan' => 'Kata sandi berhasil diubah.']);
    }

    /* =================================================================
     * BAGIAN DALAM
     * =============================================================== */

    /**
     * Kirim OTP & kembalikan tiket sementara (belum login penuh).
     */
    private function mulaiDuaFaktor(Request $request, User $user, string $ip, string $email): JsonResponse
    {
        $ticket = bin2hex(random_bytes(24));
        cache()->put('api:tfa:' . $ticket, ['user_id' => $user->id], 600);

        $kode  = $this->otp->buat((int) $user->id, (string) $user->email, Otp::JENIS_LOGIN);
        $kirim = $this->otp->kirim((string) $user->email, $kode, (string) $user->nama);

        if (! $kirim['ok']) {
            cache()->forget('api:tfa:' . $ticket);

            return response()->json([
                'sukses' => false,
                'pesan'  => 'Gagal mengirim kode verifikasi. Coba lagi.',
            ], 502);
        }

        return response()->json([
            'sukses'      => true,
            'dua_faktor'  => true,
            'ticket'      => $ticket,
            'kedaluwarsa' => 600,
            'pesan'       => 'Kode verifikasi telah dikirim ke surel Anda.',
        ]);
    }

    /**
     * Catat keberhasilan & terbitkan token Sanctum.
     */
    private function keluarkanToken(Request $request, User $user, string $ip, string $email): JsonResponse
    {
        $this->keamanan->catatSukses($ip, $email, (int) $user->id);

        $token = $user->createToken('aplikasi-mobile')->plainTextToken;

        return response()->json([
            'sukses'   => true,
            'token'    => $token,
            'pengguna' => $this->ringkasPengguna($user),
        ]);
    }

    private function ringkasPengguna(User $user): array
    {
        return [
            'id'     => (int) $user->id,
            'nama'   => (string) $user->nama,
            'email'  => (string) $user->email,
            'role'   => (string) ($user->role ?? 'karyawan'),
            'status' => (string) ($user->status ?? 'aktif'),
        ];
    }
}
