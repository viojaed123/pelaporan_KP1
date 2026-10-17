<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `jadwal` (15 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class Jadwal extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'jadwal';

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
        'user_id',
        'judul',
        'deskripsi',
        'mulai',
        'selesai',
        'lokasi',
        'garis_lintang',
        'garis_bujur',
        'wilayah_id',
        'pengingat_menit',
        'status',
        'pengingat_terkirim',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'mulai' => 'datetime',
            'selesai' => 'datetime',
            'garis_lintang' => 'decimal:7',
            'garis_bujur' => 'decimal:7',
            'wilayah_id' => 'int',
            'pengingat_menit' => 'int',
            'pengingat_terkirim' => 'bool',
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
