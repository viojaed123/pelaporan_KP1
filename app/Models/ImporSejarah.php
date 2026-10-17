<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `impor_sejarah` (12 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class ImporSejarah extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'impor_sejarah';

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
        'user_id',
        'jenis',
        'nama_berkas',
        'jumlah_baris',
        'jumlah_masuk',
        'jumlah_dilewati',
        'jumlah_gagal',
        'ringkasan',
        'id_semua',
        'sumber',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'jumlah_baris' => 'int',
            'jumlah_masuk' => 'int',
            'jumlah_dilewati' => 'int',
            'jumlah_gagal' => 'int',
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
