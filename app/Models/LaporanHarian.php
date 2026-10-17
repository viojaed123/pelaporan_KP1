<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `laporan_harian` (18 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class LaporanHarian extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'laporan_harian';


    /**
     * Kolom yang boleh diisi lewat create()/update().
     *
     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:
     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini
     * membuka celah mass-assignment.
     */
    protected $fillable = [
        'user_id',
        'tanggal',
        'jam_mulai',
        'jam_selesai',
        'kegiatan',
        'lokasi_teks',
        'garis_lintang',
        'garis_bujur',
        'wilayah_id',
        'deskripsi',
        'hasil',
        'kendala',
        'rencana_berikutnya',
        'status',
        'catatan_mentor',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'tanggal' => 'date',
            'jam_mulai' => 'string',
            'jam_selesai' => 'string',
            'garis_lintang' => 'decimal:7',
            'garis_bujur' => 'decimal:7',
            'wilayah_id' => 'int',
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
