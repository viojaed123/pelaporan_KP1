<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `riwayat_tindak_lanjut` (8 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class RiwayatTindakLanjut extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'riwayat_tindak_lanjut';

    /** Tabel ini tidak punya kolom timestamp sama sekali. */
    public $timestamps = false;

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
        'tindakan',
        'hasil',
        'kendala',
        'rencana_selanjutnya',
        'tanggal_tindak_lanjut',
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
            'tanggal_tindak_lanjut' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------
     * Relasi (kunci asing terdeteksi otomatis dari skema)
     * -------------------------------------------------------------- */

    /** kasus_id -> kasus.id */
    public function kasus()
    {
        return $this->belongsTo(Kasus::class, 'kasus_id');
    }
}
