<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Aturan kebijakan kata sandi (porting includes/security.php §2).
 *
 * Dipakai pada pendaftaran, pengaturan ulang, dan penggantian kata sandi:
 *
 *   $request->validate([
 *       'password' => ['required', new KebijakanSandi($email, $nama)],
 *   ]);
 *
 * Pemeriksaan URUT dari yang paling umum ke paling khusus. Urutan itu
 * penting: pemeriksaan "kata sandi umum" sengaja didahulukan agar pesannya
 * lebih menolong ("terlalu umum dan mudah ditebak") daripada pesan teknis
 * yang tidak menjelaskan apa pun. Bila urutannya dibalik, kata sandi umum
 * yang pendek akan tertutup oleh pesan "minimal 8 karakter" dan pengguna
 * tidak pernah tahu masalah sebenarnya.
 *
 * Fungsi statis periksa() disediakan agar aturan yang sama dapat dipanggil
 * di luar konteks validasi formulir (mis. penggantian sandi yang dipicu
 * proses lain), menghindari dua sumber kebenaran yang bisa berbeda.
 */
class KebijakanSandi implements ValidationRule
{
    /**
     * Kata sandi umum yang tidak boleh dipakai.
     *
     * Daftar disalin PERSIS dari KATA_SANDI_UMUM sistem lama. Termasuk
     * variasi khusus aplikasi ini (nama sistem, organisasi) karena kata
     * sandi seperti itu justru yang paling sering ditebak penyerang yang
     * mengenal targetnya.
     *
     * @var list<string>
     */
    public const KATA_SANDI_UMUM = [
        'password', 'password1', 'password123', '123456', '12345678', '123456789',
        'qwerty', 'qwerty123', 'abc123', 'iloveyou', 'admin', 'admin123',
        'letmein', 'welcome', 'monkey', 'dragon', 'sunshine', 'princess',
        'football', 'baseball', 'master', 'shadow', 'changeme', 'secret',
        'pelaporan', 'laporan123', 'pertamina', 'pertamina123', 'phr123',
        'rahasia', 'sandiku', 'katasantiku', 'woipro123',
    ];

    /**
     * @param  string|null  $email  Surel pengguna, untuk menolak sandi yang
     *                              memuat bagian lokal surelnya.
     * @param  string|null  $nama   Nama pengguna, untuk menolak sandi yang
     *                              memuat namanya.
     */
    public function __construct(
        private readonly ?string $email = null,
        private readonly ?string $nama = null,
    ) {
    }

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pesan = self::periksa((string) $value, $this->email, $this->nama);

        if ($pesan !== null) {
            $fail($pesan);
        }
    }

    /**
     * Periksa kata sandi terhadap seluruh kebijakan.
     *
     * @return string|null  Pesan kesalahan, atau null bila lolos.
     */
    public static function periksa(string $sandi, ?string $email = null, ?string $nama = null): ?string
    {
        $min = (int) config('keamanan.password_min', 8);

        if (strlen($sandi) < $min) {
            return "Kata sandi minimal {$min} karakter.";
        }

        if (strlen($sandi) > 200) {
            return 'Kata sandi maksimal 200 karakter.';
        }

        /* 1) Daftar kata sandi umum -- diperiksa lebih dulu. */
        if (in_array(strtolower($sandi), self::KATA_SANDI_UMUM, true)) {
            return 'Kata sandi terlalu umum dan mudah ditebak.';
        }

        /* 2) Satu karakter yang diulang seluruhnya (mis. 'aaaaaaaa'). */
        if ($sandi !== '' && preg_match('/^(.)\1+$/', $sandi) === 1) {
            return 'Kata sandi tidak boleh terdiri dari satu karakter berulang.';
        }

        /* 3) Tolak kata sandi yang memuat identitas pengguna. */
        $surel = mb_strtolower(trim((string) $email));
        if ($surel !== '') {
            $lokal = explode('@', $surel)[0];
            if ($lokal !== '' && strlen($lokal) >= 4
                && str_contains(mb_strtolower($sandi), $lokal)) {
                return 'Kata sandi tidak boleh memuat alamat surel Anda.';
            }
        }

        $namaBersih = mb_strtolower(trim((string) $nama));
        if ($namaBersih !== '' && strlen($namaBersih) >= 5
            && str_contains(mb_strtolower($sandi), $namaBersih)) {
            return 'Kata sandi tidak boleh memuat nama Anda.';
        }

        return null;
    }
}
