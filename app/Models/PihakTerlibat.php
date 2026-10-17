<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `pihak_terlibat` (7 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class PihakTerlibat extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'pihak_terlibat';

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
        'nama_lengkap',
        'organisasi',
        'email',
        'telepon',
        'jenis_pihak',
    ];
}
