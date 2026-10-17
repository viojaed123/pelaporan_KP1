<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `wilayah` (11 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class Wilayah extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'wilayah';

    public $timestamps = true;

    /** Tabel ini hanya punya kolom pembaruan, tanpa kolom pembuat. */
    public const CREATED_AT = null;
    public const UPDATED_AT = 'diperbarui_pada';

    /**
     * Kolom yang boleh diisi lewat create()/update().
     *
     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:
     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini
     * membuka celah mass-assignment.
     */
    protected $fillable = [
        'level',
        'nama',
        'nama_norm',
        'kabupaten',
        'kecamatan',
        'garis_lintang',
        'garis_bujur',
        'sumber',
        'akurasi',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'garis_lintang' => 'decimal:7',
            'garis_bujur' => 'decimal:7',
        ];
    }
}
