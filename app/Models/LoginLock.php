<?php

declare(strict_types=1);

/* PENANDA_TULIS_TANGAN
 *
 * Berkas ini tidak dihasilkan scripts/buat_model.php dan tidak akan ditimpa
 * bila generator dijalankan ulang. Alasannya:
 *
 *   1. Tabel `login_locks` tercantum dalam daftar TABEL_SISTEM generator
 *      (bukan karena memang sistem, melainkan agar tidak tercampur model
 *      domain). Padahal tabel ini DIPAKAI AKTIF oleh lapis keamanan login,
 *      sehingga tetap butuh model.
 *
 *   2. Kunci utamanya `kunci` (varchar), bukan `id`, dan bukan auto-increment.
 *      Tanpa $primaryKey/$incrementing yang benar, Eloquent akan menganggap
 *      kolom "id" sebagai kunci dan setiap operasi save()/update() gagal.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris penguncian login (rate limit + lockout).
 *
 * Setiap baris mewakili satu "kunci" yang diawasi, dalam dua bentuk:
 *   'ip:192.168.56.1'    -- melindungi dari brute force satu IP ke banyak akun
 *   'email:user@x.com'   -- melindungi dari brute force satu akun dari banyak IP
 *
 * Kedua bentuk dicatat bersamaan agar serangan "password spraying" (satu
 * sandi, banyak akun, satu IP) dan brute force klasik dua-duanya terkena batas.
 *
 * Nilai konstanta (LOGIN_MAKS_GAGAL, LOGIN_BACKOFF_DETIK) ada di .env dan
 * dibaca lewat config/keamanan.php, bukan di sini -- model hanya memegang data.
 */
class LoginLock extends Model
{
    /** Tabel eksplisit agar tidak bergantung pada pluralisasi bawaan. */
    protected $table = 'login_locks';

    /**
     * Kunci utama tabel ini adalah kolom `kunci`, bukan `id`.
     * varchar dan bukan auto-increment.
     */
    protected $primaryKey = 'kunci';

    /** `kunci` bukan auto-increment: Laravel tidak boleh mengasumsikannya. */
    public $incrementing = false;

    /** Tipe data kunci utama (varchar, bukan integer bawaan). */
    protected $keyType = 'string';

    /**
     * Tabel ini TIDAK memakai timestamp bawaan Laravel.
     *
     * Kolom waktunya `percobaan_pada` (default current_timestamp) dan
     * `diperbarui` (on update current_timestamp) -- keduanya sudah diisi
     * oleh database sendiri, sehingga mengizinkan Laravel menulis ulang
     * hanya akan membuang nilai bawaan itu.
     */
    public $timestamps = false;

    /** Kolom yang boleh diisi massal. `diperbarui` sengaja tidak dicantumkan
     *  karena dikelola database lewat ON UPDATE CURRENT_TIMESTAMP. */
    protected $fillable = [
        'kunci',
        'gagal',
        'terkunci_sampai',
        'percobaan_pertama',
    ];

    /**
     * Pemetaan tipe agar akses atribut tidak perlu konversi manual.
     *
     * `terkunci_sampai` di-cast datetime agar bisa dibandingkan langsung
     * dengan now() tanpa strtotime() di setiap titik pemakaian.
     */
    protected function casts(): array
    {
        return [
            'gagal'              => 'int',
            'terkunci_sampai'    => 'datetime',
            'percobaan_pertama'  => 'datetime',
        ];
    }

    /* =================================================================
     * PENOLONG
     * =============================================================== */

    /**
     * Bangun kunci pengawasan untuk sebuah alamat IP.
     *
     * Contoh: LoginLock::kunciIp('192.168.56.1') => 'ip:192.168.56.1'
     */
    public static function kunciIp(string $ip): string
    {
        return 'ip:' . $ip;
    }

    /**
     * Bangun kunci pengawasan untuk sebuah alamat surel.
     *
     * Surel selalu di-lowercase agar "Admin@X.com" dan "admin@x.com"
     * dihitung sebagai kunci yang sama -- tanpa ini, pelaku bisa
     * menghindari penguncian dengan mengubah huruf besar/kecil.
     */
    public static function kunciEmail(string $email): string
    {
        return 'email:' . strtolower(trim($email));
    }

    /**
     * Benar bila kunci ini sedang dalam masa penguncian.
     */
    public function terkunci(): bool
    {
        return $this->terkunci_sampai !== null
            && $this->terkunci_sampai->isFuture();
    }

    /**
     * Sisa detik penguncian; 0 bila tidak terkunci.
     */
    public function sisaDetik(): int
    {
        if (! $this->terkunci()) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->terkunci_sampai, false));
    }
}
