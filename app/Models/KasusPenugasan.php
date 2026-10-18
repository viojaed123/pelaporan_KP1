<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model untuk tabel `kasus_penugasan` (6 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class KasusPenugasan extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'kasus_penugasan';

    public $timestamps = true;

    /** Kolom pembuat di tabel ini bukan created_at. */
    public const CREATED_AT = 'dibuat_pada';
    /** Tabel ini hanya mencatat waktu pembuatan, tanpa pembaruan. */
    public const UPDATED_AT = null;

    /**
     * Kolom yang boleh diisi lewat create()/update().
     *
     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:
     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini
     * membuka celah mass-assignment.
     */
    protected $fillable = [
        'kasus_id',
        'user_id',
        'nama_teks',
        'peran',
        'ditugaskan_oleh',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'kasus_id' => 'int',
            'user_id' => 'int',
            'ditugaskan_oleh' => 'int',
        ];
    }

    /* =================================================================
     * RELASI (ditulis tangan -- bagian generator tidak menyentuhnya)
     * =============================================================== */

    /** Pengguna anggota tim (null bila hanya teks -- lihat `nama_teks`). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
