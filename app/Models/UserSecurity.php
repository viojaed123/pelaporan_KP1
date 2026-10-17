<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `user_security` (10 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class UserSecurity extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'user_security';

    /** K tabel ini bukan `id` -- kunci utamanya `user_id` (bukan auto-increment). */
    protected $primaryKey = 'user_id';

    /** `user_id` bukan kolom auto-increment, sehingga Laravel tidak boleh mengasumsikannya. */
    public $incrementing = false;

    public $timestamps = true;

    /** Kolom pembuat di tabel ini bukan created_at. */
    public const CREATED_AT = 'dibuat_pada';
    public const UPDATED_AT = 'diperbarui_pada';

    /**
     * Kolom yang boleh diisi lewat create()/update().
     *
     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:
     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini
     * membuka celah mass-assignment.
     */
    protected $fillable = [
        'dua_faktor_aktif',
        'password_diubah_pada',
        'ingat_token_hash',
        'ingat_kedaluwarsa',
        'ingat_ip',
        'gagal_login_berturut',
        'terakhir_kunci',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'dua_faktor_aktif' => 'bool',
            'password_diubah_pada' => 'datetime',
            'ingat_kedaluwarsa' => 'datetime',
            'gagal_login_berturut' => 'int',
            'terakhir_kunci' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------
     * Relasi (kunci asing terdeteksi otomatis dari skema)
     * -------------------------------------------------------------- */

    /** user_id -> user.id */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
